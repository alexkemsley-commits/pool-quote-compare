<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQC_Claude {

	const ENDPOINT    = 'https://api.anthropic.com/v1/messages';
	const API_VERSION = '2023-06-01';

	/** @var callable|null Optional callback invoked with partial text as it streams in. */
	private static $progress_cb = null;

	public static function set_progress_callback( $cb ) {
		self::$progress_cb = is_callable( $cb ) ? $cb : null;
	}

	public static function compare( array $settings, array $document_blocks, $customer_context = '' ) {
		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error( 'pqc_no_key', __( 'Claude API key is not configured.', 'pool-quote-compare' ) );
		}
		if ( count( $document_blocks ) < 2 ) {
			return new WP_Error( 'pqc_files', __( 'At least two documents are required for comparison.', 'pool-quote-compare' ) );
		}

		$user_content = [];
		$user_content[] = [
			'type' => 'text',
			'text' => 'Please compare the following pool quotes. Each is attached as a document below.',
		];

		$i = 1;
		foreach ( $document_blocks as $block ) {
			$user_content[] = [
				'type' => 'text',
				'text' => 'Quote ' . $i . ': ' . ( isset( $block['title'] ) ? $block['title'] : '' ),
			];
			$user_content[] = $block;
			$i++;
		}

		if ( $customer_context !== '' ) {
			$user_content[] = [
				'type' => 'text',
				'text' => "Additional context from the customer:\n" . $customer_context,
			];
		}

		$user_content[] = [
			'type' => 'text',
			'text' => 'Follow the system instructions and produce the full comparison now.',
		];

		$body = [
			'model'         => $settings['model'],
			'max_tokens'    => (int) $settings['max_tokens'],
			'stream'        => true,
			'system'        => $settings['system_prompt'],
			'thinking'      => [
				'type'    => 'adaptive',
				// "summarized" returns visible thinking content so it can be safely
				// echoed back in tool-use continuation turns. The default "omitted"
				// causes a 400 on echo-back because each thinking block must
				// contain non-empty thinking text.
				'display' => 'summarized',
			],
			'output_config' => [ 'effort' => 'high' ],
			'messages'      => [
				[ 'role' => 'user', 'content' => $user_content ],
			],
		];

		$tools = [];
		if ( ! empty( $settings['enable_web_search'] ) ) {
			$tools[] = [ 'type' => 'web_search_20260209', 'name' => 'web_search' ];
		}
		if ( class_exists( 'PQC_CompaniesHouse' ) && PQC_CompaniesHouse::is_configured() ) {
			$tools[] = PQC_CompaniesHouse::tool_definition();
		}
		if ( ! empty( $tools ) ) {
			$body['tools'] = $tools;
		}

		return self::run_with_pause_turn_loop( $settings['api_key'], $body );
	}

	public static function handle_custom_tool( $name, $input ) {
		if ( $name === 'companies_house_lookup' && class_exists( 'PQC_CompaniesHouse' ) ) {
			return PQC_CompaniesHouse::execute( is_array( $input ) ? $input : [] );
		}
		return 'Error: unknown tool "' . $name . '".';
	}

	private static function run_with_pause_turn_loop( $api_key, array $body ) {
		$max_iterations = 20;
		$iter           = 0;
		$accumulated    = [
			'text'         => '',
			'searches'     => [],
			'tool_calls'   => [],
			'usage'        => [],
			'stop_reason'  => '',
			'model'        => '',
		];

		while ( $iter < $max_iterations ) {
			$iter++;
			$state = self::stream_once( $api_key, $body );
			if ( is_wp_error( $state ) ) {
				if ( $accumulated['text'] !== '' ) {
					$accumulated['error']       = $state->get_error_message();
					$accumulated['stop_reason'] = 'error';
					return $accumulated;
				}
				return $state;
			}

			if ( $state['text'] !== '' ) {
				$accumulated['text'] .= ( $accumulated['text'] !== '' ? "\n\n" : '' ) . $state['text'];
			}
			$accumulated['searches']    = array_merge( $accumulated['searches'], $state['searches'] );
			$accumulated['usage']       = self::merge_usage( $accumulated['usage'], $state['usage'] );
			$accumulated['stop_reason'] = $state['stop_reason'];
			$accumulated['model']       = $state['model'] ?: $accumulated['model'];

			// pause_turn: server-side tool iteration cap hit — resume.
			if ( $state['stop_reason'] === 'pause_turn' ) {
				$echo = self::prepare_echo_blocks( $state['assistant_blocks'] );
				if ( ! empty( $echo ) ) {
					$body['messages'][] = [ 'role' => 'assistant', 'content' => $echo ];
					continue;
				}
				return $accumulated;
			}

			// tool_use: a custom (client-side) tool was called — execute it and feed results back.
			if ( $state['stop_reason'] === 'tool_use' && ! empty( $state['assistant_blocks'] ) ) {
				$tool_uses = [];
				foreach ( $state['assistant_blocks'] as $blk ) {
					if ( isset( $blk['type'] ) && $blk['type'] === 'tool_use' ) {
						$tool_uses[] = $blk;
					}
				}
				if ( empty( $tool_uses ) ) {
					return $accumulated;
				}
				$body['messages'][] = [ 'role' => 'assistant', 'content' => self::prepare_echo_blocks( $state['assistant_blocks'] ) ];

				$tool_results = [];
				foreach ( $tool_uses as $tu ) {
					$name   = isset( $tu['name'] ) ? $tu['name'] : '';
					$input  = isset( $tu['input'] ) ? $tu['input'] : [];
					$output = self::handle_custom_tool( $name, $input );

					$accumulated['tool_calls'][] = [
						'name'  => $name,
						'input' => $input,
					];

					$tool_results[] = [
						'type'        => 'tool_result',
						'tool_use_id' => isset( $tu['id'] ) ? $tu['id'] : '',
						'content'     => is_string( $output ) ? $output : wp_json_encode( $output ),
					];
				}
				$body['messages'][] = [ 'role' => 'user', 'content' => $tool_results ];
				continue;
			}

			return $accumulated;
		}

		$accumulated['error'] = __( 'Tool-use loop exceeded retry limit.', 'pool-quote-compare' );
		return $accumulated;
	}

	private static function stream_once( $api_key, array $body ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'pqc_curl', __( 'PHP cURL extension is required.', 'pool-quote-compare' ) );
		}

		$state = [
			'text'             => '',
			'searches'         => [],
			'usage'            => [],
			'stop_reason'      => '',
			'model'            => '',
			'assistant_blocks' => [],
			'error_payload'    => null,
		];

		$buffer        = '';
		$raw_body      = '';
		$current_block = null;
		$progress      = self::$progress_cb;

		$ch = curl_init();
		curl_setopt_array( $ch, [
			CURLOPT_URL            => self::ENDPOINT,
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Accept: text/event-stream',
				'x-api-key: ' . $api_key,
				'anthropic-version: ' . self::API_VERSION,
			],
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => 0,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_LOW_SPEED_LIMIT => 1,
			CURLOPT_LOW_SPEED_TIME  => 120,
			CURLOPT_TCP_KEEPALIVE  => 1,
			CURLOPT_TCP_KEEPIDLE   => 30,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$buffer, &$raw_body, &$state, &$current_block, $progress ) {
				$raw_body .= $chunk;
				$buffer   .= $chunk;
				while ( ( $pos = strpos( $buffer, "\n\n" ) ) !== false ) {
					$event_block = substr( $buffer, 0, $pos );
					$buffer      = substr( $buffer, $pos + 2 );
					self::process_sse_block( $event_block, $state, $current_block, $progress );
				}
				return strlen( $chunk );
			},
		] );

		$ok       = curl_exec( $ch );
		$err_no   = curl_errno( $ch );
		$err_msg  = curl_error( $ch );
		$http     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( $current_block !== null ) {
			$state['assistant_blocks'][] = self::finalize_block( $current_block );
			$current_block = null;
		}

		if ( $http >= 400 || ( $ok === false && $err_no !== 0 && $state['text'] === '' ) ) {
			$msg = self::extract_error_message( $raw_body, $state, $err_msg, $http );
			self::log_api_error( $http, $raw_body, $body );
			return new WP_Error( 'pqc_api', sprintf( 'Claude API error (HTTP %d): %s', $http, $msg ) );
		}

		return $state;
	}

	/**
	 * Pull the actual error message from whatever we got back: SSE error event,
	 * plain JSON error body, or plain text.
	 */
	private static function extract_error_message( $raw_body, array $state, $curl_err, $http ) {
		if ( ! empty( $state['error_payload'] ) ) {
			return $state['error_payload'];
		}
		$trim = trim( (string) $raw_body );
		if ( $trim !== '' ) {
			$json = json_decode( $trim, true );
			if ( is_array( $json ) ) {
				if ( isset( $json['error']['message'] ) ) {
					$type = isset( $json['error']['type'] ) ? ' [' . $json['error']['type'] . ']' : '';
					return $type ? ( $json['error']['message'] . $type ) : $json['error']['message'];
				}
				if ( isset( $json['message'] ) ) {
					return $json['message'];
				}
			}
			if ( strlen( $trim ) < 1000 ) {
				return $trim;
			}
			return substr( $trim, 0, 1000 ) . '…';
		}
		if ( $curl_err ) {
			return $curl_err;
		}
		return 'HTTP ' . $http;
	}

	private static function log_api_error( $http, $raw_body, array $request_body ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		$summary = [
			'model'       => isset( $request_body['model'] ) ? $request_body['model'] : '',
			'max_tokens'  => isset( $request_body['max_tokens'] ) ? $request_body['max_tokens'] : 0,
			'tool_count'  => isset( $request_body['tools'] ) && is_array( $request_body['tools'] ) ? count( $request_body['tools'] ) : 0,
			'msg_count'   => isset( $request_body['messages'] ) && is_array( $request_body['messages'] ) ? count( $request_body['messages'] ) : 0,
		];
		error_log( '[PQC] Claude HTTP ' . $http . ' — request: ' . wp_json_encode( $summary ) . ' — body: ' . substr( (string) $raw_body, 0, 2000 ) );
	}

	private static function process_sse_block( $block, array &$state, &$current_block, $progress ) {
		$event = '';
		$data  = '';
		foreach ( explode( "\n", $block ) as $line ) {
			if ( strpos( $line, 'event:' ) === 0 ) {
				$event = trim( substr( $line, 6 ) );
			} elseif ( strpos( $line, 'data:' ) === 0 ) {
				$data .= ( $data !== '' ? "\n" : '' ) . substr( $line, 5 );
			}
		}
		$data = ltrim( $data );
		if ( $data === '' || $data === '[DONE]' ) {
			return;
		}
		$payload = json_decode( $data, true );
		if ( ! is_array( $payload ) ) {
			return;
		}

		$type = isset( $payload['type'] ) ? $payload['type'] : $event;

		switch ( $type ) {
			case 'message_start':
				if ( isset( $payload['message']['model'] ) ) {
					$state['model'] = $payload['message']['model'];
				}
				if ( isset( $payload['message']['usage'] ) && is_array( $payload['message']['usage'] ) ) {
					$state['usage'] = self::merge_usage( $state['usage'], $payload['message']['usage'] );
				}
				break;

			case 'content_block_start':
				$cb            = isset( $payload['content_block'] ) ? $payload['content_block'] : [];
				$current_block = [
					'data'      => $cb,
					'text'      => '',
					'json'      => '',
					'thinking'  => isset( $cb['thinking'] ) ? $cb['thinking'] : '',
					'signature' => isset( $cb['signature'] ) ? $cb['signature'] : '',
				];
				if ( ( $cb['type'] ?? '' ) === 'server_tool_use' && ( $cb['name'] ?? '' ) === 'web_search' ) {
					$q = isset( $cb['input']['query'] ) ? $cb['input']['query'] : '';
					if ( $q !== '' ) {
						$state['searches'][] = $q;
					}
				}
				break;

			case 'content_block_delta':
				$delta = isset( $payload['delta'] ) ? $payload['delta'] : [];
				if ( ! is_array( $current_block ) ) {
					break;
				}
				$dtype = $delta['type'] ?? '';
				if ( $dtype === 'text_delta' && isset( $delta['text'] ) ) {
					$current_block['text'] .= $delta['text'];
					$state['text']         .= $delta['text'];
					if ( $progress ) {
						call_user_func( $progress, $state['text'] );
					}
				} elseif ( $dtype === 'input_json_delta' && isset( $delta['partial_json'] ) ) {
					$current_block['json'] .= $delta['partial_json'];
				} elseif ( $dtype === 'thinking_delta' && isset( $delta['thinking'] ) ) {
					$current_block['thinking'] .= $delta['thinking'];
				} elseif ( $dtype === 'signature_delta' && isset( $delta['signature'] ) ) {
					$current_block['signature'] = $delta['signature'];
				}
				break;

			case 'content_block_stop':
				if ( is_array( $current_block ) ) {
					$state['assistant_blocks'][] = self::finalize_block( $current_block );
					$current_block               = null;
				}
				break;

			case 'message_delta':
				if ( isset( $payload['delta']['stop_reason'] ) ) {
					$state['stop_reason'] = $payload['delta']['stop_reason'];
				}
				if ( isset( $payload['usage'] ) && is_array( $payload['usage'] ) ) {
					$state['usage'] = self::merge_usage( $state['usage'], $payload['usage'] );
				}
				break;

			case 'message_stop':
				break;

			case 'error':
				$state['error_payload'] = isset( $payload['error']['message'] ) ? $payload['error']['message'] : 'API error';
				break;
		}
	}

	private static function finalize_block( array $cb ) {
		$data = isset( $cb['data'] ) ? $cb['data'] : [];
		$type = isset( $data['type'] ) ? $data['type'] : '';
		if ( $type === 'text' ) {
			$data['text'] = $cb['text'];
		} elseif ( $type === 'tool_use' || $type === 'server_tool_use' ) {
			if ( ! empty( $cb['json'] ) ) {
				$decoded = json_decode( $cb['json'], true );
				if ( is_array( $decoded ) ) {
					$data['input'] = $decoded;
				}
			}
		} elseif ( $type === 'thinking' ) {
			$data['thinking']  = isset( $cb['thinking'] ) ? $cb['thinking'] : '';
			if ( ! empty( $cb['signature'] ) ) {
				$data['signature'] = $cb['signature'];
			}
		}
		return $data;
	}

	/**
	 * Prepare assistant content for echo-back during tool-use / pause_turn
	 * continuation.
	 *
	 * Thinking blocks are stripped entirely: Opus 4.7 defaults `display` to
	 * "omitted" so thinking content streams in empty, and the API then rejects
	 * the echo with "each thinking block must contain thinking". Dropping them
	 * is safe — the model regenerates any reasoning it needs on the next turn
	 * and the tool_use/text blocks carry the conversational state.
	 */
	private static function prepare_echo_blocks( array $blocks ) {
		$out = [];
		foreach ( $blocks as $blk ) {
			$type = isset( $blk['type'] ) ? $blk['type'] : '';
			if ( $type === 'thinking' || $type === 'redacted_thinking' ) {
				continue;
			}
			if ( $type === 'text' ) {
				$text = isset( $blk['text'] ) ? trim( (string) $blk['text'] ) : '';
				if ( $text === '' ) {
					continue;
				}
			}
			$out[] = $blk;
		}
		return $out;
	}

	private static function merge_usage( array $a, array $b ) {
		foreach ( $b as $k => $v ) {
			if ( is_numeric( $v ) ) {
				$a[ $k ] = ( isset( $a[ $k ] ) && is_numeric( $a[ $k ] ) ) ? ( $a[ $k ] + $v ) : $v;
			} else {
				$a[ $k ] = $v;
			}
		}
		return $a;
	}
}
