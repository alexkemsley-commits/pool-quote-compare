<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQC_Ajax {

	const TOKEN_TTL = 3600;

	public static function init() {
		add_action( 'wp_ajax_pqc_submit', [ __CLASS__, 'handle_submit' ] );
		add_action( 'wp_ajax_nopriv_pqc_submit', [ __CLASS__, 'handle_submit' ] );

		add_action( 'wp_ajax_pqc_status', [ __CLASS__, 'handle_status' ] );
		add_action( 'wp_ajax_nopriv_pqc_status', [ __CLASS__, 'handle_status' ] );

		add_action( 'wp_ajax_pqc_download', [ __CLASS__, 'handle_download' ] );
	}

	private static function log( $msg ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[PQC] ' . $msg );
		}
	}

	public static function handle_submit() {
		check_ajax_referer( 'pqc_submit', 'nonce' );

		$settings = pqc_get_settings();

		if ( empty( $settings['api_key'] ) ) {
			wp_send_json_error( [ 'message' => __( 'This tool is not yet configured. Please contact the site administrator.', 'pool-quote-compare' ) ], 500 );
		}

		$name    = isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '';
		$email   = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
		$notes   = isset( $_POST['customer_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['customer_notes'] ) ) : '';
		$consent = ! empty( $_POST['consent'] );

		$priority_labels = [
			'cheap'     => 'Cheap — lowest defensible upfront price',
			'quick'     => 'Quick — fastest realistic install timeline',
			'good'      => 'Good — premium specification, finish and equipment quality',
			'cheap_run' => 'Cheap to run long term — lowest 10-year running cost (efficient pump, heating, cover)',
			'low_risk'  => 'Low risk — accountable warranty, watertight scope, no surprise extras',
		];
		$selected_priorities = [];
		if ( ! empty( $_POST['priorities'] ) && is_array( $_POST['priorities'] ) ) {
			foreach ( $_POST['priorities'] as $key ) {
				$key = sanitize_key( $key );
				if ( isset( $priority_labels[ $key ] ) && ! in_array( $key, array_keys( $selected_priorities ), true ) ) {
					$selected_priorities[ $key ] = $priority_labels[ $key ];
				}
				if ( count( $selected_priorities ) >= 2 ) {
					break;
				}
			}
		}
		if ( ! empty( $selected_priorities ) ) {
			$priority_text  = "The customer has named these as their TOP TWO priorities (use these to weight the comparison and any tradeoffs):\n- ";
			$priority_text .= implode( "\n- ", array_values( $selected_priorities ) );
			$notes          = $notes !== '' ? $priority_text . "\n\nAdditional context from the customer:\n" . $notes : $priority_text;
		}

		if ( ! $consent ) {
			wp_send_json_error( [ 'message' => __( 'Please tick the consent box to continue.', 'pool-quote-compare' ) ], 400 );
		}
		if ( ! $email || ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'pool-quote-compare' ) ], 400 );
		}

		$files = self::normalize_files( isset( $_FILES['quotes'] ) ? $_FILES['quotes'] : [] );
		if ( count( $files ) < 2 ) {
			wp_send_json_error( [ 'message' => __( 'Please upload at least two quotes to compare.', 'pool-quote-compare' ) ], 400 );
		}
		if ( count( $files ) > (int) $settings['max_files'] ) {
			wp_send_json_error( [ 'message' => sprintf( __( 'Please upload no more than %d files.', 'pool-quote-compare' ), (int) $settings['max_files'] ) ], 400 );
		}

		$max_bytes = (int) $settings['max_file_mb'] * 1024 * 1024;
		foreach ( $files as $f ) {
			$valid = PQC_Parser::validate_upload( $f, $max_bytes );
			if ( is_wp_error( $valid ) ) {
				wp_send_json_error( [ 'message' => $valid->get_error_message() ], 400 );
			}
		}

		$submission_id = PQC_Storage::insert( [
			'created_at'     => current_time( 'mysql' ),
			'customer_name'  => $name,
			'customer_email' => $email,
			'customer_notes' => $notes,
			'system_prompt'  => $settings['system_prompt'],
			'model'          => $settings['model'],
			'file_count'     => count( $files ),
			'files_json'     => wp_json_encode( [] ),
			'status'         => 'queued',
			'ip_address'     => self::client_ip(),
		] );
		if ( ! $submission_id ) {
			wp_send_json_error( [ 'message' => __( 'Could not create submission.', 'pool-quote-compare' ) ], 500 );
		}

		$dir   = PQC_Storage::submission_dir( $submission_id );
		$saved = [];

		foreach ( $files as $index => $f ) {
			$safe_name = sanitize_file_name( $f['name'] );
			$ext       = strtolower( pathinfo( $safe_name, PATHINFO_EXTENSION ) );
			$basename  = pathinfo( $safe_name, PATHINFO_FILENAME );
			$dest      = $dir . '/' . sprintf( '%02d_', $index + 1 ) . wp_generate_password( 8, false, false ) . '_' . $basename . '.' . $ext;

			if ( ! @move_uploaded_file( $f['tmp_name'], $dest ) ) {
				self::mark_failed( $submission_id, __( 'Could not save uploaded file.', 'pool-quote-compare' ) );
				wp_send_json_error( [ 'message' => __( 'Could not save uploaded file.', 'pool-quote-compare' ) ], 500 );
			}
			@chmod( $dest, 0640 );

			$saved[] = [
				'original' => $f['name'],
				'path'     => $dest,
				'size'     => filesize( $dest ),
			];
		}

		PQC_Storage::update( $submission_id, [ 'files_json' => wp_json_encode( $saved ) ] );

		$token = wp_generate_password( 32, false, false );
		set_transient( 'pqc_token_' . $submission_id, $token, self::TOKEN_TTL );

		self::respond_then_process( [
			'id'    => $submission_id,
			'token' => $token,
		], $submission_id, $saved, $notes, $settings );
	}

	/**
	 * Send the JSON success response to the browser, close the connection,
	 * then continue processing in the same PHP process. No loopback / spawn.
	 */
	private static function respond_then_process( array $response, $submission_id, array $saved, $notes, array $settings ) {
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Connection: close' );

		$payload = wp_json_encode( [ 'success' => true, 'data' => $response ] );
		header( 'Content-Length: ' . strlen( $payload ) );
		echo $payload;

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} else {
			@ob_end_flush();
			@flush();
		}

		ignore_user_abort( true );
		@set_time_limit( 0 );
		if ( function_exists( 'session_write_close' ) ) {
			@session_write_close();
		}

		try {
			self::run_comparison( $submission_id, $saved, $notes, $settings );
		} catch ( Throwable $e ) {
			self::log( 'Exception in run_comparison: ' . $e->getMessage() );
			self::mark_failed( $submission_id, 'Internal error: ' . $e->getMessage() );
		}

		exit;
	}

	private static function run_comparison( $submission_id, array $saved, $notes, array $settings ) {
		PQC_Storage::update( $submission_id, [ 'status' => 'processing' ] );

		$doc_blocks = [];
		foreach ( $saved as $f ) {
			if ( empty( $f['path'] ) || ! file_exists( $f['path'] ) ) {
				self::mark_failed( $submission_id, __( 'Saved file is missing on disk.', 'pool-quote-compare' ) );
				return;
			}
			$block = PQC_Parser::build_document_block( $f['path'], $f['original'] );
			if ( is_wp_error( $block ) ) {
				self::mark_failed( $submission_id, $block->get_error_message() );
				return;
			}
			$doc_blocks[] = $block;
		}

		$last_save = 0;
		PQC_Claude::set_progress_callback( function ( $partial ) use ( $submission_id, &$last_save ) {
			$now = time();
			if ( $now - $last_save >= 3 ) {
				$last_save = $now;
				PQC_Storage::update( $submission_id, [
					'status'   => 'streaming',
					'response' => $partial,
				] );
			}
		} );

		$result = PQC_Claude::compare( $settings, $doc_blocks, $notes );
		PQC_Claude::set_progress_callback( null );

		if ( is_wp_error( $result ) ) {
			self::mark_failed( $submission_id, $result->get_error_message() );
			return;
		}

		$status = ! empty( $result['error'] ) ? 'partial' : 'completed';
		PQC_Storage::update( $submission_id, [
			'status'        => $status,
			'response'      => isset( $result['text'] ) ? $result['text'] : '',
			'usage_json'    => wp_json_encode( isset( $result['usage'] ) ? $result['usage'] : [] ),
			'error_message' => isset( $result['error'] ) ? $result['error'] : null,
		] );

		self::send_customer_email( $submission_id );
		delete_transient( 'pqc_token_' . $submission_id );
	}

	public static function handle_status() {
		$submission_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$token         = isset( $_GET['token'] ) ? (string) $_GET['token'] : '';

		if ( ! $submission_id || ! $token ) {
			wp_send_json_error( [ 'message' => 'Bad request.' ], 400 );
		}
		$expected = get_transient( 'pqc_token_' . $submission_id );
		$row      = PQC_Storage::get( $submission_id );
		if ( ! $row ) {
			wp_send_json_error( [ 'message' => 'Not found.' ], 404 );
		}
		$is_terminal = in_array( $row->status, [ 'completed', 'failed', 'partial' ], true );
		if ( ! $is_terminal && ( ! $expected || ! hash_equals( $expected, $token ) ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden.' ], 403 );
		}

		$raw  = (string) $row->response;
		$html = $raw !== '' ? PQC_Markdown::render( $raw ) : '';

		wp_send_json_success( [
			'id'            => (int) $row->id,
			'status'        => $row->status,
			'response'      => $raw,
			'response_html' => $html,
			'length'        => strlen( $raw ),
			'error'         => $row->error_message,
			'emailed'       => ! empty( $row->emailed_at ),
		] );
	}

	public static function handle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', '', [ 'response' => 403 ] );
		}
		$id    = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$index = isset( $_GET['i'] ) ? (int) $_GET['i'] : -1;
		$nonce = isset( $_GET['_wpnonce'] ) ? $_GET['_wpnonce'] : '';
		if ( ! wp_verify_nonce( $nonce, 'pqc_download_' . $id ) ) {
			wp_die( 'Bad nonce', '', [ 'response' => 403 ] );
		}
		$row = PQC_Storage::get( $id );
		if ( ! $row ) {
			wp_die( 'Not found', '', [ 'response' => 404 ] );
		}
		$files = json_decode( $row->files_json, true );
		if ( ! is_array( $files ) || ! isset( $files[ $index ] ) ) {
			wp_die( 'File not found', '', [ 'response' => 404 ] );
		}
		$file = $files[ $index ];
		$path = isset( $file['path'] ) ? $file['path'] : '';

		$base = realpath( PQC_Storage::upload_dir() );
		$real = realpath( $path );
		if ( ! $base || ! $real || strpos( $real, $base ) !== 0 || ! is_file( $real ) ) {
			wp_die( 'File unavailable', '', [ 'response' => 404 ] );
		}

		$original = isset( $file['original'] ) ? $file['original'] : basename( $real );
		$ext      = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
		$mime     = 'application/octet-stream';
		if ( $ext === 'pdf' ) {
			$mime = 'application/pdf';
		} elseif ( $ext === 'docx' ) {
			$mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		} elseif ( $ext === 'doc' ) {
			$mime = 'application/msword';
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $original ) . '"' );
		header( 'Content-Length: ' . filesize( $real ) );
		header( 'X-Content-Type-Options: nosniff' );
		@ob_end_clean();
		readfile( $real );
		exit;
	}

	public static function send_customer_email( $submission_id ) {
		$row = PQC_Storage::get( $submission_id );
		if ( ! $row || empty( $row->customer_email ) || empty( $row->response ) ) {
			return false;
		}

		$settings = pqc_get_settings();

		$from_name = ! empty( $settings['email_from_name'] ) ? $settings['email_from_name'] : get_bloginfo( 'name' );
		$from_addr = ! empty( $settings['email_from_address'] ) ? $settings['email_from_address'] : get_option( 'admin_email' );
		$subject   = ! empty( $settings['email_subject'] ) ? $settings['email_subject'] : __( 'Your pool quote comparison', 'pool-quote-compare' );

		$intro         = wpautop( wp_kses_post( $settings['email_intro'] ) );
		$analysis      = PQC_Markdown::render( $row->response );
		$disclaimer_tx = ! empty( $settings['disclaimer'] ) ? $settings['disclaimer'] : '';
		$disclaimer    = $disclaimer_tx
			? '<div style="margin-top:24px;padding:14px 16px;border:1px solid #f0c36d;background:#fff8e1;border-radius:6px;font-size:13px;line-height:1.5;color:#5a3e00;"><strong>' . esc_html__( 'Please read before acting on this analysis', 'pool-quote-compare' ) . '</strong><br/>' . wp_kses_post( wpautop( $disclaimer_tx ) ) . '</div>'
			: '';

		$body  = PQC_Markdown::email_style_wrap( '' );
		$body .= '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#222;max-width:800px;">';
		$body .= $intro . $analysis . $disclaimer;
		$body .= '</div>';

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_addr . '>',
		];
		if ( ! empty( $settings['bcc_admin'] ) ) {
			$admin_email = get_option( 'admin_email' );
			if ( $admin_email && is_email( $admin_email ) ) {
				$headers[] = 'Bcc: ' . $admin_email;
			}
		}

		$sent = wp_mail( $row->customer_email, $subject, $body, $headers );
		if ( $sent ) {
			PQC_Storage::update( $submission_id, [ 'emailed_at' => current_time( 'mysql' ) ] );
		}
		return $sent;
	}

	private static function normalize_files( $input ) {
		$out = [];
		if ( empty( $input ) || empty( $input['name'] ) ) {
			return $out;
		}
		$count = is_array( $input['name'] ) ? count( $input['name'] ) : 1;
		for ( $i = 0; $i < $count; $i++ ) {
			if ( is_array( $input['name'] ) ) {
				if ( empty( $input['name'][ $i ] ) ) {
					continue;
				}
				$out[] = [
					'name'     => $input['name'][ $i ],
					'type'     => $input['type'][ $i ],
					'tmp_name' => $input['tmp_name'][ $i ],
					'error'    => $input['error'][ $i ],
					'size'     => $input['size'][ $i ],
				];
			} else {
				$out[] = $input;
			}
		}
		return $out;
	}

	private static function mark_failed( $id, $message ) {
		PQC_Storage::update( $id, [
			'status'        => 'failed',
			'error_message' => $message,
		] );
	}

	private static function client_ip() {
		$ip = ! empty( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
		return preg_replace( '/[^0-9a-fA-F:.]/', '', (string) $ip );
	}
}
