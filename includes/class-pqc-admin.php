<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQC_Admin {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'admin_post_pqc_resend_email', [ __CLASS__, 'handle_resend' ] );
	}

	public static function menu() {
		add_menu_page(
			__( 'Pool Quote Compare', 'pool-quote-compare' ),
			__( 'Pool Quotes', 'pool-quote-compare' ),
			'manage_options',
			'pool-quote-compare',
			[ __CLASS__, 'render_settings' ],
			'dashicons-clipboard',
			58
		);
		add_submenu_page(
			'pool-quote-compare',
			__( 'Settings', 'pool-quote-compare' ),
			__( 'Settings', 'pool-quote-compare' ),
			'manage_options',
			'pool-quote-compare',
			[ __CLASS__, 'render_settings' ]
		);
		add_submenu_page(
			'pool-quote-compare',
			__( 'Submissions', 'pool-quote-compare' ),
			__( 'Submissions', 'pool-quote-compare' ),
			'manage_options',
			'pqc-submissions',
			[ __CLASS__, 'render_submissions' ]
		);
	}

	public static function register_settings() {
		register_setting( 'pqc_settings_group', PQC_OPTION_KEY, [
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
			'default'           => [],
		] );
	}

	public static function sanitize( $input ) {
		$settings = pqc_get_settings();
		$clean = $settings;

		if ( isset( $input['api_key'] ) ) {
			$clean['api_key'] = trim( sanitize_text_field( $input['api_key'] ) );
		}
		if ( isset( $input['companies_house_api_key'] ) ) {
			$clean['companies_house_api_key'] = trim( sanitize_text_field( $input['companies_house_api_key'] ) );
		}
		if ( isset( $input['system_prompt'] ) ) {
			$clean['system_prompt'] = wp_unslash( $input['system_prompt'] );
		}
		if ( isset( $input['model'] ) ) {
			$clean['model'] = sanitize_text_field( $input['model'] );
		}
		if ( isset( $input['max_tokens'] ) ) {
			$clean['max_tokens'] = max( 1024, min( 128000, (int) $input['max_tokens'] ) );
		}
		$clean['enable_web_search'] = ! empty( $input['enable_web_search'] ) ? 1 : 0;
		if ( isset( $input['max_file_mb'] ) ) {
			$clean['max_file_mb'] = max( 1, min( 200, (int) $input['max_file_mb'] ) );
		}
		if ( isset( $input['max_files'] ) ) {
			$clean['max_files'] = max( 2, min( 20, (int) $input['max_files'] ) );
		}
		if ( isset( $input['email_from_name'] ) ) {
			$clean['email_from_name'] = sanitize_text_field( $input['email_from_name'] );
		}
		if ( isset( $input['email_from_address'] ) ) {
			$clean['email_from_address'] = sanitize_email( $input['email_from_address'] );
		}
		if ( isset( $input['email_subject'] ) ) {
			$clean['email_subject'] = sanitize_text_field( $input['email_subject'] );
		}
		if ( isset( $input['email_intro'] ) ) {
			$clean['email_intro'] = wp_kses_post( $input['email_intro'] );
		}
		if ( isset( $input['disclaimer'] ) ) {
			$clean['disclaimer'] = wp_kses_post( $input['disclaimer'] );
		}
		$clean['bcc_admin'] = ! empty( $input['bcc_admin'] ) ? 1 : 0;

		return $clean;
	}

	public static function enqueue( $hook ) {
		if ( strpos( (string) $hook, 'pool-quote-compare' ) === false && strpos( (string) $hook, 'pqc-submissions' ) === false ) {
			return;
		}
		wp_enqueue_style( 'pqc-admin', PQC_PLUGIN_URL . 'assets/css/admin.css', [], PQC_VERSION );
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = pqc_get_settings();
		$default_prompt = pqc_default_system_prompt();
		?>
		<div class="wrap pqc-admin">
			<h1><?php esc_html_e( 'Pool Quote Compare – Settings', 'pool-quote-compare' ); ?></h1>
			<p><?php esc_html_e( 'Configure the Claude Opus 4.7 (1M context) comparison agent. The system prompt shown below is displayed to customers on the upload page for full transparency.', 'pool-quote-compare' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'pqc_settings_group' ); ?>

				<h2><?php esc_html_e( 'Claude API', 'pool-quote-compare' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pqc_api_key"><?php esc_html_e( 'API key', 'pool-quote-compare' ); ?></label></th>
						<td>
							<input type="password" id="pqc_api_key" name="<?php echo esc_attr( PQC_OPTION_KEY ); ?>[api_key]" value="<?php echo esc_attr( $settings['api_key'] ); ?>" class="regular-text" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Anthropic API key. Stored in wp_options. Never exposed to the frontend.', 'pool-quote-compare' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pqc_model"><?php esc_html_e( 'Model', 'pool-quote-compare' ); ?></label></th>
						<td>
							<input type="text" id="pqc_model" name="<?php echo esc_attr( PQC_OPTION_KEY ); ?>[model]" value="<?php echo esc_attr( $settings['model'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Default: claude-opus-4-7 (Opus 4.7 with 1M context window).', 'pool-quote-compare' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pqc_max_tokens"><?php esc_html_e( 'Max output tokens', 'pool-quote-compare' ); ?></label></th>
						<td>
							<input type="number" id="pqc_max_tokens" name="<?php echo esc_attr( PQC_OPTION_KEY ); ?>[max_tokens]" value="<?php echo esc_attr( $settings['max_tokens'] ); ?>" min="1024" max="128000" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Web search', 'pool-quote-compare' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( PQC_OPTION_KEY ); ?>[enable_web_search]" value="1" <?php checked( 1, $settings['enable_web_search'] ); ?> /> <?php esc_html_e( 'Let the agent use the web_search tool to verify facts (Google reviews, Trustpilot, equipment datasheets, news).', 'pool-quote-compare' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pqc_ch_api_key"><?php esc_html_e( 'Companies House API key', 'pool-quote-compare' ); ?></label></th>
						<td>
							<input type="password" id="pqc_ch_api_key" name="<?php echo esc_attr( PQC_OPTION_KEY ); ?>[companies_house_api_key]" value="<?php echo esc_attr( $settings['companies_house_api_key'] ); ?>" class="regular-text" autocomplete="off" />
							<p class="description"><?php
								printf(
									esc_html__( 'Free UK Companies House API key (%s). When set, the agent can look up legal entity, incorporation, officers, filings, PSCs and charges for every company named in a quote — authoritative data, not brochure claims.', 'pool-quote-compare' ),
									'<a href="https://developer.company-information.service.gov.uk/" target="_blank" rel="noopener">developer.company-information.service.gov.uk</a>'
								);
							?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Uploads', 'pool-quote-compare' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pqc_max_file_mb"><?php esc_html_e( 'Max file size (MB)', 'pool-quote-compare' ); ?></label></th>
						<td>
							<input type="number" id="pqc_max_file_mb" name="<?php echo esc_attr( PQC_OPTION_KEY ); ?>[max_file_mb]" value="<?php echo esc_attr( $settings['max_file_mb'] ); ?>" min="1" max="200" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pqc_max_files"><?php esc_html_e( 'Max files per submission', 'pool-quote-compare' ); ?></label></th>
						<td>
							<input type="number" id="pqc_max_files" name="<?php echo esc_attr( PQC_OPTION_KEY ); ?>[max_files]" value="<?php echo esc_attr( $settings['max_files'] ); ?>" min="2" max="20" />
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Email delivery', 'pool-quote-compare' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pqc_email_from_name"><?php esc_html_e( 'From name', 'pool-quote-compare' ); ?></label></th>
						<td><input type="text" id="pqc_email_from_name" name="<?php echo esc_attr( PQC_OPTION_KEY ); ?>[email_from_name]" value="<?php echo esc_attr( $settings['email_from_name'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="pqc_email_from_address"><?php esc_html_e( 'From address', 'pool-quote-compare' ); ?></label></th>
						<td><input type="email" id="pqc_email_from_address" name="<?php echo esc_attr( PQC_OPTION_KEY ); ?>[email_from_address]" value="<?php echo esc_attr( $settings['email_from_address'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="pqc_email_subject"><?php esc_html_e( 'Subject', 'pool-quote-compare' ); ?></label></th>
						<td><input type="text" id="pqc_email_subject" name="<?php echo esc_attr( PQC_OPTION_KEY ); ?>[email_subject]" value="<?php echo esc_attr( $settings['email_subject'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="pqc_email_intro"><?php esc_html_e( 'Email intro', 'pool-quote-compare' ); ?></label></th>
						<td><textarea id="pqc_email_intro" name="<?php echo esc_attr( PQC_OPTION_KEY ); ?>[email_intro]" rows="3" class="large-text"><?php echo esc_textarea( $settings['email_intro'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="pqc_disclaimer"><?php esc_html_e( 'Disclaimer', 'pool-quote-compare' ); ?></label></th>
						<td>
							<textarea id="pqc_disclaimer" name="<?php echo esc_attr( PQC_OPTION_KEY ); ?>[disclaimer]" rows="6" class="large-text"><?php echo esc_textarea( $settings['disclaimer'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown at the bottom of the comparison on the webpage and in the email. Covers AI accuracy limitations and the customer\'s duty to verify.', 'pool-quote-compare' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Admin copy', 'pool-quote-compare' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( PQC_OPTION_KEY ); ?>[bcc_admin]" value="1" <?php checked( 1, $settings['bcc_admin'] ); ?> /> <?php esc_html_e( 'BCC the site admin on every customer email.', 'pool-quote-compare' ); ?></label></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Agent system prompt', 'pool-quote-compare' ); ?></h2>
				<p class="description"><?php esc_html_e( 'This is the system prompt sent to Claude. It is also displayed to customers on the upload page so they can see exactly how their quotes will be analysed.', 'pool-quote-compare' ); ?></p>
				<textarea name="<?php echo esc_attr( PQC_OPTION_KEY ); ?>[system_prompt]" rows="24" class="large-text code"><?php echo esc_textarea( $settings['system_prompt'] ); ?></textarea>
				<p><button type="button" class="button" onclick="document.getElementById('pqc_default_prompt').style.display='block';return false;"><?php esc_html_e( 'Show packaged default', 'pool-quote-compare' ); ?></button></p>
				<pre id="pqc_default_prompt" style="display:none;max-height:300px;overflow:auto;background:#f6f7f7;padding:12px;border:1px solid #ccd0d4;"><?php echo esc_html( $default_prompt ); ?></pre>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Shortcode', 'pool-quote-compare' ); ?></h2>
			<p><?php esc_html_e( 'Place this shortcode on any page to show the comparison form:', 'pool-quote-compare' ); ?></p>
			<code>[pool_quote_compare]</code>
		</div>
		<?php
	}

	public static function render_submissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$view_id = isset( $_GET['view'] ) ? (int) $_GET['view'] : 0;
		if ( $view_id ) {
			self::render_single_submission( $view_id );
			return;
		}

		$rows = PQC_Storage::recent( 200 );
		?>
		<div class="wrap pqc-admin">
			<h1><?php esc_html_e( 'Submissions', 'pool-quote-compare' ); ?></h1>
			<?php if ( ! empty( $_GET['resent'] ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Email resent.', 'pool-quote-compare' ); ?></p></div>
			<?php endif; ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>ID</th>
						<th><?php esc_html_e( 'Date', 'pool-quote-compare' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'pool-quote-compare' ); ?></th>
						<th><?php esc_html_e( 'Email', 'pool-quote-compare' ); ?></th>
						<th><?php esc_html_e( 'Files', 'pool-quote-compare' ); ?></th>
						<th><?php esc_html_e( 'Status', 'pool-quote-compare' ); ?></th>
						<th><?php esc_html_e( 'Emailed', 'pool-quote-compare' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No submissions yet.', 'pool-quote-compare' ); ?></td></tr>
					<?php else : foreach ( $rows as $row ) : ?>
						<tr>
							<td>#<?php echo (int) $row->id; ?></td>
							<td><?php echo esc_html( $row->created_at ); ?></td>
							<td><?php echo esc_html( $row->customer_name ); ?></td>
							<td><?php echo esc_html( $row->customer_email ); ?></td>
							<td><?php echo (int) $row->file_count; ?></td>
							<td><?php echo esc_html( $row->status ); ?></td>
							<td><?php echo $row->emailed_at ? esc_html( $row->emailed_at ) : '—'; ?></td>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=pqc-submissions&view=' . (int) $row->id ) ); ?>" class="button button-small"><?php esc_html_e( 'View', 'pool-quote-compare' ); ?></a></td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_single_submission( $id ) {
		$row = PQC_Storage::get( $id );
		if ( ! $row ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Submission not found.', 'pool-quote-compare' ) . '</p></div>';
			return;
		}
		$files = json_decode( $row->files_json, true );
		if ( ! is_array( $files ) ) {
			$files = [];
		}
		$usage = json_decode( $row->usage_json, true );
		?>
		<div class="wrap pqc-admin">
			<h1><?php printf( esc_html__( 'Submission #%d', 'pool-quote-compare' ), (int) $row->id ); ?></h1>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=pqc-submissions' ) ); ?>">&larr; <?php esc_html_e( 'Back to list', 'pool-quote-compare' ); ?></a></p>

			<h2><?php esc_html_e( 'Customer', 'pool-quote-compare' ); ?></h2>
			<table class="widefat"><tbody>
				<tr><th><?php esc_html_e( 'Submitted', 'pool-quote-compare' ); ?></th><td><?php echo esc_html( $row->created_at ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Name', 'pool-quote-compare' ); ?></th><td><?php echo esc_html( $row->customer_name ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Email', 'pool-quote-compare' ); ?></th><td><?php echo esc_html( $row->customer_email ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Notes', 'pool-quote-compare' ); ?></th><td><?php echo nl2br( esc_html( $row->customer_notes ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'IP', 'pool-quote-compare' ); ?></th><td><?php echo esc_html( $row->ip_address ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Status', 'pool-quote-compare' ); ?></th><td><?php echo esc_html( $row->status ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Emailed', 'pool-quote-compare' ); ?></th><td><?php echo $row->emailed_at ? esc_html( $row->emailed_at ) : '—'; ?></td></tr>
			</tbody></table>

			<h2><?php esc_html_e( 'Uploaded files', 'pool-quote-compare' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Filename', 'pool-quote-compare' ); ?></th>
					<th><?php esc_html_e( 'Size', 'pool-quote-compare' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $files as $idx => $f ) :
					$dl_url = wp_nonce_url(
						admin_url( 'admin-ajax.php?action=pqc_download&id=' . (int) $row->id . '&i=' . (int) $idx ),
						'pqc_download_' . (int) $row->id
					);
				?>
					<tr>
						<td><?php echo esc_html( isset( $f['original'] ) ? $f['original'] : '' ); ?></td>
						<td><?php echo esc_html( isset( $f['size'] ) ? size_format( (int) $f['size'] ) : '' ); ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( $dl_url ); ?>"><?php esc_html_e( 'Download', 'pool-quote-compare' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'AI response', 'pool-quote-compare' ); ?></h2>
			<?php if ( ! empty( $row->error_message ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $row->error_message ); ?></p></div>
			<?php endif; ?>
			<div class="pqc-response-box"><?php echo PQC_Markdown::render( (string) $row->response ); ?></div>

			<?php if ( $usage ) : ?>
				<h3><?php esc_html_e( 'Usage', 'pool-quote-compare' ); ?></h3>
				<pre><?php echo esc_html( wp_json_encode( $usage, JSON_PRETTY_PRINT ) ); ?></pre>
			<?php endif; ?>

			<h2><?php esc_html_e( 'System prompt used', 'pool-quote-compare' ); ?></h2>
			<details><summary><?php esc_html_e( 'Show prompt', 'pool-quote-compare' ); ?></summary>
				<pre style="max-height:400px;overflow:auto;background:#f6f7f7;padding:12px;border:1px solid #ccd0d4;"><?php echo esc_html( $row->system_prompt ); ?></pre>
			</details>

			<?php if ( $row->customer_email && $row->response ) : ?>
				<h2><?php esc_html_e( 'Email', 'pool-quote-compare' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'pqc_resend_' . $row->id, 'pqc_resend_nonce' ); ?>
					<input type="hidden" name="action" value="pqc_resend_email" />
					<input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" />
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Re-send email to customer', 'pool-quote-compare' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_resend() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'pqc_resend_' . $id, 'pqc_resend_nonce' );
		PQC_Ajax::send_customer_email( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=pqc-submissions&view=' . $id . '&resent=1' ) );
		exit;
	}
}
