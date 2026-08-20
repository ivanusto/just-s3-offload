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
	 * Upper bound on attachments queued before the queue is flushed early.
	 *
	 * A single request can touch a very large number of attachments - a WP-CLI
	 * import loop, for instance - and deferring all of them to the very end of
	 * the run would be neither memory-friendly nor crash-safe.
	 */
	const QUEUE_FLUSH_THRESHOLD = 100;

	/**
	 * @var Just_WP_S3_Client
	 */
	private $client;

	/**
	 * Attachment IDs queued for offload, keyed by ID.
	 *
	 * @var array<int,bool>
	 */
	private $queued = array();

	/**
	 * Constructor
	 */
	public function __construct( $client ) {
		$this->client = $client;

		/*
		 * Queue offloads rather than running them inline. WordPress saves the
		 * attachment metadata once per generated sub-size (see
		 * _wp_make_subsizes()), so uploading on every call re-uploads every file
		 * already on disk and makes the number of S3 requests grow with the
		 * square of the sub-size count. Running once at the end of the request
		 * also means "delete local files" happens after sub-size generation has
		 * finished, instead of removing the source image it still needs.
		 */
		add_filter( 'wp_update_attachment_metadata', array( $this, 'queue_attachment_offload' ), 10, 2 );
		add_action( 'shutdown', array( $this, 'process_queued_offloads' ), 20 );

		// Hook into URL retrieval filters to rewrite local URLs to S3 URLs
		add_filter( 'wp_get_attachment_url', array( $this, 's3_get_attachment_url' ), 10, 2 );
		add_filter( 'image_downsize', array( $this, 's3_image_downsize' ), 10, 3 );
		add_filter( 'wp_calculate_image_srcset_sources', array( $this, 's3_image_srcset_sources' ), 10, 5 );

		// Hook into attachment deletion to clean up S3 files
		add_action( 'delete_attachment', array( $this, 'delete_attachment_files' ) );

		// Fetch offloaded files back from S3 on demand when a local copy is needed
		// (e.g. the built-in image editor) but was deleted after offload
		add_filter( 'get_attached_file', array( $this, 'maybe_rehydrate_local_file' ), 10, 2 );
	}

	/**
	 * Queue an attachment to be offloaded at the end of the request.
	 *
	 * @since 1.4.0
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment post ID.
	 * @return array The metadata, unchanged.
	 */
	public function queue_attachment_offload( $metadata, $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id > 0 ) {
			$this->queued[ $attachment_id ] = true;

			if ( count( $this->queued ) > self::QUEUE_FLUSH_THRESHOLD ) {
				// Hold back the attachment currently being written: more of its
				// sub-sizes are probably still to come, and offloading it now
				// would upload a partial set.
				unset( $this->queued[ $attachment_id ] );
				$this->process_queued_offloads();
				$this->queued[ $attachment_id ] = true;
			}
		}

		return $metadata;
	}

	/**
	 * Offload every attachment queued during this request.
	 *
	 * @since 1.4.0
	 */
	public function process_queued_offloads() {
		if ( empty( $this->queued ) ) {
			return;
		}

		$attachment_ids = array_keys( $this->queued );
		$this->queued   = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$this->offload_attachment( $attachment_id );
		}
	}

	/**
	 * Upload an attachment's original, companion and sub-size files to S3.
	 *
	 * Reads the metadata fresh instead of trusting the array passed to the
	 * metadata filter: by the time this runs the sub-sizes have been generated
	 * and saved, so the stored metadata is the complete picture.
	 *
	 * @since 1.4.0 Replaces upload_attachment_files(), which ran on every
	 *              metadata save.
	 *
	 * @param int $attachment_id Attachment post ID.
	 */
	public function offload_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		// The attachment may have been deleted after it was queued.
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return;
		}

		$bucket = get_option( 'just_wp_s3_bucket' );
		if ( empty( $bucket ) ) {
			return;
		}

		// Some attachments, such as the site icon, must remain on local storage.
		if ( $this->should_skip_attachment( $attachment_id ) ) {
			return;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : array();

		$prefix     = get_option( 'just_wp_s3_prefix', '' );
		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'];

		// Retrieve the main file path
		$main_file = ! empty( $metadata['file'] ) ? $metadata['file'] : get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( empty( $main_file ) ) {
			return;
		}

		// Collect the main file, its companion files and every sub-size that is
		// actually present on disk. WordPress 7.1 client-side media processing
		// writes metadata in two requests - the upload and the finalize call -
		// so this runs once per request; files already uploaded and removed
		// locally simply fall out of the list on the second pass.
		$files_to_upload = array();

		foreach ( just_wp_s3_collect_attachment_files( $metadata, $main_file ) as $relative_path ) {
			$local_path = $basedir . '/' . $relative_path;
			if ( ! file_exists( $local_path ) ) {
				continue;
			}
			$files_to_upload[] = array(
				'local_path' => $local_path,
				's3_key'    => $this->build_s3_key( $prefix, $relative_path ),
			);
		}

		// Perform uploads
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
					error_log( sprintf( 'Just S3 Offload: Upload failed for %s. Error: %s', $file_info['local_path'], $upload->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only logs when WP_DEBUG is enabled.
				}
			} else {
				$uploaded_successfully[] = $file_info;
			}
		}

		// Save the offload marker and delete local files if configured and everything succeeded
		if ( count( $uploaded_successfully ) > 0 && count( $failed_uploads ) === 0 ) {
			// Save sync metadata
			$s3_info = array(
				'bucket' => $bucket,
				'prefix' => $prefix,
				'file'   => $main_file
			);
			update_post_meta( $attachment_id, '_wp_s3_info', $s3_info );

			// The object is in the bucket again, so a previously failed
			// rehydration attempt must no longer be suppressed.
			delete_transient( $this->rehydrate_failure_key( $attachment_id ) );

			// Check delete local config
			$delete_local = get_option( 'just_wp_s3_delete_local', '0' );
			if ( $delete_local === '1' ) {
				foreach ( $uploaded_successfully as $file_info ) {
					wp_delete_file( $file_info['local_path'] );
				}
			}
		}
	}

	/**
	 * Determine whether a missing local file may be fetched back from S3.
	 *
	 * `get_attached_file` also fires on read-only paths, most importantly
	 * wp_prepare_attachment_for_js(), which core runs once per attachment
	 * whenever the Media Library grid, the block editor media picker, or any
	 * similar browser loads a page of results. Rehydrating there turns a single
	 * screen into dozens of full-size bucket downloads, so the default is to
	 * refuse and allow only the requests that genuinely need bytes on disk.
	 *
	 * @since 1.4.1
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool True when rehydration is allowed for the current request.
	 */
	private function should_rehydrate( $attachment_id ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$allow = true;
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads the action name to identify the context; core authorises the request itself.
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			$allow  = in_array( $action, array( 'image-editor', 'imgedit-preview', 'crop-image' ), true );
		}

		/**
		 * Filters whether a missing local file may be downloaded back from S3.
		 *
		 * Tools that legitimately need the local original, such as thumbnail
		 * regenerators, can opt in through this filter.
		 *
		 * @since 1.4.1
		 *
		 * @param bool $allow         Whether rehydration is allowed.
		 * @param int  $attachment_id Attachment post ID.
		 */
		return (bool) apply_filters( 'just_wp_s3_rehydrate', $allow, $attachment_id );
	}

	/**
	 * Download the attached file back from S3 when the local copy is missing.
	 *
	 * Runs only in the contexts allowed by should_rehydrate(), so browsing the
	 * Media Library never triggers bucket downloads. Each attachment is
	 * attempted at most once per request, and a failed attempt is remembered for
	 * an hour so a missing object is not re-requested on every page load.
	 *
	 * @param string $file          Local file path.
	 * @param int    $attachment_id Attachment post ID.
	 * @return string The local file path (unchanged).
	 */
	public function maybe_rehydrate_local_file( $file, $attachment_id ) {
		static $attempted = array();

		if ( empty( $file ) || isset( $attempted[ $attachment_id ] ) || file_exists( $file ) ) {
			return $file;
		}

		if ( ! $this->should_rehydrate( $attachment_id ) ) {
			return $file;
		}

		$failure_key = $this->rehydrate_failure_key( $attachment_id );
		if ( get_transient( $failure_key ) ) {
			return $file;
		}

		$s3_info = get_post_meta( $attachment_id, '_wp_s3_info', true );
		if ( ! $s3_info || ! is_array( $s3_info ) || empty( $s3_info['bucket'] ) ) {
			return $file;
		}

		$attempted[ $attachment_id ] = true;

		$relative_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( empty( $relative_path ) ) {
			return $file;
		}

		$prefix = isset( $s3_info['prefix'] ) ? $s3_info['prefix'] : '';
		$result = $this->client->download_file( $this->build_s3_key( $prefix, $relative_path ), $file );

		if ( is_wp_error( $result ) ) {
			// Remember the failure so an object that is missing from the bucket
			// is not requested again on every subsequent request.
			set_transient( $failure_key, 1, HOUR_IN_SECONDS );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( 'Just S3 Offload: Rehydrate failed for attachment %d: %s', $attachment_id, $result->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only logs when WP_DEBUG is enabled.
			}
		}

		return $file;
	}

	/**
	 * Transient key holding the last failed rehydration attempt.
	 *
	 * @since 1.4.1
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string Transient key.
	 */
	private function rehydrate_failure_key( $attachment_id ) {
		return 'just_wp_s3_nodl_' . (int) $attachment_id;
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
			$s3_url        = $this->get_s3_url( $s3_info, $relative_path );
			if ( ! empty( $s3_url ) ) {
				return $s3_url;
			}
		}

		return $url;
	}

	/**
	 * Short-circuit image downsizing to return S3 URL and size details.
	 *
	 * @param bool|array   $downsize      Short-circuit value from earlier filters.
	 * @param int          $attachment_id Attachment post ID.
	 * @param string|int[] $size          Registered size name, or array( width, height ).
	 * @return array|false array( url, width, height, is_intermediate ), or false to defer to WordPress.
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
			$width     = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
			$height    = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;
		} else {
			/*
			 * Let core resolve the requested size. This covers registered size
			 * names and array( width, height ) requests, for which it picks the
			 * smallest sub-size that is large enough and matches the aspect ratio,
			 * and constrains the reported dimensions to the requested box. It
			 * reads metadata only and never calls image_downsize(), so it cannot
			 * recurse back into this filter.
			 */
			$intermediate = image_get_intermediate_size( $attachment_id, $size );

			if ( ! is_array( $intermediate ) || empty( $intermediate['file'] ) ) {
				/*
				 * No sub-size matched, e.g. sub-sizes were never generated.
				 * Returning false hands control back to image_downsize(), which
				 * falls back to the original via wp_get_attachment_url() - already
				 * rewritten to S3 by s3_get_attachment_url() - and applies
				 * image_constrain_size_for_editor() to the reported dimensions.
				 */
				return false;
			}

			/*
			 * 'file' is a bare basename sitting next to $metadata['file'], the same
			 * relationship image_downsize() assumes when it swaps the basename of
			 * the full-size URL. $intermediate['path'] is deliberately not used: it
			 * is uploads-relative rather than absolute, is missing when
			 * $metadata['file'] is empty, and gains a './' prefix on flat upload
			 * structures.
			 */
			$file_name       = $intermediate['file'];
			$width           = isset( $intermediate['width'] ) ? (int) $intermediate['width'] : 0;
			$height          = isset( $intermediate['height'] ) ? (int) $intermediate['height'] : 0;
			$is_intermediate = true;
		}

		if ( empty( $file_name ) ) {
			return false;
		}

		$relative_path = $relative_dir ? $relative_dir . '/' . $file_name : $file_name;
		$url           = $this->get_s3_url( $s3_info, $relative_path );

		if ( empty( $url ) ) {
			return false;
		}

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
				$s3_url        = $this->get_s3_url( $s3_info, $relative_path );
				if ( ! empty( $s3_url ) ) {
					$sources[ $width ]['url'] = $s3_url;
				}
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
		$metadata = is_array( $metadata ) ? $metadata : array();

		/*
		 * Resolve the main file the same way the upload side does. Only images
		 * carry a 'file' key in their metadata: wp_read_video_metadata() and
		 * wp_read_audio_metadata() return duration, codec and dimensions but no
		 * path, so a video or audio attachment would otherwise resolve to no
		 * files at all and leave its object behind in the bucket.
		 */
		$main_file = ! empty( $metadata['file'] ) ? $metadata['file'] : get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( empty( $main_file ) ) {
			return;
		}

		$prefix = isset( $s3_info['prefix'] ) ? $s3_info['prefix'] : '';

		foreach ( just_wp_s3_collect_attachment_files( $metadata, $main_file ) as $relative_path ) {
			$this->client->delete_file( $this->build_s3_key( $prefix, $relative_path ) );
		}
	}

	/**
	 * Determine whether an attachment must stay on local storage.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool True when the attachment must not be offloaded.
	 */
	public function should_skip_attachment( $attachment_id ) {
		return just_wp_s3_should_skip_attachment( $attachment_id );
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
	 *
	 * Returns an empty string when there is no object to point at, so callers
	 * keep the local URL. This matters beyond correctness: a URL that ends at
	 * the bucket rather than an object is a ListObjects (GET Bucket) request,
	 * which S3 bills at the higher LIST rate. An attachment carrying S3
	 * metadata but no file path would otherwise make every page view issue
	 * one.
	 *
	 * @param array  $s3_info      Stored S3 metadata for the attachment.
	 * @param string $relative_path Uploads-relative path of the file.
	 * @return string Fully-qualified URL, or an empty string when none applies.
	 */
	private function get_s3_url( $s3_info, $relative_path ) {
		if ( ! is_array( $s3_info ) ) {
			return '';
		}

		$relative_path = ltrim( (string) $relative_path, '/' );
		if ( '' === $relative_path ) {
			return '';
		}

		$custom_domain = get_option( 'just_wp_s3_custom_domain' );
		$prefix        = isset( $s3_info['prefix'] ) ? $s3_info['prefix'] : '';
		$bucket        = isset( $s3_info['bucket'] ) ? $s3_info['bucket'] : '';

		$s3_key = $this->build_s3_key( $prefix, $relative_path );
		if ( '' === $s3_key ) {
			return '';
		}

		// Encode each segment separately so directory separators survive while
		// characters that would otherwise terminate or reshape the path, such as
		// '#', '?' and '%', are escaped.
		$encoded_key = implode( '/', array_map( 'rawurlencode', explode( '/', $s3_key ) ) );

		if ( ! empty( $custom_domain ) ) {
			return rtrim( $custom_domain, '/' ) . '/' . $encoded_key;
		}

		if ( empty( $bucket ) ) {
			return '';
		}

		$endpoint   = get_option( 'just_wp_s3_endpoint', '' );
		$region     = get_option( 'just_wp_s3_region', 'us-east-1' );
		$path_style = get_option( 'just_wp_s3_path_style', '0' ) === '1';

		// Bucket names containing dots break wildcard TLS certificates in
		// virtual-hosted-style URLs. Force path-style for them.
		if ( strpos( $bucket, '.' ) !== false ) {
			$path_style = true;
		}

		$aws_url = $path_style
			? 'https://s3.' . $region . '.amazonaws.com/' . $bucket . '/' . $encoded_key
			: 'https://' . $bucket . '.s3.' . $region . '.amazonaws.com/' . $encoded_key;

		if ( empty( $endpoint ) ) {
			// Default AWS S3 URL
			return $aws_url;
		}

		$endpoint_parsed = wp_parse_url( $endpoint );
		if ( ! $endpoint_parsed || empty( $endpoint_parsed['host'] ) ) {
			// Fallback to default
			return $aws_url;
		}

		$ep_host   = $endpoint_parsed['host'];
		$ep_scheme = isset( $endpoint_parsed['scheme'] ) ? $endpoint_parsed['scheme'] : 'https';
		$ep_port   = isset( $endpoint_parsed['port'] ) ? ':' . $endpoint_parsed['port'] : '';
		$ep_path   = isset( $endpoint_parsed['path'] ) ? rtrim( $endpoint_parsed['path'], '/' ) : '';

		if ( $path_style ) {
			return $ep_scheme . '://' . $ep_host . $ep_port . $ep_path . '/' . $bucket . '/' . $encoded_key;
		}

		return $ep_scheme . '://' . $bucket . '.' . $ep_host . $ep_port . $ep_path . '/' . $encoded_key;
	}
}
