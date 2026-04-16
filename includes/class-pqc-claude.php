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
			'thinking'      => [ 'type' => 'adaptive' ],
			'output_config' => [ 'effort' => 'high' ],
			'messages'      => [
				[ 'role' => 'user', 'content' => $user_content ],
			],
		];

		if ( ! empty( $settings['enable_web_search'] ) ) {
			$body['tools'] = [
				[ 'type' => 'web_search_20260209', 'name' => 'web_search' ],
			];
		}

		return self::run_with_pause_turn_loop( $settings['api_key'], $body );
	}

	private static function run_with_pause_turn_loop( $api_key, array $body ) {
		$max_iterations = 6;
		$iter           = 0;
		$accumulated    = [
			'text'        => '',
			'searches'    => [],
			'usage'       => [],
			'stop_reason' => '',
			'model'       => '',
		];

		while ( $iter < $max_iterations ) {
			$iter++;
			$state = self::stream_once( $api_key, $body );
			if ( is_wp_error( $state ) ) {
				if ( $accumulated['text'] !== '' ) {
					$accumulated['error']        = $state->get_error_message();
					$accumulated['stop_reason']  = 'error';
					return $accumulated;
				}
				return $state;
			}

			$accumulated['text']        .= ( $accumulated['text'] !== '' ? "\n\n" : '' ) . $state['text'];
			$accumulated['searches']     = array_merge( $accumulated['searches'], $state['searches'] );
			$accumulated['usage']        = self::merge_usage( $accumulated['usage'], $state['usage'] );
			$accumulated['stop_reason']  = $state['stop_reason'];
			$accumulated['model']        = $state['model'] ?: $accumulated['model'];

			if ( $state['stop_reason'] !== 'pause_turn' ) {
				return $accumulated;
			}

			if ( ! empty( $state['assistant_blocks'] ) ) {
				$body['messages'][] = [ 'role' => 'assistant', 'content' => $state['assistant_blocks'] ];
			} else {
				return $accumulated;
			}
		}

		$accumulated['error'] = __( 'Server-tool loop exceeded retry limit.', 'pool-quote-compare' );
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
			CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$buffer, &$state, &$current_block, $progress ) {
				$buffer .= $chunk;
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
			$msg = $state['error_payload'] ?: ( $err_msg ? $err_msg : ( 'HTTP ' . $http ) );
			return new WP_Error( 'pqc_api', sprintf( 'Claude API error (%d): %s', $http, $msg ) );
		}

		return $state;
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
				$current_block = [ 'data' => $cb, 'text' => '', 'json' => '' ];
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
				if ( ( $delta['type'] ?? '' ) === 'text_delta' && isset( $delta['text'] ) ) {
					$current_block['text'] .= $delta['text'];
					$state['text']         .= $delta['text'];
					if ( $progress ) {
						call_user_func( $progress, $state['text'] );
					}
				} elseif ( ( $delta['type'] ?? '' ) === 'input_json_delta' && isset( $delta['partial_json'] ) ) {
					$current_block['json'] .= $delta['partial_json'];
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
			if ( $cb['json'] !== '' ) {
				$decoded = json_decode( $cb['json'], true );
				if ( is_array( $decoded ) ) {
					$data['input'] = $decoded;
				}
			}
		}
		return $data;
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
