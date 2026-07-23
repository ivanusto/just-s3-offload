# Just S3 Offload

[繁體中文版說明請參見 README.zh-TW.md](README.zh-TW.md) | [WordPress on S3 Implementation Guide](GUIDE.md) | [WordPress on S3 中文實作指南](GUIDE.zh-TW.md)

A lightweight, dependency-free WordPress plugin to offload your Media Library to Amazon S3 or S3-compatible cloud storage (Cloudflare R2, Backblaze B2, DigitalOcean Spaces, MinIO, etc.).

> [!NOTE]
> Looking for Google Cloud Storage (GCS) support instead? Check out our sister project: [Just GCS Offload](https://github.com/ivanusto/just-gcs-offload).

Unlike other bloated cloud storage plugins, **Just S3 Offload** is designed to be as small and efficient as possible. It implements a lightweight S3 REST client in pure PHP using cURL and native cryptographic functions for AWS Signature Version 4 (SigV4), completely bypassing the massive official AWS SDK.

## Features

* **Zero External Dependencies**: Weighs only a few dozen kilobytes. No bulky `vendor/` folder or external libraries.
* **AWS Signature Version 4 (SigV4)**: Securely signs requests in pure PHP using native cryptographic functions (`hash_hmac`).
* **S3-Compatible Storage Support**: Out-of-the-box support for custom S3 endpoints (Cloudflare R2, MinIO, Backblaze B2, DigitalOcean Spaces, etc.) and path-style URLs.
* **Automatic Media Offloading**: Automatically uploads new images and all generated sub-sizes (thumbnails) to S3 during upload.
* **URL & Srcset Rewriting**: Seamlessly rewrites image URLs and responsive `srcset` paths to point to S3 or a custom CDN domain.
* **Optional Local Cleanup**: Optionally deletes the local server copy of uploaded files to save disk space.
* **Automatic Deletion**: Automatically deletes original and resized files from S3 when an attachment is permanently deleted from the WordPress admin.
* **WP-CLI Integration**: Provides powerful command-line tools to migrate existing media library items and sync database metadata.
* **Connection Test**: A simple button in settings to test read/write/delete permissions.

## Requirements

* PHP 7.4 or higher
* PHP Hash extension enabled
* WordPress 6.0 or higher

## Installation

1. Download the latest `just-s3-offload.zip` from the [Releases](https://github.com/ivanusto/just-s3-offload/releases) page.
2. In your WordPress admin, go to **Plugins -> Add New -> Upload Plugin**, select the zip file, and click **Install Now**.
3. Activate the plugin.

## S3 Bucket Configuration

To ensure your offloaded images are publicly readable, configure your S3 bucket using one of the following methods:

### Method A: Public Bucket Access via Bucket Policy (Recommended)
1. Keep the bucket default ACL private and disable public access block settings if you wish to serve files directly.
2. Configure a bucket policy that allows `GetObject` to `*` or specific referrers, OR map the bucket to a Cloudflare CDN which handles public serving.
3. In WordPress settings, keep the "Set Public ACL" option **disabled**.

### Method B: Object ACLs (Fine-grained Access Control)
1. In your bucket settings, ensure ACLs are enabled (e.g., Object writer ownership or Bucket owner preferred).
2. In WordPress settings, enable the **"Set Public ACL"** option. This will apply a `public-read` ACL header to every uploaded object.

## Configuration Settings

Navigate to **Settings -> S3 Offload** in your WordPress dashboard to configure the following settings:

* **Access Key ID**: Your S3 account access key.
* **Secret Access Key**: Your S3 account secret key.
* **S3 Region**: The region of your bucket (e.g., `us-east-1`, `ap-northeast-1`).
* **S3 Bucket Name**: Enter the target S3 bucket name (e.g., `my-wordpress-bucket`).
* **Custom Endpoint URL**: (Optional) For S3-compatible providers, enter the custom endpoint (e.g. `https://<accountid>.r2.cloudflarestorage.com` or `https://s3.us-west-004.backblazeb2.com`).
* **Use Path-Style URLs**: Check this if your S3-compatible service requires path-style URLs (`endpoint/bucket/file` instead of `bucket.endpoint/file`).
* **Folder Path Prefix**: (Optional) Subfolder path inside the bucket (e.g., `wp-content/uploads`). Do not add leading or trailing slashes.
* **Custom Domain / CDN URL**: (Optional) Custom domain or CDN mapping (e.g., `https://cdn.example.com`). If empty, the default S3 URL structure will be used.
* **Cache-Control Header**: The Cache-Control header applied to uploaded objects (defaults to `public, max-age=31536000`).
* **Set Public ACL**: Check this to set the uploaded objects ACL to public-read.
* **Delete Local Files**: Check this to delete local copies of files after uploading them to S3. *Note: Deleting local files may prevent the built-in WordPress image editor (crop/rotate) from working.*

Click **Run Connection Test** to verify that your credentials and permissions are configured correctly.

## WP-CLI Commands

For developers and system administrators, the plugin provides custom WP-CLI commands to perform batch operations and migrations.

### 1. Sync Database Metadata
If you have already copied your files to the S3 bucket using tools like `aws s3 sync` or `rclone`, use this command to generate the offload metadata (`_wp_s3_info`) in the database so WordPress rewrites the URLs.
```bash
wp s3-offload sync-metadata [--bucket=<bucket>] [--prefix=<prefix>] [--overwrite]
```

### 2. Batch Upload Local Files
Scan all existing media library attachments, upload them to S3, and update their database records.
```bash
wp s3-offload sync-all [--delete-local] [--overwrite]
```
* Use `--delete-local` to remove local copies after successful upload.
* Use `--overwrite` to re-upload files that are already marked as synced.

## Troubleshooting

If you encounter any issues (such as a fatal error or blank screen in the Media Library):
1. Enable debugging in your `wp-config.php`:
   ```php
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   define( 'WP_DEBUG_DISPLAY', false );
   ```
2. Re-trigger the error.
3. Check the logs:
   * WordPress Debug Log: `wp-content/debug.log`
   * Web Server Error Logs: `/var/log/apache2/error.log` or `/var/log/nginx/error.log`

## License

This project is licensed under the MIT License.
