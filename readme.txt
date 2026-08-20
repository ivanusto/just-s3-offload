=== Just S3 Offload ===
Contributors: ivanusto
Tags: amazon s3, s3, offload, media library, cdn
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

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

= 1.4.1 =
* Fixed: files recorded under `sources` were never offloaded. The WordPress Performance team's Modern Image Formats plugin (`webp-uploads`) stores one file per output format there - on the attachment metadata and on every sub-size - and the converted WebP or AVIF exists nowhere else. With that plugin configured to keep the original format alongside the modern one, a stock upload produced twelve files of which only seven reached the bucket; the five derivatives 404ed, so the `<picture>` sources on the front end pointed at objects that did not exist, and deleting the attachment left them behind.
* Note: sites where the modern format replaces the original - the default, and the case where `_wp_attached_file` already points at the `.webp` - were unaffected, because the original is recorded as `original_image` and was already handled.

= 1.4.0 =
* Fixed: uploading a single image issued far more S3 requests than it had files. WordPress saves the attachment metadata once per generated sub-size, and the plugin re-uploaded every file already on disk each time, so the request count grew with the square of the sub-size count. Offloading now happens once per request, at the end, and each file is uploaded exactly once.
* Fixed: with "Delete Local Files" enabled, the original was uploaded and deleted on the first metadata save, which happens *before* WordPress generates the sub-sizes. Sub-size generation then had no source image and silently produced nothing, leaving attachments with no thumbnails at all - every size fell back to the full-size original. Local files are now removed only after sub-size generation has finished.
* Fixed: browsing the Media Library could issue one full-size download per attachment. `get_attached_file` also fires on read-only paths, including the grid view and the block editor media picker, so on a site with "Delete Local Files" enabled a single page of results turned into dozens of bucket downloads. Rehydration now defaults to off and only runs for WP-CLI and the built-in image editor.
* Fixed: an attachment carrying S3 metadata but no file path produced a URL ending at the bucket rather than an object, which S3 serves as a ListObjects request and bills at the higher LIST rate. Such attachments now fall back to their local URL.
* Fixed: requests for a size given as `array( width, height )` always returned the full-size original while reporting the requested dimensions as if they were real. Size resolution is now delegated to WordPress core, so the correct sub-size is served.
* Fixed: deleting a video or audio attachment left its object behind in the bucket. Only images carry a `file` key in their attachment metadata, so the cleanup resolved no files at all for anything else. It now falls back to `_wp_attached_file`.
* Fixed: object keys are percent-encoded per path segment, so file names containing `#`, `?` or `%` produce a working URL. Sites using a CDN will see a one-off wave of cache misses for any affected file names.
* Fixed: a failed rehydration is now remembered for an hour instead of being retried on every request.
* New: WordPress 7.1 companion files are offloaded and deleted alongside the attachment - `source_image` (the HEIC kept next to its JPEG derivative) and `animated_video` / `animated_video_poster` (the MP4/WebM an animated GIF is converted to in the browser, and its poster frame).
* Fixed: a sub-size file registered under several size names is uploaded and deleted once instead of once per name. WordPress 7.1 deduplicates sizes that share dimensions, so this is now common.
* New: the site icon is no longer offloaded. Its URLs are printed into the document head on every page load, so it now always stays on the local site rather than depending on the bucket or CDN being reachable.
* New: `just_wp_s3_skip_attachment`, `just_wp_s3_rehydrate` and `just_wp_s3_companion_meta_keys` filters.
* Changed: `upload_attachment_files()` is replaced by `queue_attachment_offload()` and `offload_attachment()`. The `_wp_s3_processing` post meta flag is no longer used; existing rows are harmless leftovers.
* Tested against WordPress 7.1, including the client-side media processing upload flow (`POST /wp/v2/media/{id}/sideload` and `/finalize`).

= 1.3.1 =
* Fix: bucket names containing dots (e.g. `assets.example.com`) failed the connection test and all S3 requests with cURL error 60 (TLS certificate mismatch), because virtual-hosted-style URLs produce multi-level subdomains that wildcard certificates cannot cover. Path-style addressing is now applied automatically for such buckets, for both API requests and generated file URLs.

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
