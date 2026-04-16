<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQC_Frontend {

	public static function init() {
		add_shortcode( 'pool_quote_compare', [ __CLASS__, 'shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
	}

	public static function register_assets() {
		wp_register_style( 'pqc-frontend', PQC_PLUGIN_URL . 'assets/css/frontend.css', [], PQC_VERSION );
		wp_register_script( 'pqc-frontend', PQC_PLUGIN_URL . 'assets/js/frontend.js', [], PQC_VERSION, true );
	}

	public static function shortcode( $atts ) {
		$settings = pqc_get_settings();
		$max_mb   = (int) $settings['max_file_mb'];
		$max_n    = (int) $settings['max_files'];

		wp_enqueue_style( 'pqc-frontend' );
		wp_enqueue_script( 'pqc-frontend' );
		wp_localize_script( 'pqc-frontend', 'PQC', [
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'pqc_submit' ),
			'maxFiles' => $max_n,
			'maxBytes' => $max_mb * 1024 * 1024,
			'i18n'     => [
				'tooMany'      => sprintf( __( 'Please upload no more than %d files.', 'pool-quote-compare' ), $max_n ),
				'tooFew'       => __( 'Please upload at least 2 quotes to compare.', 'pool-quote-compare' ),
				'tooBig'       => sprintf( __( 'Each file must be under %d MB.', 'pool-quote-compare' ), $max_mb ),
				'uploading'    => __( 'Uploading your quotes…', 'pool-quote-compare' ),
				'queued'       => __( 'Queued — starting the agent…', 'pool-quote-compare' ),
				'reading'      => __( 'Reading your quotes…', 'pool-quote-compare' ),
				'writing'      => __( 'Writing your comparison live…', 'pool-quote-compare' ),
				'done'         => __( 'Done.', 'pool-quote-compare' ),
				'donePartial'  => __( 'Finished (partial — see below).', 'pool-quote-compare' ),
				'failed'       => __( 'Analysis failed. Please try again or contact us.', 'pool-quote-compare' ),
				'workingTitle' => __( 'Working on your comparison…', 'pool-quote-compare' ),
				'resultTitle'  => __( 'Your comparison', 'pool-quote-compare' ),
				'emailed'      => __( 'A copy has been emailed to you.', 'pool-quote-compare' ),
				'error'        => __( 'Something went wrong. Please try again or contact us.', 'pool-quote-compare' ),
			],
		] );

		ob_start();
		?>
		<div class="pqc-wrapper">
			<form class="pqc-form" id="pqc-form" enctype="multipart/form-data">

				<div class="pqc-row">
					<label for="pqc-name"><?php esc_html_e( 'Your name', 'pool-quote-compare' ); ?></label>
					<input id="pqc-name" name="customer_name" type="text" required />
				</div>

				<div class="pqc-row">
					<label for="pqc-email"><?php esc_html_e( 'Your email (we will send the comparison here)', 'pool-quote-compare' ); ?></label>
					<input id="pqc-email" name="customer_email" type="email" required />
				</div>

				<?php
				$priority_options = [
					'cheap'      => [
						'title'   => __( 'Cheap', 'pool-quote-compare' ),
						'subtext' => __( 'Lowest upfront price you can defensibly justify.', 'pool-quote-compare' ),
					],
					'quick'      => [
						'title'   => __( 'Quick', 'pool-quote-compare' ),
						'subtext' => __( 'Fastest realistic install timeline, in the water sooner.', 'pool-quote-compare' ),
					],
					'good'       => [
						'title'   => __( 'Good', 'pool-quote-compare' ),
						'subtext' => __( 'Premium specification, finish and equipment quality.', 'pool-quote-compare' ),
					],
					'cheap_run'  => [
						'title'   => __( 'Cheap to run long term', 'pool-quote-compare' ),
						'subtext' => __( 'Energy-efficient pump, heating and cover — lowest 10-year running cost.', 'pool-quote-compare' ),
					],
					'low_risk'   => [
						'title'   => __( 'Low risk', 'pool-quote-compare' ),
						'subtext' => __( 'Accountable warranty, watertight scope, no surprise extras during the build.', 'pool-quote-compare' ),
					],
				];
				?>
				<div class="pqc-row">
					<label><?php esc_html_e( "Pick the 2 that matter most to you", 'pool-quote-compare' ); ?></label>
					<p class="pqc-hint pqc-tradeoff-hint"><?php esc_html_e( "It's the classic cheap / quick / good trade-off — plus running cost and risk. Real-world pools can't do all five at once. Pick the two you'll measure each quote against.", 'pool-quote-compare' ); ?></p>
					<div class="pqc-priorities">
						<?php foreach ( $priority_options as $key => $opt ) : ?>
							<label class="pqc-priority">
								<input type="checkbox" name="priorities[]" value="<?php echo esc_attr( $key ); ?>" data-pqc-priority="1" />
								<span class="pqc-priority-title"><?php echo esc_html( $opt['title'] ); ?></span>
								<span class="pqc-priority-sub"><?php echo esc_html( $opt['subtext'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="pqc-hint" id="pqc-priority-warning" hidden></p>
				</div>

				<div class="pqc-row">
					<label for="pqc-notes"><?php esc_html_e( 'Anything else we should know? (optional)', 'pool-quote-compare' ); ?></label>
					<textarea id="pqc-notes" name="customer_notes" rows="4" placeholder="<?php esc_attr_e( 'E.g. budget cap, intended use, family situation, access constraints, timing, planning constraints, or specific concerns about a quote…', 'pool-quote-compare' ); ?>"></textarea>
				</div>

				<div class="pqc-row">
					<label for="pqc-files"><?php printf( esc_html__( 'Upload 2 to %d quotes (PDF or Word)', 'pool-quote-compare' ), (int) $max_n ); ?></label>
					<input id="pqc-files" name="quotes[]" type="file" accept=".pdf,.docx,.doc,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" multiple required />
					<p class="pqc-hint"><?php printf( esc_html__( 'Max %1$d MB per file. Files are saved securely and used only to generate your comparison.', 'pool-quote-compare' ), (int) $max_mb ); ?></p>
				</div>

				<div class="pqc-row pqc-consent">
					<label><input type="checkbox" name="consent" value="1" required /> <?php esc_html_e( 'I agree to have my quotes analysed by an AI and emailed back to me.', 'pool-quote-compare' ); ?></label>
				</div>

				<div class="pqc-row">
					<button type="submit" class="pqc-submit"><?php esc_html_e( 'Compare my quotes', 'pool-quote-compare' ); ?></button>
				</div>

				<div class="pqc-status" id="pqc-status" hidden></div>
			</form>

			<div class="pqc-result" id="pqc-result" hidden>
				<div class="pqc-result-header">
					<h3 id="pqc-result-title"><?php esc_html_e( 'Working on your comparison…', 'pool-quote-compare' ); ?></h3>
					<div class="pqc-progress" id="pqc-progress" aria-live="polite">
						<span class="pqc-spinner" id="pqc-spinner"></span>
						<span class="pqc-progress-text" id="pqc-progress-text"><?php esc_html_e( 'Reading your quotes…', 'pool-quote-compare' ); ?></span>
						<span class="pqc-progress-meta" id="pqc-progress-meta"></span>
					</div>
				</div>
				<div class="pqc-result-body" id="pqc-result-body"></div>
				<p class="pqc-sent-note" id="pqc-sent-note" hidden></p>
			</div>

			<section class="pqc-transparency">
				<h3><?php esc_html_e( 'How this works – full transparency', 'pool-quote-compare' ); ?></h3>
				<p><?php printf(
					esc_html__( 'Your quotes are analysed by Anthropic\'s Claude %s model. Below is the exact system prompt we send to the model. Nothing is hidden.', 'pool-quote-compare' ),
					'<code>' . esc_html( $settings['model'] ) . '</code>'
				); ?></p>
				<?php if ( ! empty( $settings['enable_web_search'] ) ) : ?>
					<p><?php esc_html_e( 'The agent may use web search to verify equipment models, company details, or market pricing. Any sources used are disclosed in the comparison.', 'pool-quote-compare' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Web search is currently disabled — analysis is based only on the information in your uploaded quotes.', 'pool-quote-compare' ); ?></p>
				<?php endif; ?>

				<details class="pqc-prompt-details">
					<summary><?php esc_html_e( 'Show the agent prompt', 'pool-quote-compare' ); ?></summary>
					<pre class="pqc-prompt"><?php echo esc_html( $settings['system_prompt'] ); ?></pre>
				</details>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}
}
