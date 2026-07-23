<?php
/**
 * Just S3 Offload Client
 * Handles Amazon S3 and S3-compatible REST API calls using custom AWS Signature Version 4 (SigV4).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Just_WP_S3_Client {

	/**
	 * Send signed S3 API request using AWS Signature Version 4.
	 *
	 * @param string $method   HTTP Method (GET, PUT, DELETE, etc.).
	 * @param string $s3_path  Path to the object in the bucket (e.g. 'uploads/file.png').
	 * @param array  $args     Optional request arguments (body, headers, query).
	 * @return array|WP_Error Response array from wp_remote_request or WP_Error on failure.
	 */
	public function make_s3_request( $method, $s3_path, $args = array() ) {
		$access_key = get_option( 'just_wp_s3_access_key' );
		$secret_key = get_option( 'just_wp_s3_secret_key' );
		$region     = get_option( 'just_wp_s3_region', 'us-east-1' );
		$bucket     = get_option( 'just_wp_s3_bucket' );
		$endpoint   = get_option( 'just_wp_s3_endpoint', '' );
		$path_style = get_option( 'just_wp_s3_path_style', '0' ) === '1';

		if ( empty( $access_key ) || empty( $secret_key ) || empty( $bucket ) ) {
			return new WP_Error( 'missing_credentials', __( 'S3 credentials or bucket name are not configured.', 'just-s3-offload' ) );
		}

		$method = strtoupper( $method );
		$s3_path = ltrim( $s3_path, '/' );

		// 1. Determine Host and Request URL
		if ( empty( $endpoint ) ) {
			// Default AWS S3 Host
			// AWS virtual-host style URL: https://{bucket}.s3.{region}.amazonaws.com/{path}
			$host = $bucket . '.s3.' . $region . '.amazonaws.com';
			$url  = 'https://' . $host . '/' . $s3_path;
			$canonical_uri = '/' . $s3_path;
		} else {
			// Custom S3 compatible endpoint (e.g., MinIO, Cloudflare R2, B2)
			$endpoint_parsed = wp_parse_url( $endpoint );
			if ( ! $endpoint_parsed || empty( $endpoint_parsed['host'] ) ) {
				return new WP_Error( 'invalid_endpoint', __( 'Invalid S3 Endpoint URL configured.', 'just-s3-offload' ) );
			}

			$ep_host   = $endpoint_parsed['host'];
			$ep_scheme = isset( $endpoint_parsed['scheme'] ) ? $endpoint_parsed['scheme'] : 'https';
			$ep_port   = isset( $endpoint_parsed['port'] ) ? ':' . $endpoint_parsed['port'] : '';
			$ep_path   = isset( $endpoint_parsed['path'] ) ? rtrim( $endpoint_parsed['path'], '/' ) : '';

			if ( $path_style ) {
				// Path-style: https://{endpoint}/{bucket}/{path}
				$host = $ep_host . $ep_port;
				$url  = $ep_scheme . '://' . $host . $ep_path . '/' . $bucket . '/' . $s3_path;
				$canonical_uri = $ep_path . '/' . $bucket . '/' . $s3_path;
			} else {
				// Virtual-host style: https://{bucket}.{endpoint}/{path}
				$host = $bucket . '.' . $ep_host . $ep_port;
				$url  = $ep_scheme . '://' . $host . $ep_path . '/' . $s3_path;
				$canonical_uri = $ep_path . '/' . $s3_path;
			}
		}

		// Ensure Canonical URI starts with '/' and encode segments (preserving slashes)
		$canonical_uri = '/' . ltrim( $canonical_uri, '/' );
		$uri_parts     = explode( '/', $canonical_uri );
		$encoded_parts = array_map( 'rawurlencode', $uri_parts );
		$canonical_uri = implode( '/', $encoded_parts );

		// 2. Prepare Payload & Date
		$body         = isset( $args['body'] ) ? $args['body'] : '';
		$payload_hash = hash( 'sha256', $body );

		$amz_date = gmdate( 'Ymd\THis\Z' );
		$date_day = substr( $amz_date, 0, 8 );

		// 3. Prepare headers
		$headers = isset( $args['headers'] ) ? $args['headers'] : array();
		$headers['Host']                 = $host;
		$headers['x-amz-date']           = $amz_date;
		$headers['x-amz-content-sha256'] = $payload_hash;

		// Convert header keys to lowercase, trim keys and values
		$canonical_headers = array();
		foreach ( $headers as $k => $v ) {
			$canonical_headers[ strtolower( trim( $k ) ) ] = trim( $v );
		}
		ksort( $canonical_headers );

		// Build Canonical Headers and Signed Headers
		$canonical_headers_str = '';
		$signed_headers_arr    = array();
		foreach ( $canonical_headers as $k => $v ) {
			$canonical_headers_str .= $k . ':' . $v . "\n";
			$signed_headers_arr[]   = $k;
		}
		$signed_headers_str = implode( ';', $signed_headers_arr );

		// Canonical Query String (if any)
		$canonical_query_str = '';
		if ( isset( $args['query'] ) && is_array( $args['query'] ) ) {
			$query_params = $args['query'];
			ksort( $query_params );
			$query_parts = array();
			foreach ( $query_params as $k => $v ) {
				$query_parts[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
			}
			$canonical_query_str = implode( '&', $query_parts );
		}

		// 4. Build Canonical Request
		$canonical_request = implode( "\n", array(
			$method,
			$canonical_uri,
			$canonical_query_str,
			$canonical_headers_str,
			$signed_headers_str,
			$payload_hash
		) );

		// 5. Build String to Sign
		$service          = 's3';
		$credential_scope = implode( '/', array( $date_day, $region, $service, 'aws4_request' ) );
		$string_to_sign   = implode( "\n", array(
			'AWS4-HMAC-SHA256',
			$amz_date,
			$credential_scope,
			hash( 'sha256', $canonical_request )
		) );

		// 6. Calculate Signature Key (kSigning) and Signature
		$k_date    = hash_hmac( 'sha256', $date_day, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );

		$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		// 7. Assemble Authorization Header
		$headers['Authorization'] = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$access_key,
			$credential_scope,
			$signed_headers_str,
			$signature
		);

		// Execute HTTP Request
		$request_args = array(
			'method'      => $method,
			'timeout'     => isset( $args['timeout'] ) ? $args['timeout'] : 45,
			'headers'     => $headers,
			'body'        => $body,
			'redirection' => 5,
			'httpversion' => '1.1',
		);

		// Optional streaming download support (used by download_file).
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			$request_args['stream']   = true;
			$request_args['filename'] = $args['filename'];
		}

		return wp_remote_request( $url, $request_args );
	}

	/**
	 * Upload a local file to S3.
	 *
	 * @param string $local_path   Absolute path to the local file.
	 * @param string $s3_path      Destination path inside the S3 bucket.
	 * @param string $content_type Optional mime content type.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function upload_file( $local_path, $s3_path, $content_type = '' ) {
		if ( ! file_exists( $local_path ) ) {
			/* translators: %s: Absolute path of the local file. */
			return new WP_Error( 'file_not_found', sprintf( __( 'Local file not found: %s', 'just-s3-offload' ), $local_path ) );
		}

		if ( empty( $content_type ) ) {
			if ( function_exists( 'mime_content_type' ) ) {
				$content_type = mime_content_type( $local_path );
			}
			if ( ! $content_type ) {
				$content_type = 'application/octet-stream';
			}
		}

		$file_content = file_get_contents( $local_path );
		if ( $file_content === false ) {
			/* translators: %s: Absolute path of the local file. */
			return new WP_Error( 'read_error', sprintf( __( 'Failed to read local file: %s', 'just-s3-offload' ), $local_path ) );
		}

		$headers = array(
			'Content-Type' => $content_type,
		);

		// Apply Cache-Control header if set
		$cache_control = get_option( 'just_wp_s3_cache_control', 'public, max-age=31536000' );
		if ( ! empty( $cache_control ) ) {
			$headers['Cache-Control'] = $cache_control;
		}

		// Apply x-amz-acl: public-read if set
		$set_public_acl = get_option( 'just_wp_s3_set_public_acl', '0' );
		if ( $set_public_acl === '1' ) {
			$headers['x-amz-acl'] = 'public-read';
		}

		$response = $this->make_s3_request( 'PUT', $s3_path, array(
			'body'    => $file_content,
			'headers' => $headers,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			$res_body = wp_remote_retrieve_body( $response );
			/* translators: 1: HTTP status code, 2: Response body returned by S3. */
			return new WP_Error( 'upload_failed', sprintf( __( 'Upload to S3 failed with status %1$d. Response: %2$s', 'just-s3-offload' ), $code, $res_body ) );
		}

		return true;
	}

	/**
	 * Delete a file from S3 bucket.
	 *
	 * @param string $s3_path Path of the object in the S3 bucket.
	 * @return bool|WP_Error True on success (or 404), WP_Error on failure.
	 */
	public function delete_file( $s3_path ) {
		$response = $this->make_s3_request( 'DELETE', $s3_path );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		// 204 No Content, 200 OK are success. 404 Not Found is also acceptable as it indicates the file is gone.
		if ( $code !== 204 && $code !== 200 && $code !== 404 ) {
			$body = wp_remote_retrieve_body( $response );
			/* translators: 1: HTTP status code, 2: Response body returned by S3. */
			return new WP_Error( 'delete_failed', sprintf( __( 'Failed to delete object from S3. Response code: %1$d. Response: %2$s', 'just-s3-offload' ), $code, $body ) );
		}

		return true;
	}

	/**
	 * Download an object from S3 to a local file path.
	 *
	 * @param string $s3_path    Path of the object in the S3 bucket.
	 * @param string $local_path Absolute local destination path.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function download_file( $s3_path, $local_path ) {
		$dir = dirname( $local_path );
		if ( ! wp_mkdir_p( $dir ) ) {
			/* translators: %s: Directory path. */
			return new WP_Error( 'mkdir_failed', sprintf( __( 'Failed to create local directory: %s', 'just-s3-offload' ), $dir ) );
		}

		// Stream into a temp file so a failed download never leaves a partial file
		// at the final path.
		$tmp_path = $local_path . '.s3-download';

		$response = $this->make_s3_request( 'GET', $s3_path, array(
			'timeout'  => 120,
			'stream'   => true,
			'filename' => $tmp_path,
		) );

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp_path );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			wp_delete_file( $tmp_path );
			/* translators: 1: HTTP status code, 2: Object path in the bucket. */
			return new WP_Error( 'download_failed', sprintf( __( 'Download from S3 failed with status %1$d for object: %2$s', 'just-s3-offload' ), $code, $s3_path ) );
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		if ( ! $wp_filesystem || ! $wp_filesystem->move( $tmp_path, $local_path, true ) ) {
			wp_delete_file( $tmp_path );
			/* translators: %s: Local file path. */
			return new WP_Error( 'move_failed', sprintf( __( 'Failed to move downloaded file into place: %s', 'just-s3-offload' ), $local_path ) );
		}

		return true;
	}

	/**
	 * Perform a connection test by uploading and deleting a test file.
	 *
	 * @return bool|WP_Error True if connection test passes, WP_Error otherwise.
	 */
	public function test_connection() {
		// Write a temporary test file
		$test_content = 'Just WP S3 Offload Connection Test: ' . current_time( 'mysql' );
		$temp_file    = wp_tempnam( 's3_test' );
		if ( ! $temp_file ) {
			return new WP_Error( 'temp_file_failed', __( 'Failed to create local temp file.', 'just-s3-offload' ) );
		}

		file_put_contents( $temp_file, $test_content );

		$s3_path = 'just-s3-offload-test-connection.txt';
		$upload  = $this->upload_file( $temp_file, $s3_path, 'text/plain' );
		wp_delete_file( $temp_file );

		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		// Delete the test file immediately
		$delete = $this->delete_file( $s3_path );
		if ( is_wp_error( $delete ) ) {
			/* translators: %s: Error message describing why the S3 deletion failed. */
			return new WP_Error( 'delete_failed', sprintf( __( 'Upload succeeded, but S3 deletion failed: %s', 'just-s3-offload' ), $delete->get_error_message() ) );
		}

		return true;
	}
}
