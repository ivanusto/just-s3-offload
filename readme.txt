=== Just S3 Offload ===
Contributors: ivanusto
Tags: amazon s3, s3, offload, media library, cdn
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A lightweight, dependency-free WordPress plugin to offload Media Library to Amazon S3 or S3-compatible cloud storage.

== Description ==

Just S3 Offload is a lightweight WordPress plugin that offloads your Media Library to an Amazon S3 bucket or S3-compatible storage.

It implements a lightweight S3 REST client in pure PHP using the WordPress HTTP API and custom AWS Signature Version 4 (SigV4) signing, completely bypassing the massive official AWS SDK.

= Features =

* **Zero external dependencies**: Weighs only a few dozen kilobytes. No bulky `vendor/` folder or external libraries.
* **AWS Signature Version 4 (SigV4)**: Securely signs requests in pure PHP using native cryptographic functions.
* **S3-Compatible support**: Works out-of-the-box with Cloudflare R2, Backblaze B2, MinIO, DigitalOcean Spaces, etc., via custom endpoint and path-style URL configuration.
* **Automatic media offloading**: Automatically uploads new images and all generated sub-sizes (thumbnails) to S3 during upload.
* **URL and srcset rewriting**: Seamlessly rewrites image URLs and responsive `srcset` paths to point to S3 or a custom CDN domain.
* **Optional local cleanup**: Optionally deletes the local server copy of uploaded files to save disk space.
* **Automatic deletion**: Automatically deletes original and resized files from S3 when an attachment is permanently deleted from the WordPress admin.
* **WP-CLI integration**: Provides command-line tools to migrate existing media library items and sync database metadata.
* **Connection test**: A simple button in settings to test read/write/delete permissions.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/just-s3-offload` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to **Settings -> S3 Offload** and input your S3 access credentials, region, and bucket name.
4. Click **Run Connection Test** to verify your credentials and permissions.

== Frequently Asked Questions ==

= Does this plugin require the AWS SDK? =

No. The plugin implements a minimal S3 REST client in pure PHP with no external dependencies.

= How should I configure my bucket for public access? =

Either enable public access via bucket policy, or check the "Set Public ACL" option in the plugin settings to apply a `public-read` ACL to every uploaded file.

== Changelog ==

= 1.1.0 =
* Initial release of Just S3 Offload. Features AWS SigV4 signed request handling, custom S3-compatible endpoint support, and WordPress hook integrations.
