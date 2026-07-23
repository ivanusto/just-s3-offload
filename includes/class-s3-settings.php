<?php
/**
 * Just S3 Offload Settings Page
 * Sets up admin menus, registers options, and handles connection testing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Just_WP_S3_Settings {

	/**
	 * @var Just_WP_S3_Client
	 */
	private $client;

	/**
	 * Constructor
	 */
	public function __construct( $client ) {
		$this->client = $client;

		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_notices', array( $this, 'display_notices' ) );

		add_action( 'wp_ajax_just_wp_s3_get_attachment_ids', array( $this, 'ajax_get_attachment_ids' ) );
		add_action( 'wp_ajax_just_wp_s3_process_batch', array( $this, 'ajax_process_batch' ) );
	}

	/**
	 * Add Settings submenu to WP Admin Settings menu.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'S3 Offload Settings', 'just-s3-offload' ),
			__( 'S3 Offload', 'just-s3-offload' ),
			'manage_options',
			'just-s3-offload',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings, sections, and fields.
	 */
	public function register_settings() {
		register_setting( 'just_wp_s3_options', 'just_wp_s3_access_key', 'sanitize_text_field' );
		register_setting( 'just_wp_s3_options', 'just_wp_s3_secret_key', 'sanitize_text_field' );
		register_setting( 'just_wp_s3_options', 'just_wp_s3_region', 'sanitize_text_field' );
		register_setting( 'just_wp_s3_options', 'just_wp_s3_bucket', 'sanitize_text_field' );
		register_setting( 'just_wp_s3_options', 'just_wp_s3_endpoint', 'esc_url_raw' );
		register_setting( 'just_wp_s3_options', 'just_wp_s3_path_style', 'sanitize_text_field' );
		register_setting( 'just_wp_s3_options', 'just_wp_s3_prefix', array(
			'sanitize_callback' => array( $this, 'sanitize_prefix' ),
		) );
		register_setting( 'just_wp_s3_options', 'just_wp_s3_custom_domain', 'esc_url_raw' );
		register_setting( 'just_wp_s3_options', 'just_wp_s3_set_public_acl', 'sanitize_text_field' );
		register_setting( 'just_wp_s3_options', 'just_wp_s3_delete_local', 'sanitize_text_field' );
		register_setting( 'just_wp_s3_options', 'just_wp_s3_cache_control', 'sanitize_text_field' );

		// Credentials Section
		add_settings_section(
			'just_wp_s3_section_credentials',
			__( 'S3 Cloud Storage Credentials', 'just-s3-offload' ),
			null,
			'just-s3-offload'
		);

		add_settings_field(
			'just_wp_s3_access_key',
			__( 'Access Key ID', 'just-s3-offload' ),
			array( $this, 'render_access_key_field' ),
			'just-s3-offload',
			'just_wp_s3_section_credentials'
		);

		add_settings_field(
			'just_wp_s3_secret_key',
			__( 'Secret Access Key', 'just-s3-offload' ),
			array( $this, 'render_secret_key_field' ),
			'just-s3-offload',
			'just_wp_s3_section_credentials'
		);

		add_settings_field(
			'just_wp_s3_region',
			__( 'S3 Region', 'just-s3-offload' ),
			array( $this, 'render_region_field' ),
			'just-s3-offload',
			'just_wp_s3_section_credentials'
		);

		add_settings_field(
			'just_wp_s3_bucket',
			__( 'S3 Bucket Name', 'just-s3-offload' ),
			array( $this, 'render_bucket_field' ),
			'just-s3-offload',
			'just_wp_s3_section_credentials'
		);

		// Compatible Endpoint settings (Optional)
		add_settings_section(
			'just_wp_s3_section_compatible',
			__( 'S3 Compatible / Custom Endpoint Settings (Optional)', 'just-s3-offload' ),
			null,
			'just-s3-offload'
		);

		add_settings_field(
			'just_wp_s3_endpoint',
			__( 'Custom Endpoint URL', 'just-s3-offload' ),
			array( $this, 'render_endpoint_field' ),
			'just-s3-offload',
			'just_wp_s3_section_compatible'
		);

		add_settings_field(
			'just_wp_s3_path_style',
			__( 'Use Path-Style URLs', 'just-s3-offload' ),
			array( $this, 'render_path_style_field' ),
			'just-s3-offload',
			'just_wp_s3_section_compatible'
		);

		// General Behavior Section
		add_settings_section(
			'just_wp_s3_section_behavior',
			__( 'Plugin Behavior & Offload Settings', 'just-s3-offload' ),
			null,
			'just-s3-offload'
		);

		add_settings_field(
			'just_wp_s3_prefix',
			__( 'Folder Path Prefix', 'just-s3-offload' ),
			array( $this, 'render_prefix_field' ),
			'just-s3-offload',
			'just_wp_s3_section_behavior'
		);

		add_settings_field(
			'just_wp_s3_custom_domain',
			__( 'Custom Domain / CDN URL', 'just-s3-offload' ),
			array( $this, 'render_custom_domain_field' ),
			'just-s3-offload',
			'just_wp_s3_section_behavior'
		);

		add_settings_field(
			'just_wp_s3_cache_control',
			__( 'Cache-Control Header', 'just-s3-offload' ),
			array( $this, 'render_cache_control_field' ),
			'just-s3-offload',
			'just_wp_s3_section_behavior'
		);

		add_settings_field(
			'just_wp_s3_set_public_acl',
			__( 'Set Public ACL', 'just-s3-offload' ),
			array( $this, 'render_public_acl_field' ),
			'just-s3-offload',
			'just_wp_s3_section_behavior'
		);

		add_settings_field(
			'just_wp_s3_delete_local',
			__( 'Delete Local Files', 'just-s3-offload' ),
			array( $this, 'render_delete_local_field' ),
			'just-s3-offload',
			'just_wp_s3_section_behavior'
		);
	}

	/**
	 * Sanitization callback for Folder Prefix.
	 */
	public function sanitize_prefix( $value ) {
		return trim( $value, '/ ' );
	}

	/**
	 * Form field renders.
	 */
	public function render_access_key_field() {
		$value = get_option( 'just_wp_s3_access_key', '' );
		?>
		<input type="text" name="just_wp_s3_access_key" id="just_wp_s3_access_key" value="<?php echo esc_attr( $value ); ?>" class="regular-text code" placeholder="AKIA..." />
		<p class="description"><?php esc_html_e( 'Enter your IAM User Access Key ID.', 'just-s3-offload' ); ?></p>
		<?php
	}

	public function render_secret_key_field() {
		$value = get_option( 'just_wp_s3_secret_key', '' );
		?>
		<input type="password" name="just_wp_s3_secret_key" id="just_wp_s3_secret_key" value="<?php echo esc_attr( $value ); ?>" class="regular-text code" autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Enter your IAM User Secret Access Key.', 'just-s3-offload' ); ?></p>
		<?php
	}

	public function render_region_field() {
		$value = get_option( 'just_wp_s3_region', 'us-east-1' );
		?>
		<input type="text" name="just_wp_s3_region" id="just_wp_s3_region" value="<?php echo esc_attr( $value ); ?>" class="regular-text code" placeholder="us-east-1" />
		<p class="description"><?php esc_html_e( 'Enter the S3 region name (e.g. us-east-1, ap-northeast-1).', 'just-s3-offload' ); ?></p>
		<?php
	}

	public function render_bucket_field() {
		$value = get_option( 'just_wp_s3_bucket', '' );
		?>
		<input type="text" name="just_wp_s3_bucket" id="just_wp_s3_bucket" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php echo wp_kses( __( 'Enter the S3 bucket name (e.g. <code>my-wordpress-bucket</code>).', 'just-s3-offload' ), array( 'code' => array() ) ); ?></p>
		<?php
	}

	public function render_endpoint_field() {
		$value = get_option( 'just_wp_s3_endpoint', '' );
		?>
		<input type="url" name="just_wp_s3_endpoint" id="just_wp_s3_endpoint" value="<?php echo esc_url( $value ); ?>" class="regular-text" placeholder="https://s3.us-west-004.backblazeb2.com" />
		<p class="description"><?php echo wp_kses( __( 'Leave blank for default Amazon S3. For other providers, enter the custom endpoint URL (e.g. Cloudflare R2, MinIO, Backblaze B2, DigitalOcean Spaces).', 'just-s3-offload' ), array( 'code' => array() ) ); ?></p>
		<?php
	}

	public function render_path_style_field() {
		$value = get_option( 'just_wp_s3_path_style', '0' );
		?>
		<label for="just_wp_s3_path_style">
			<input type="checkbox" name="just_wp_s3_path_style" id="just_wp_s3_path_style" value="1" <?php checked( $value, '1' ); ?> />
			<?php esc_html_e( 'Force path-style URLs', 'just-s3-offload' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Enable if your custom S3 provider requires path-style addressing (e.g., https://endpoint.com/bucket/file instead of https://bucket.endpoint.com/file). Often required for MinIO or local dev environments.', 'just-s3-offload' ); ?></p>
		<?php
	}

	public function render_prefix_field() {
		$value = get_option( 'just_wp_s3_prefix', '' );
		?>
		<input type="text" name="just_wp_s3_prefix" id="just_wp_s3_prefix" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="e.g. wp-content/uploads" />
		<p class="description"><?php esc_html_e( 'Optional folder path prefix inside the bucket. Do not include leading or trailing slashes.', 'just-s3-offload' ); ?></p>
		<?php
	}

	public function render_custom_domain_field() {
		$value = get_option( 'just_wp_s3_custom_domain', '' );
		?>
		<input type="url" name="just_wp_s3_custom_domain" id="just_wp_s3_custom_domain" value="<?php echo esc_url( $value ); ?>" class="regular-text" placeholder="https://cdn.example.com" />
		<p class="description"><?php echo wp_kses( __( 'Optional custom domain or CDN URL pointing to your S3 bucket. Leave blank to use the default S3 URL structure.', 'just-s3-offload' ), array( 'code' => array() ) ); ?></p>
		<?php
	}

	public function render_cache_control_field() {
		$value = get_option( 'just_wp_s3_cache_control', 'public, max-age=31536000' );
		?>
		<input type="text" name="just_wp_s3_cache_control" id="just_wp_s3_cache_control" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
		<p class="description"><?php echo wp_kses( __( 'The <code>Cache-Control</code> header applied to uploaded objects (e.g., <code>public, max-age=31536000</code>).', 'just-s3-offload' ), array( 'code' => array() ) ); ?></p>
		<?php
	}

	public function render_public_acl_field() {
		$value = get_option( 'just_wp_s3_set_public_acl', '0' );
		?>
		<label for="just_wp_s3_set_public_acl">
			<input type="checkbox" name="just_wp_s3_set_public_acl" id="just_wp_s3_set_public_acl" value="1" <?php checked( $value, '1' ); ?> />
			<?php esc_html_e( 'Set uploaded objects ACL to public-read', 'just-s3-offload' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Enable this to grant public-read access to uploaded files. Disable this if your bucket has "Block Public Access" enabled and you serve files via Cloudfront/CDN using Origin Access Control (OAC), or if your bucket uses Bucket Policy for public read access.', 'just-s3-offload' ); ?></p>
		<?php
	}

	public function render_delete_local_field() {
		$value = get_option( 'just_wp_s3_delete_local', '0' );
		?>
		<label for="just_wp_s3_delete_local">
			<input type="checkbox" name="just_wp_s3_delete_local" id="just_wp_s3_delete_local" value="1" <?php checked( $value, '1' ); ?> />
			<?php esc_html_e( 'Delete local files after successful S3 upload', 'just-s3-offload' ); ?>
		</label>
		<p class="description" style="color: #d63638; font-weight: 500;"><?php esc_html_e( 'Warning: If enabled, WordPress deletes the server local copy after a successful upload. When a local file is needed again (for example by the built-in image editor), the plugin downloads it back from S3 on demand in admin and WP-CLI contexts.', 'just-s3-offload' ); ?></p>
		<?php
	}

	/**
	 * Display Settings page content.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'just_wp_s3_options' );
				do_settings_sections( 'just-s3-offload' );
				submit_button( __( 'Save Settings', 'just-s3-offload' ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Test Connection', 'just-s3-offload' ); ?></h2>
			<p><?php esc_html_e( 'Click the button below to test S3 bucket authentication and write permissions using the currently saved settings.', 'just-s3-offload' ); ?></p>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-bottom: 20px;">
				<input type="hidden" name="action" value="just_wp_s3_test" />
				<?php wp_nonce_field( 'just_wp_s3_test_action', 'just_wp_s3_test_nonce' ); ?>
				<?php
				$bucket     = get_option( 'just_wp_s3_bucket', '' );
				$access_key = get_option( 'just_wp_s3_access_key', '' );
				$secret_key = get_option( 'just_wp_s3_secret_key', '' );
				$disabled   = ( empty( $bucket ) || empty( $access_key ) || empty( $secret_key ) ) ? 'disabled' : '';
				submit_button( __( 'Run Connection Test', 'just-s3-offload' ), 'secondary', 'run_test', true, array( $disabled => $disabled ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Bulk Operations', 'just-s3-offload' ); ?></h2>
			<p><?php esc_html_e( 'Sync your existing Media Library with S3 without using command-line tools.', 'just-s3-offload' ); ?></p>

			<div class="card" style="max-width: 800px; margin-top: 15px; padding: 20px;">
				<!-- Operation 1: Sync Metadata -->
				<div class="bulk-section" style="margin-bottom: 25px;">
					<h3 style="margin-top: 0;"><?php esc_html_e( '1. Sync Database Metadata Only', 'just-s3-offload' ); ?></h3>
					<p class="description" style="margin-bottom: 15px;"><?php esc_html_e( 'Generate offload metadata in the database for files already copied to the S3 bucket (using aws s3 sync or rclone). This process does not upload any files and is extremely fast.', 'just-s3-offload' ); ?></p>

					<div style="margin-bottom: 15px;">
						<label for="bulk_meta_overwrite">
							<input type="checkbox" id="bulk_meta_overwrite" value="1" />
							<?php esc_html_e( 'Overwrite existing S3 metadata (re-sync)', 'just-s3-offload' ); ?>
						</label>
					</div>

					<button type="button" class="button button-secondary" id="btn_run_bulk_meta" <?php echo esc_attr( $disabled ); ?>>
						<?php esc_html_e( 'Run Metadata Sync', 'just-s3-offload' ); ?>
					</button>
				</div>

				<hr style="margin: 20px 0;" />

				<!-- Operation 2: Sync All Files -->
				<div class="bulk-section">
					<h3 style="margin-top: 0;"><?php esc_html_e( '2. Batch Upload Local Files to S3', 'just-s3-offload' ); ?></h3>
					<p class="description" style="margin-bottom: 15px;"><?php esc_html_e( 'Scan all existing media library attachments, upload them to S3, and update database records. This runs in secure chunks to prevent PHP timeout errors.', 'just-s3-offload' ); ?></p>

					<div style="margin-bottom: 10px;">
						<label for="bulk_all_overwrite">
							<input type="checkbox" id="bulk_all_overwrite" value="1" />
							<?php esc_html_e( 'Re-upload files even if already synced', 'just-s3-offload' ); ?>
						</label>
					</div>
					<div style="margin-bottom: 15px;">
						<label for="bulk_all_delete_local" style="color: #d63638; font-weight: 500;">
							<input type="checkbox" id="bulk_all_delete_local" value="1" />
							<?php esc_html_e( 'Delete local files after successful upload (Caution)', 'just-s3-offload' ); ?>
						</label>
					</div>

					<button type="button" class="button button-secondary" id="btn_run_bulk_all" <?php echo esc_attr( $disabled ); ?>>
						<?php esc_html_e( 'Run Batch Upload', 'just-s3-offload' ); ?>
					</button>
				</div>

				<!-- Progress Area -->
				<div id="just_s3_bulk_progress_container" style="display:none; margin-top: 25px; padding: 20px; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px;">
					<h4 id="just_s3_bulk_progress_title" style="margin: 0 0 15px 0; font-size: 14px;"></h4>

					<div style="background: #dcdcde; border-radius: 10px; height: 16px; overflow: hidden; margin-bottom: 12px; position: relative; box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);">
						<div id="just_s3_bulk_progress_bar" style="background: linear-gradient(90deg, #2271b1, #35b0ff); height: 100%; width: 0%; transition: width 0.2s ease; box-shadow: inset 0 -1px 0 rgba(0,0,0,0.15);"></div>
					</div>

					<div style="display: flex; justify-content: space-between; font-weight: 600; font-size: 13px; margin-bottom: 15px;">
						<span id="just_s3_bulk_progress_text">0%</span>
						<span id="just_s3_bulk_progress_stats">0 / 0</span>
					</div>

					<div id="just_s3_bulk_progress_log" style="height: 180px; overflow-y: scroll; background: #1d2327; color: #39ff14; font-family: Consolas, Monaco, monospace; font-size: 12px; padding: 12px; border-radius: 4px; border: 1px solid #3c434a; white-space: pre-wrap; line-height: 1.5;"></div>

					<div style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
						<span id="just_s3_bulk_status_label" style="font-style: italic; color: #646970;"></span>
						<button type="button" class="button button-link-delete" id="btn_cancel_bulk" style="color: #d63638; text-decoration: none; font-weight: 500;"><?php esc_html_e( 'Cancel Operation', 'just-s3-offload' ); ?></button>
					</div>
				</div>
			</div>
		</div>

		<script type="text/javascript">
		var just_s3_bulk_data = {
			nonce: <?php echo json_encode( wp_create_nonce( 'just_wp_s3_bulk_nonce' ) ); ?>
		};

		jQuery(document).ready(function($) {
			var totalItems = 0;
			var processedItems = 0;
			var idsQueue = [];
			var batchSize = 10;
			var currentType = '';
			var isRunning = false;
			var deleteLocal = 0;
			var overwrite = 0;

			// Keep the log bounded and render it in a single write per batch, so the
			// DOM never grows past maxLogLines and reflow cost stays constant.
			var maxLogLines = 300;
			var logLines = [];

			function appendLogLines(msgs) {
				var time = new Date().toLocaleTimeString();
				for (var i = 0; i < msgs.length; i++) {
					logLines.push('[' + time + '] ' + msgs[i]);
				}
				if (logLines.length > maxLogLines) {
					logLines = logLines.slice(logLines.length - maxLogLines);
				}
				var $log = $('#just_s3_bulk_progress_log');
				$log.text(logLines.join('\n') + '\n');
				$log.scrollTop($log[0].scrollHeight);
			}

			function logMessage(msg) {
				appendLogLines([msg]);
			}

			function updateProgress() {
				var percentage = totalItems > 0 ? Math.round((processedItems / totalItems) * 100) : 0;
				$('#just_s3_bulk_progress_bar').css('width', percentage + '%');
				$('#just_s3_bulk_progress_text').text(percentage + '%');
				$('#just_s3_bulk_progress_stats').text(processedItems + ' / ' + totalItems);
			}

			function processNextBatch() {
				if (!isRunning) {
					logMessage('<?php esc_html_e( 'Operation canceled by user.', 'just-s3-offload' ); ?>');
					$('#just_s3_bulk_status_label').text('<?php esc_html_e( 'Canceled.', 'just-s3-offload' ); ?>');
					enableControls();
					return;
				}

				if (idsQueue.length === 0) {
					isRunning = false;
					$('#btn_cancel_bulk').hide();
					logMessage('<?php esc_html_e( '--- Operation completed successfully! ---', 'just-s3-offload' ); ?>');
					$('#just_s3_bulk_status_label').text('<?php esc_html_e( 'Completed.', 'just-s3-offload' ); ?>');
					enableControls();
					return;
				}

				var batch = idsQueue.splice(0, batchSize);
				$('#just_s3_bulk_status_label').text('<?php esc_html_e( 'Processing...', 'just-s3-offload' ); ?>');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'just_wp_s3_process_batch',
						nonce: just_s3_bulk_data.nonce,
						type: currentType,
						ids: batch,
						delete_local: deleteLocal,
						overwrite: overwrite
					},
					success: function(response) {
						if (response.success) {
							if (response.data && response.data.logs && response.data.logs.length) {
								appendLogLines(response.data.logs);
							}
							processedItems += batch.length;
							updateProgress();
							processNextBatch();
						} else {
							var errMsg = response.data && response.data.message ? response.data.message : '<?php esc_html_e( 'Unknown error occurred.', 'just-s3-offload' ); ?>';
							logMessage('<?php esc_html_e( 'Error: ', 'just-s3-offload' ); ?>' + errMsg);
							isRunning = false;
							$('#just_s3_bulk_status_label').text('<?php esc_html_e( 'Failed.', 'just-s3-offload' ); ?>');
							enableControls();
						}
					},
					error: function() {
						logMessage('<?php esc_html_e( 'Error: AJAX request failed.', 'just-s3-offload' ); ?>');
						isRunning = false;
						$('#just_s3_bulk_status_label').text('<?php esc_html_e( 'Failed.', 'just-s3-offload' ); ?>');
						enableControls();
					}
				});
			}

			function disableControls() {
				$('#btn_run_bulk_meta, #btn_run_bulk_all, #bulk_meta_overwrite, #bulk_all_overwrite, #bulk_all_delete_local').prop('disabled', true);
			}

			function enableControls() {
				$('#btn_run_bulk_meta, #btn_run_bulk_all, #bulk_meta_overwrite, #bulk_all_overwrite, #bulk_all_delete_local').prop('disabled', false);
			}

			function startBulkOperation(type) {
				if (isRunning) return;

				currentType = type;
				deleteLocal = type === 'all' && $('#bulk_all_delete_local').is(':checked') ? 1 : 0;
				overwrite = type === 'metadata'
					? ($('#bulk_meta_overwrite').is(':checked') ? 1 : 0)
					: ($('#bulk_all_overwrite').is(':checked') ? 1 : 0);

				batchSize = type === 'metadata' ? 50 : 3;

				logLines = [];
				$('#just_s3_bulk_progress_log').empty();
				$('#just_s3_bulk_progress_container').show();
				$('#btn_cancel_bulk').show();

				var title = type === 'metadata'
					? '<?php esc_html_e( 'Syncing Database Metadata', 'just-s3-offload' ); ?>'
					: '<?php esc_html_e( 'Batch Uploading Files to S3', 'just-s3-offload' ); ?>';
				$('#just_s3_bulk_progress_title').text(title);
				$('#just_s3_bulk_status_label').text('<?php esc_html_e( 'Scanning attachments...', 'just-s3-offload' ); ?>');

				logMessage('<?php esc_html_e( 'Initializing bulk operation...', 'just-s3-offload' ); ?>');
				logMessage('<?php esc_html_e( 'Scanning Media Library for attachments...', 'just-s3-offload' ); ?>');

				isRunning = true;
				totalItems = 0;
				processedItems = 0;
				updateProgress();
				disableControls();

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'just_wp_s3_get_attachment_ids',
						nonce: just_s3_bulk_data.nonce
					},
					success: function(response) {
						if (response.success && response.data && response.data.ids) {
							idsQueue = response.data.ids;
							totalItems = idsQueue.length;
							logMessage('<?php esc_html_e( 'Scan complete. Found ', 'just-s3-offload' ); ?>' + totalItems + '<?php esc_html_e( ' attachments to process.', 'just-s3-offload' ); ?>');
							updateProgress();
							processNextBatch();
						} else {
							logMessage('<?php esc_html_e( 'Error: Failed to scan attachments.', 'just-s3-offload' ); ?>');
							isRunning = false;
							$('#just_s3_bulk_status_label').text('<?php esc_html_e( 'Failed.', 'just-s3-offload' ); ?>');
							enableControls();
						}
					},
					error: function() {
						logMessage('<?php esc_html_e( 'Error: Failed to fetch attachments.', 'just-s3-offload' ); ?>');
						isRunning = false;
						$('#just_s3_bulk_status_label').text('<?php esc_html_e( 'Failed.', 'just-s3-offload' ); ?>');
						enableControls();
					}
				});
			}

			$('#btn_run_bulk_meta').on('click', function() {
				startBulkOperation('metadata');
			});

			$('#btn_run_bulk_all').on('click', function() {
				startBulkOperation('all');
			});

			$('#btn_cancel_bulk').on('click', function() {
				isRunning = false;
				$(this).hide();
				logMessage('<?php esc_html_e( 'Canceling operation...', 'just-s3-offload' ); ?>');
				$('#just_s3_bulk_status_label').text('<?php esc_html_e( 'Canceling...', 'just-s3-offload' ); ?>');
			});
		});
		</script>
		<?php
	}

	/**
	 * Handle Connection Test Action (POST request to admin-post.php).
	 */
	public function handle_test_connection() {
		if ( ! isset( $_POST['action'] ) || $_POST['action'] !== 'just_wp_s3_test' ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'just-s3-offload' ) );
		}

		check_admin_referer( 'just_wp_s3_test_action', 'just_wp_s3_test_nonce' );

		$result = $this->client->test_connection();

		if ( is_wp_error( $result ) ) {
			$url = add_query_arg( array(
				'page'            => 'just-s3-offload',
				's3_test_status' => 'failed',
				's3_error_msg'   => urlencode( $result->get_error_message() )
			), admin_url( 'options-general.php' ) );
		} else {
			$url = add_query_arg( array(
				'page'            => 'just-s3-offload',
				's3_test_status' => 'success'
			), admin_url( 'options-general.php' ) );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Display admin notices on success or failure of connection test.
	 */
	public function display_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of the connection test result; no state is changed.
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'just-s3-offload' ) {
			return;
		}

		if ( isset( $_GET['s3_test_status'] ) ) {
			if ( $_GET['s3_test_status'] === 'success' ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p><strong><?php esc_html_e( 'S3 Connection Test Succeeded!', 'just-s3-offload' ); ?></strong> <?php esc_html_e( 'The plugin successfully authenticated with S3, wrote a test file to the bucket, and deleted it.', 'just-s3-offload' ); ?></p>
				</div>
				<?php
			} elseif ( $_GET['s3_test_status'] === 'failed' ) {
				$msg = isset( $_GET['s3_error_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['s3_error_msg'] ) ) : __( 'Unknown error.', 'just-s3-offload' );
				?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php esc_html_e( 'S3 Connection Test Failed!', 'just-s3-offload' ); ?></strong></p>
					<p><?php echo esc_html( $msg ); ?></p>
				</div>
				<?php
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * AJAX handler to scan all attachment IDs in the Media Library.
	 */
	public function ajax_get_attachment_ids() {
		check_ajax_referer( 'just_wp_s3_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'just-s3-offload' ) ) );
		}

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$attachment_ids = get_posts( $query_args );

		wp_send_json_success( array( 'ids' => $attachment_ids ) );
	}

	/**
	 * AJAX handler to process a batch of attachments (sync metadata or upload files).
	 */
	public function ajax_process_batch() {
		check_ajax_referer( 'just_wp_s3_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'just-s3-offload' ) ) );
		}

		$type         = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$ids          = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : array();
		$overwrite    = isset( $_POST['overwrite'] ) && $_POST['overwrite'] === '1';
		$delete_local = isset( $_POST['delete_local'] ) && $_POST['delete_local'] === '1';

		$bucket = get_option( 'just_wp_s3_bucket' );
		$prefix = get_option( 'just_wp_s3_prefix', '' );

		if ( empty( $bucket ) ) {
			wp_send_json_error( array( 'message' => __( 'S3 bucket name is not configured.', 'just-s3-offload' ) ) );
		}

		if ( empty( $ids ) ) {
			wp_send_json_success( array( 'logs' => array() ) );
		}

		$logs = array();
		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'];

		foreach ( $ids as $attachment_id ) {
			$s3_info = get_post_meta( $attachment_id, '_wp_s3_info', true );
			if ( ! empty( $s3_info ) && ! $overwrite ) {
				/* translators: %d: Attachment ID. */
				$logs[] = sprintf( __( 'ID %d: Already synced, skipped.', 'just-s3-offload' ), $attachment_id );
				continue;
			}

			$main_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
			if ( empty( $main_file ) ) {
				/* translators: %d: Attachment ID. */
				$logs[] = sprintf( __( 'ID %d: No associated file found, skipped.', 'just-s3-offload' ), $attachment_id );
				continue;
			}

			if ( $type === 'metadata' ) {
				$s3_info = array(
					'bucket' => $bucket,
					'prefix' => $prefix,
					'file'   => $main_file
				);
				update_post_meta( $attachment_id, '_wp_s3_info', $s3_info );
				/* translators: 1: Attachment ID, 2: File name. */
				$logs[] = sprintf( __( 'ID %1$d: Metadata synced successfully (%2$s).', 'just-s3-offload' ), $attachment_id, basename( $main_file ) );
			} elseif ( $type === 'all' ) {
				$local_main_file = $basedir . '/' . $main_file;
				if ( ! file_exists( $local_main_file ) ) {
					/* translators: 1: Attachment ID, 2: File name. */
					$logs[] = sprintf( __( 'ID %1$d: Local file not found: %2$s', 'just-s3-offload' ), $attachment_id, basename( $main_file ) );
					continue;
				}

				$metadata     = wp_get_attachment_metadata( $attachment_id );
				$relative_dir = dirname( $main_file );
				if ( $relative_dir === '.' ) {
					$relative_dir = '';
				}

				$files_to_upload = array();

				// Add main file
				$s3_main_key = $this->build_s3_key( $prefix, $main_file );
				$files_to_upload[] = array(
					'local_path' => $local_main_file,
					's3_key'     => $s3_main_key
				);

				// Add original image (pre-conversion source, e.g. the JPEG of a WebP)
				if ( ! empty( $metadata['original_image'] ) ) {
					$relative_original_path = $relative_dir ? $relative_dir . '/' . $metadata['original_image'] : $metadata['original_image'];
					$local_original_file    = $basedir . '/' . $relative_original_path;
					if ( $relative_original_path !== $main_file && file_exists( $local_original_file ) ) {
						$files_to_upload[] = array(
							'local_path' => $local_original_file,
							's3_key'     => $this->build_s3_key( $prefix, $relative_original_path )
						);
					}
				}

				// Add size files
				if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
					foreach ( $metadata['sizes'] as $size => $size_info ) {
						if ( empty( $size_info['file'] ) ) {
							continue;
						}
						$size_file_name = $size_info['file'];
						$relative_size_path = $relative_dir ? $relative_dir . '/' . $size_file_name : $size_file_name;
						$local_size_file = $basedir . '/' . $relative_size_path;

						if ( file_exists( $local_size_file ) ) {
							$s3_size_key = $this->build_s3_key( $prefix, $relative_size_path );
							$files_to_upload[] = array(
								'local_path' => $local_size_file,
								's3_key'     => $s3_size_key
							);
						}
					}
				}

				$uploaded_successfully = array();
				$failed_uploads        = array();

				foreach ( $files_to_upload as $file_info ) {
					$upload = $this->client->upload_file( $file_info['local_path'], $file_info['s3_key'] );
					if ( is_wp_error( $upload ) ) {
						$failed_uploads[] = $upload->get_error_message();
					} else {
						$uploaded_successfully[] = $file_info;
					}
				}

				if ( count( $uploaded_successfully ) > 0 && count( $failed_uploads ) === 0 ) {
					$s3_info_new = array(
						'bucket' => $bucket,
						'prefix' => $prefix,
						'file'   => $main_file
					);
					update_post_meta( $attachment_id, '_wp_s3_info', $s3_info_new );
					/* translators: 1: Attachment ID, 2: File name. */
					$logs[] = sprintf( __( 'ID %1$d: Uploaded successfully (%2$s).', 'just-s3-offload' ), $attachment_id, basename( $main_file ) );

					if ( $delete_local ) {
						foreach ( $uploaded_successfully as $file_info ) {
							wp_delete_file( $file_info['local_path'] );
						}
					}
				} else {
					/* translators: 1: Attachment ID, 2: File name, 3: Error messages. */
					$logs[] = sprintf( __( 'ID %1$d: Failed to upload (%2$s). Errors: %3$s', 'just-s3-offload' ), $attachment_id, basename( $main_file ), implode( ', ', $failed_uploads ) );
				}
			}
		}

		wp_send_json_success( array( 'logs' => $logs ) );
	}

	/**
	 * Build S3 Key combining prefix and relative file path.
	 */
	private function build_s3_key( $prefix, $relative_path ) {
		$key = $relative_path;
		if ( ! empty( $prefix ) ) {
			$key = $prefix . '/' . $key;
		}
		return ltrim( $key, '/' );
	}
}
