<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQC_Ajax {

	public static function init() {
		add_action( 'wp_ajax_pqc_submit', [ __CLASS__, 'handle_submit' ] );
		add_action( 'wp_ajax_nopriv_pqc_submit', [ __CLASS__, 'handle_submit' ] );
	}

	public static function handle_submit() {
		@set_time_limit( 600 );
		check_ajax_referer( 'pqc_submit', 'nonce' );

		$settings = pqc_get_settings();

		if ( empty( $settings['api_key'] ) ) {
			wp_send_json_error( [ 'message' => __( 'This tool is not yet configured. Please contact the site administrator.', 'pool-quote-compare' ) ], 500 );
		}

		$name    = isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '';
		$email   = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
		$notes   = isset( $_POST['customer_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['customer_notes'] ) ) : '';
		$consent = ! empty( $_POST['consent'] );

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
			'status'         => 'processing',
			'ip_address'     => self::client_ip(),
		] );
		if ( ! $submission_id ) {
			wp_send_json_error( [ 'message' => __( 'Could not create submission.', 'pool-quote-compare' ) ], 500 );
		}

		$dir = PQC_Storage::submission_dir( $submission_id );
		$saved = [];
		$doc_blocks = [];

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

			$block = PQC_Parser::build_document_block( $dest, $f['name'] );
			if ( is_wp_error( $block ) ) {
				self::mark_failed( $submission_id, $block->get_error_message() );
				wp_send_json_error( [ 'message' => $block->get_error_message() ], 400 );
			}
			$doc_blocks[] = $block;
		}

		PQC_Storage::update( $submission_id, [ 'files_json' => wp_json_encode( $saved ) ] );

		$last_save = 0;
		PQC_Claude::set_progress_callback( function ( $partial ) use ( $submission_id, &$last_save ) {
			$now = time();
			if ( $now - $last_save >= 5 ) {
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
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 502 );
		}

		$status = ! empty( $result['error'] ) ? 'partial' : 'completed';
		PQC_Storage::update( $submission_id, [
			'status'        => $status,
			'response'      => isset( $result['text'] ) ? $result['text'] : '',
			'usage_json'    => wp_json_encode( isset( $result['usage'] ) ? $result['usage'] : [] ),
			'error_message' => isset( $result['error'] ) ? $result['error'] : null,
		] );

		$sent = self::send_customer_email( $submission_id );

		wp_send_json_success( [
			'id'       => $submission_id,
			'response' => isset( $result['text'] ) ? $result['text'] : '',
			'emailed'  => (bool) $sent,
			'partial'  => $status === 'partial',
		] );
	}

	public static function send_customer_email( $submission_id ) {
		$row = PQC_Storage::get( $submission_id );
		if ( ! $row || empty( $row->customer_email ) || empty( $row->response ) ) {
			return false;
		}

		$settings = pqc_get_settings();

		$from_name   = ! empty( $settings['email_from_name'] ) ? $settings['email_from_name'] : get_bloginfo( 'name' );
		$from_addr   = ! empty( $settings['email_from_address'] ) ? $settings['email_from_address'] : get_option( 'admin_email' );
		$subject     = ! empty( $settings['email_subject'] ) ? $settings['email_subject'] : __( 'Your pool quote comparison', 'pool-quote-compare' );

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
		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = $_SERVER['REMOTE_ADDR'];
		}
		return preg_replace( '/[^0-9a-fA-F:.]/', '', (string) $ip );
	}
}
