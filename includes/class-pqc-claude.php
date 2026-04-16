<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQC_Claude {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	const API_VERSION = '2023-06-01';

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
			'text' => "Please compare the following pool quotes. Each is attached as a document below.",
		];

		$i = 1;
		foreach ( $document_blocks as $block ) {
			$user_content[] = [
				'type' => 'text',
				'text' => "Quote " . $i . ": " . ( isset( $block['title'] ) ? $block['title'] : '' ),
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
			'text' => "Follow the system instructions and produce the full comparison now.",
		];

		$body = [
			'model'      => $settings['model'],
			'max_tokens' => (int) $settings['max_tokens'],
			'system'     => $settings['system_prompt'],
			'thinking'   => [ 'type' => 'adaptive' ],
			'output_config' => [ 'effort' => 'high' ],
			'messages'   => [
				[ 'role' => 'user', 'content' => $user_content ],
			],
		];

		if ( ! empty( $settings['enable_web_search'] ) ) {
			$body['tools'] = [
				[ 'type' => 'web_search_20260209', 'name' => 'web_search' ],
			];
		}

		return self::request( $settings['api_key'], $body );
	}

	private static function request( $api_key, array $body, $messages_history = null ) {
		$attempt = 0;
		$messages = $messages_history !== null ? $messages_history : $body['messages'];
		$body['messages'] = $messages;

		while ( $attempt < 6 ) {
			$attempt++;

			$response = wp_remote_post( self::ENDPOINT, [
				'timeout' => 300,
				'headers' => [
					'Content-Type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => self::API_VERSION,
				],
				'body'    => wp_json_encode( $body ),
			] );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );
			$data = json_decode( $raw, true );

			if ( $code < 200 || $code >= 300 ) {
				$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : ( $raw !== '' ? $raw : 'HTTP ' . $code );
				return new WP_Error( 'pqc_api', sprintf( 'Claude API error (%d): %s', $code, $msg ) );
			}

			if ( ! is_array( $data ) ) {
				return new WP_Error( 'pqc_api', 'Malformed API response.' );
			}

			$stop_reason = isset( $data['stop_reason'] ) ? $data['stop_reason'] : '';

			if ( $stop_reason === 'pause_turn' ) {
				$body['messages'][] = [ 'role' => 'assistant', 'content' => $data['content'] ];
				continue;
			}

			return self::format_result( $data );
		}

		return new WP_Error( 'pqc_api', 'Claude server-tool loop exceeded retry limit.' );
	}

	private static function format_result( array $data ) {
		$text_parts = [];
		$searches   = [];

		if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( ! is_array( $block ) || empty( $block['type'] ) ) {
					continue;
				}
				if ( $block['type'] === 'text' && isset( $block['text'] ) ) {
					$text_parts[] = $block['text'];
				}
				if ( $block['type'] === 'server_tool_use' && isset( $block['name'] ) && $block['name'] === 'web_search' ) {
					$q = isset( $block['input']['query'] ) ? $block['input']['query'] : '';
					if ( $q !== '' ) {
						$searches[] = $q;
					}
				}
			}
		}

		return [
			'text'      => trim( implode( "\n\n", $text_parts ) ),
			'searches'  => $searches,
			'usage'     => isset( $data['usage'] ) ? $data['usage'] : [],
			'raw_stop'  => isset( $data['stop_reason'] ) ? $data['stop_reason'] : '',
			'model'     => isset( $data['model'] ) ? $data['model'] : '',
		];
	}
}
