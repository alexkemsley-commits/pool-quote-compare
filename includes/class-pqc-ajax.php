<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQC_Ajax {

	const TOKEN_TTL = 1800;

	public static function init() {
		add_action( 'wp_ajax_pqc_submit', [ __CLASS__, 'handle_submit' ] );
		add_action( 'wp_ajax_nopriv_pqc_submit', [ __CLASS__, 'handle_submit' ] );

		add_action( 'wp_ajax_pqc_status', [ __CLASS__, 'handle_status' ] );
		add_action( 'wp_ajax_nopriv_pqc_status', [ __CLASS__, 'handle_status' ] );

		add_action( 'wp_ajax_pqc_process', [ __CLASS__, 'handle_process' ] );
		add_action( 'wp_ajax_nopriv_pqc_process', [ __CLASS__, 'handle_process' ] );
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
				if ( isset( $priority_labels[ $key ] ) && ! in_array( $key, $selected_priorities, true ) ) {
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

		self::spawn_processor( $submission_id, $token );

		wp_send_json_success( [
			'id'    => $submission_id,
			'token' => $token,
		] );
	}

	private static function spawn_processor( $submission_id, $token ) {
		$url = admin_url( 'admin-ajax.php' );
		wp_remote_post( $url, [
			'timeout'   => 0.1,
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'cookies'   => [],
			'body'      => [
				'action' => 'pqc_process',
				'id'     => (int) $submission_id,
				'token'  => $token,
			],
		] );
	}

	public static function handle_process() {
		ignore_user_abort( true );
		@set_time_limit( 0 );
		if ( function_exists( 'session_write_close' ) ) {
			@session_write_close();
		}

		$submission_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$token         = isset( $_POST['token'] ) ? (string) $_POST['token'] : '';

		if ( ! $submission_id || ! $token ) {
			wp_die( '', '', [ 'response' => 400 ] );
		}
		$expected = get_transient( 'pqc_token_' . $submission_id );
		if ( ! $expected || ! hash_equals( $expected, $token ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}

		$row = PQC_Storage::get( $submission_id );
		if ( ! $row ) {
			wp_die( '', '', [ 'response' => 404 ] );
		}
		if ( in_array( $row->status, [ 'completed', 'partial', 'failed' ], true ) ) {
			wp_die( '', '', [ 'response' => 200 ] );
		}

		PQC_Storage::update( $submission_id, [ 'status' => 'processing' ] );

		$settings = pqc_get_settings();
		$files    = json_decode( $row->files_json, true );
		if ( ! is_array( $files ) || count( $files ) < 2 ) {
			self::mark_failed( $submission_id, __( 'Saved files missing.', 'pool-quote-compare' ) );
			wp_die( '', '', [ 'response' => 500 ] );
		}

		$doc_blocks = [];
		foreach ( $files as $f ) {
			if ( empty( $f['path'] ) || ! file_exists( $f['path'] ) ) {
				self::mark_failed( $submission_id, __( 'Saved file is missing on disk.', 'pool-quote-compare' ) );
				wp_die( '', '', [ 'response' => 500 ] );
			}
			$block = PQC_Parser::build_document_block( $f['path'], $f['original'] );
			if ( is_wp_error( $block ) ) {
				self::mark_failed( $submission_id, $block->get_error_message() );
				wp_die( '', '', [ 'response' => 500 ] );
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

		$result = PQC_Claude::compare( $settings, $doc_blocks, $row->customer_notes );
		PQC_Claude::set_progress_callback( null );

		if ( is_wp_error( $result ) ) {
			self::mark_failed( $submission_id, $result->get_error_message() );
			wp_die( '', '', [ 'response' => 200 ] );
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

		wp_die( '', '', [ 'response' => 200 ] );
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
		// For terminal states the token may be cleared. Verify token matches OR submission is terminal.
		$is_terminal = in_array( $row->status, [ 'completed', 'failed', 'partial' ], true );
		if ( ! $is_terminal && ( ! $expected || ! hash_equals( $expected, $token ) ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden.' ], 403 );
		}

		wp_send_json_success( [
			'id'       => (int) $row->id,
			'status'   => $row->status,
			'response' => (string) $row->response,
			'length'   => strlen( (string) $row->response ),
			'error'    => $row->error_message,
			'emailed'  => ! empty( $row->emailed_at ),
		] );
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

		$intro      = wpautop( wp_kses_post( $settings['email_intro'] ) );
		$analysis   = wpautop( esc_html( $row->response ) );
		$disclaimer = '<hr/><p style="font-size:12px;color:#555;">' . esc_html__( 'This comparison was generated by an AI assistant. It is decision support, not a decision. Please read the contracts in full before committing any deposit.', 'pool-quote-compare' ) . '</p>';

		$body  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#222;">';
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
