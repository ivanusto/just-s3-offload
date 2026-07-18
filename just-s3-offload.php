<?php
/**
 * Plugin Name: Just S3 Offload
 * Plugin URI:  https://github.com/ivanusto/just-s3-offload
 * Description: A lightweight, dependency-free plugin to offload WordPress Media Library to Amazon S3 or S3-compatible storage (R2, B2, Spaces, MinIO) using custom SigV4 authentication.
 * Version:     1.1.0
 * Author:      Ivan Lin
 * Author URI:  https://yblog.org
 * License:     MIT
 * Text Domain: just-s3-offload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define Constants
define( 'JUST_WP_S3_VERSION', '1.1.0' );
define( 'JUST_WP_S3_PATH', plugin_dir_path( __FILE__ ) );
define( 'JUST_WP_S3_URL', plugin_dir_url( __FILE__ ) );

// Load classes
require_once JUST_WP_S3_PATH . 'includes/class-s3-client.php';
require_once JUST_WP_S3_PATH . 'includes/class-s3-settings.php';
require_once JUST_WP_S3_PATH . 'includes/class-s3-media-handler.php';

// Initialize Plugin
function just_wp_s3_init() {
	// Initialize S3 Client with settings
	$client = new Just_WP_S3_Client();

	// Initialize Settings Page
	new Just_WP_S3_Settings( $client );

	// Initialize Media Handler
	new Just_WP_S3_Media_Handler( $client );
}
add_action( 'plugins_loaded', 'just_wp_s3_init' );

// Register WP-CLI command if active
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once JUST_WP_S3_PATH . 'includes/class-s3-cli.php';
	WP_CLI::add_command( 's3-offload', 'Just_WP_S3_CLI' );
}
