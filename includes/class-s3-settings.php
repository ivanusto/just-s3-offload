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
		<p class="description" style="color: #d63638; font-weight: 500;"><?php esc_html_e( 'Warning: If enabled, WordPress will delete the server local copy. Built-in image editing (crop/rotate) in WP Admin requires local files and might fail.', 'just-s3-offload' ); ?></p>
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
			
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
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
		</div>
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
				'page'           => 'just-s3-offload',
				's3_test_status' => 'failed',
				's3_error_msg'   => urlencode( $result->get_error_message() )
			), admin_url( 'options-general.php' ) );
		} else {
			$url = add_query_arg( array(
				'page'           => 'just-s3-offload',
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
}
