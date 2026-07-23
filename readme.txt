=== Just S3 Offload ===
Contributors: ivanusto
Tags: amazon s3, s3, offload, media library, cdn
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.0
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

= 1.3.0 =
* New: on-demand rehydration. When a local file is missing but the attachment is offloaded (e.g. after enabling "Delete Local Files"), the plugin automatically downloads it back from S3 the moment WordPress needs the local path — so the built-in image editor and thumbnail regeneration keep working. Downloads only trigger in admin and WP-CLI contexts, never on the front end.
* New: S3 client download support (streamed to disk via a temp file, so failed downloads never leave partial files).
* Updated the "Delete Local Files" setting description to reflect the new behavior.

= 1.2.2 =
* Offload the pre-conversion original image (`original_image` in attachment metadata, e.g. the JPEG source of a WebP conversion or the pre-scaled original) in automatic uploads, the Bulk Upload UI, and WP-CLI sync-all, so the Media Library "original file" link resolves on S3.
* Delete the original image object from S3 when an attachment is permanently deleted.

= 1.2.1 =
* Bulk Operations UI: the live log now keeps only the most recent 300 lines and renders each batch in a single write, preventing severe browser slowdown on large media libraries (tens of thousands of items).
* Bulk Operations UI: log output is rendered as plain text instead of HTML.
* WP-CLI: sync-metadata and sync-all now process attachments in chunks with meta-cache preloading, and release the in-process object cache between chunks so memory usage stays flat on large media libraries.

= 1.2.0 =
* Added Bulk Operations UI to S3 Offload Settings page (Sync Database Metadata Only and Batch Upload Local Files to S3) using secure, sequential AJAX requests with progress bar and live log output.

= 1.1.0 =
* Initial release of Just S3 Offload. Features AWS SigV4 signed request handling, custom S3-compatible endpoint support, and WordPress hook integrations.
