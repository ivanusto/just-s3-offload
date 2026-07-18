<?php
/**
 * Just S3 Offload Media Handler
 * Hooks into WordPress media upload, URL rewrite, and deletion processes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Just_WP_S3_Media_Handler {

	/**
	 * @var Just_WP_S3_Client
	 */
	private $client;

	/**
	 * Constructor
	 */
	public function __construct( $client ) {
		$this->client = $client;

		// Hook into metadata generation to upload files to S3
		add_filter( 'wp_update_attachment_metadata', array( $this, 'upload_attachment_files' ), 10, 2 );

		// Hook into URL retrieval filters to rewrite local URLs to S3 URLs
		add_filter( 'wp_get_attachment_url', array( $this, 's3_get_attachment_url' ), 10, 2 );
		add_filter( 'image_downsize', array( $this, 's3_image_downsize' ), 10, 3 );
		add_filter( 'wp_calculate_image_srcset_sources', array( $this, 's3_image_srcset_sources' ), 10, 5 );

		// Hook into attachment deletion to clean up S3 files
		add_action( 'delete_attachment', array( $this, 'delete_attachment_files' ) );
	}

	/**
	 * Upload attachment original and sub-size files to S3.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment post ID.
	 * @return array Modified metadata.
	 */
	public function upload_attachment_files( $metadata, $attachment_id ) {
		// Prevent double uploads or processing if already done
		if ( get_post_meta( $attachment_id, '_wp_s3_processing', true ) ) {
			return $metadata;
		}

		$bucket = get_option( 'just_wp_s3_bucket' );
		if ( empty( $bucket ) ) {
			return $metadata;
		}

		update_post_meta( $attachment_id, '_wp_s3_processing', '1' );

		$prefix     = get_option( 'just_wp_s3_prefix', '' );
		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'];

		// Retrieve the main file path
		$main_file = isset( $metadata['file'] ) ? $metadata['file'] : get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( empty( $main_file ) ) {
			delete_post_meta( $attachment_id, '_wp_s3_processing' );
			return $metadata;
		}

		$local_main_file = $basedir . '/' . $main_file;
		$relative_dir    = dirname( $main_file );
		if ( $relative_dir === '.' ) {
			$relative_dir = '';
		}

		$files_to_upload = array();

		// 1. Add main file to upload queue
		if ( file_exists( $local_main_file ) ) {
			$s3_main_key = $this->build_s3_key( $prefix, $main_file );
			$files_to_upload[] = array(
				'local_path' => $local_main_file,
				's3_key'     => $s3_main_key,
			);
		}

		// 2. Add all intermediate size files to upload queue
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
						's3_key'     => $s3_size_key,
					);
				}
			}
		}

		// 3. Perform uploads
		$uploaded_successfully = array();
		$failed_uploads        = array();

		foreach ( $files_to_upload as $file_info ) {
			$upload = $this->client->upload_file( $file_info['local_path'], $file_info['s3_key'] );
			if ( is_wp_error( $upload ) ) {
				$failed_uploads[] = array(
					'file'  => $file_info['local_path'],
					'error' => $upload->get_error_message()
				);
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'Just S3 Offload: Upload failed for %s. Error: %s', $file_info['local_path'], $upload->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			} else {
				$uploaded_successfully[] = $file_info;
			}
		}

		// 4. Save metadata flag and delete local files if configured and everything succeeded
		if ( count( $uploaded_successfully ) > 0 && count( $failed_uploads ) === 0 ) {
			// Save sync metadata
			$s3_info = array(
				'bucket' => $bucket,
				'prefix' => $prefix,
				'file'   => $main_file
			);
			update_post_meta( $attachment_id, '_wp_s3_info', $s3_info );

			// Check delete local config
			$delete_local = get_option( 'just_wp_s3_delete_local', '0' );
			if ( $delete_local === '1' ) {
				foreach ( $uploaded_successfully as $file_info ) {
					wp_delete_file( $file_info['local_path'] );
				}
			}
		}

		delete_post_meta( $attachment_id, '_wp_s3_processing' );
		return $metadata;
	}

	/**
	 * Rewrite attachment URL to S3 URL.
	 */
	public function s3_get_attachment_url( $url, $attachment_id ) {
		$s3_info = get_post_meta( $attachment_id, '_wp_s3_info', true );
		if ( ! $s3_info || ! is_array( $s3_info ) || empty( $s3_info['bucket'] ) ) {
			return $url;
		}

		$upload_dir = wp_upload_dir();
		$baseurl    = isset( $upload_dir['baseurl'] ) ? $upload_dir['baseurl'] : '';
		if ( empty( $baseurl ) || empty( $url ) ) {
			return $url;
		}

		// If URL matches local uploads URL base, rewrite it
		if ( strpos( $url, $baseurl ) === 0 ) {
			$relative_path = substr( $url, strlen( $baseurl ) );
			$relative_path = ltrim( $relative_path, '/' );
			return $this->get_s3_url( $s3_info, $relative_path );
		}

		return $url;
	}

	/**
	 * Short-circuit image downsizing to return S3 URL and size details.
	 */
	public function s3_image_downsize( $downsize, $attachment_id, $size ) {
		$s3_info = get_post_meta( $attachment_id, '_wp_s3_info', true );
		if ( ! $s3_info || ! is_array( $s3_info ) || empty( $s3_info['bucket'] ) ) {
			return false; // Skip and let WP handle locally
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! $metadata || ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
			return false;
		}

		$relative_dir = dirname( $metadata['file'] );
		if ( $relative_dir === '.' ) {
			$relative_dir = '';
		}

		$width           = 0;
		$height          = 0;
		$is_intermediate = false;
		$file_name       = '';

		if ( $size === 'full' ) {
			$file_name = basename( $metadata['file'] );
			$width     = isset( $metadata['width'] ) ? $metadata['width'] : 0;
			$height    = isset( $metadata['height'] ) ? $metadata['height'] : 0;
		} elseif ( is_string( $size ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) && isset( $metadata['sizes'][ $size ] ) && is_array( $metadata['sizes'][ $size ] ) ) {
			$size_data       = $metadata['sizes'][ $size ];
			$file_name       = isset( $size_data['file'] ) ? $size_data['file'] : '';
			$width           = isset( $size_data['width'] ) ? $size_data['width'] : 0;
			$height          = isset( $size_data['height'] ) ? $size_data['height'] : 0;
			$is_intermediate = true;
		} else {
			// Fallback: If it's a width/height array or unregistered size
			if ( is_array( $size ) && isset( $size[0] ) && isset( $size[1] ) ) {
				$file_name = basename( $metadata['file'] );
				$width     = $size[0];
				$height    = $size[1];
			} else {
				return false;
			}
		}

		if ( empty( $file_name ) ) {
			return false;
		}

		$relative_path = $relative_dir ? $relative_dir . '/' . $file_name : $file_name;
		$url           = $this->get_s3_url( $s3_info, $relative_path );

		return array( $url, $width, $height, $is_intermediate );
	}

	/**
	 * Rewrite URLs inside the image srcset attribute.
	 */
	public function s3_image_srcset_sources( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		$s3_info = get_post_meta( $attachment_id, '_wp_s3_info', true );
		if ( ! $s3_info || ! is_array( $s3_info ) || empty( $s3_info['bucket'] ) || ! is_array( $sources ) ) {
			return $sources;
		}

		$upload_dir = wp_upload_dir();
		$baseurl    = isset( $upload_dir['baseurl'] ) ? $upload_dir['baseurl'] : '';
		if ( empty( $baseurl ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $source ) {
			if ( ! is_array( $source ) || empty( $source['url'] ) ) {
				continue;
			}
			$source_url = $source['url'];
			if ( strpos( $source_url, $baseurl ) === 0 ) {
				$relative_path = substr( $source_url, strlen( $baseurl ) );
				$relative_path = ltrim( $relative_path, '/' );
				$sources[ $width ]['url'] = $this->get_s3_url( $s3_info, $relative_path );
			}
		}

		return $sources;
	}

	/**
	 * Clean up S3 objects when attachment is deleted.
	 *
	 * @param int $attachment_id Attachment post ID.
	 */
	public function delete_attachment_files( $attachment_id ) {
		$s3_info = get_post_meta( $attachment_id, '_wp_s3_info', true );
		if ( ! $s3_info || ! is_array( $s3_info ) || empty( $s3_info['bucket'] ) ) {
			return;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! $metadata || ! is_array( $metadata ) ) {
			return;
		}

		$prefix       = isset( $s3_info['prefix'] ) ? $s3_info['prefix'] : '';
		$main_file    = isset( $metadata['file'] ) ? $metadata['file'] : '';
		$relative_dir = dirname( $main_file );
		if ( $relative_dir === '.' ) {
			$relative_dir = '';
		}

		// Delete original S3 key
		if ( ! empty( $main_file ) ) {
			$s3_main_key = $this->build_s3_key( $prefix, $main_file );
			$this->client->delete_file( $s3_main_key );
		}

		// Delete sizes keys
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size => $size_info ) {
				if ( empty( $size_info['file'] ) ) {
					continue;
				}
				$size_file_name = $size_info['file'];
				$relative_size_path = $relative_dir ? $relative_dir . '/' . $size_file_name : $size_file_name;
				$s3_size_key = $this->build_s3_key( $prefix, $relative_size_path );
				$this->client->delete_file( $s3_size_key );
			}
		}
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

	/**
	 * Generate fully-qualified S3 or CDN URL.
	 */
	private function get_s3_url( $s3_info, $relative_path ) {
		if ( ! is_array( $s3_info ) ) {
			return '';
		}
		$custom_domain = get_option( 'just_wp_s3_custom_domain' );
		$prefix        = isset( $s3_info['prefix'] ) ? $s3_info['prefix'] : '';
		$bucket        = isset( $s3_info['bucket'] ) ? $s3_info['bucket'] : '';

		$s3_key = $this->build_s3_key( $prefix, $relative_path );

		if ( ! empty( $custom_domain ) ) {
			return rtrim( $custom_domain, '/' ) . '/' . $s3_key;
		}

		$endpoint   = get_option( 'just_wp_s3_endpoint', '' );
		$region     = get_option( 'just_wp_s3_region', 'us-east-1' );
		$path_style = get_option( 'just_wp_s3_path_style', '0' ) === '1';

		if ( empty( $endpoint ) ) {
			// Default AWS S3 URL
			return 'https://' . $bucket . '.s3.' . $region . '.amazonaws.com/' . $s3_key;
		} else {
			$endpoint_parsed = wp_parse_url( $endpoint );
			if ( ! $endpoint_parsed || empty( $endpoint_parsed['host'] ) ) {
				// Fallback to default
				return 'https://' . $bucket . '.s3.' . $region . '.amazonaws.com/' . $s3_key;
			}
			$ep_host   = $endpoint_parsed['host'];
			$ep_scheme = isset( $endpoint_parsed['scheme'] ) ? $endpoint_parsed['scheme'] : 'https';
			$ep_port   = isset( $endpoint_parsed['port'] ) ? ':' . $endpoint_parsed['port'] : '';
			$ep_path   = isset( $endpoint_parsed['path'] ) ? rtrim( $endpoint_parsed['path'], '/' ) : '';

			if ( $path_style ) {
				return $ep_scheme . '://' . $ep_host . $ep_port . $ep_path . '/' . $bucket . '/' . $s3_key;
			} else {
				return $ep_scheme . '://' . $bucket . '.' . $ep_host . $ep_port . $ep_path . '/' . $s3_key;
			}
		}
	}
}
