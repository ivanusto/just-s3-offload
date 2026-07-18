# WordPress on S3 Implementation Guide (with just-s3-offload)

[繁體中文版說明請參見 GUIDE.zh-TW.md](GUIDE.zh-TW.md)

This guide provides a comprehensive path to migrating your WordPress Media Library to Amazon S3 or S3-compatible cloud storage (like Cloudflare R2, Backblaze B2, MinIO, or DigitalOcean Spaces) using the `just-s3-offload` plugin.

The primary goal of this project is to provide a lightweight, high-performance solution that avoids PHP timeouts and memory constraints when dealing with massive media libraries (e.g., tens of GiBs / 40,000+ files).

---

## 1. IAM Policy Configuration (AWS S3)

To allow WordPress to authenticate and perform operations on your S3 bucket, you should create an IAM User with programmatic access (Access Key / Secret Key) and attach a policy restricting permissions to your specific bucket.

Here is a recommended minimum-privilege IAM Policy:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "VisualEditor0",
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:GetObject",
                "s3:PutObjectAcl",
                "s3:DeleteObject"
            ],
            "Resource": "arn:aws:s3:::[BUCKET_NAME]/*"
        }
    ]
}
```
*(Replace `[BUCKET_NAME]` with your actual S3 bucket name. If you do not enable the "Set Public ACL" option in the plugin settings, you can omit `s3:PutObjectAcl` from the actions.)*

---

## 2. First-Time Media Library Migration (CLI Command)

Before activating the plugin's rewriting features, it is recommended to sync your existing local `uploads` folder to S3. **Ensuring correct path alignment is critical!**

Run this command from your WordPress root directory:

### For Amazon S3 (using AWS CLI):
```bash
aws s3 sync wp-content/uploads/ s3://[BUCKET_NAME]/[PREFIX]
```
*(If using Object ACLs for public read, append `--acl public-read` to the command.)*

### For Cloudflare R2 / Backblaze B2 (using rclone):
```bash
rclone sync wp-content/uploads/ myremote:[BUCKET_NAME]/[PREFIX] --progress
```

**Why use CLI?**
Using a CLI is extremely fast, utilizes parallel threads, and guarantees that your year/month directory structures (e.g., `/2025`, `/2026`) are preserved exactly, without exhausting your PHP memory or timeout limits.

---

## 3. Cloudflare DNS & CDN Configuration

To serve your S3 files using a custom domain (e.g., `static.yblog.org`) through Cloudflare CDN, configure it as follows:

1. **Bucket Naming**: For AWS S3, your bucket name **MUST** be identical to your subdomain name (e.g., `static.yblog.org`) if CNAME routing is used directly. For Cloudflare R2, you can map any custom domain directly inside the R2 Dashboard.
2. **DNS Record in Cloudflare**:
   * **Type**: CNAME
   * **Name**: `static` (or your chosen subdomain)
   * **Target**: `[BUCKET_NAME].s3.[REGION].amazonaws.com` (or your custom provider's public domain)
   * **Proxy status**: Proxied (Orange Cloud Enabled)
3. **SSL/TLS Encryption Mode**: Set Cloudflare SSL/TLS encryption mode to **Full** or **Full (Strict)**.

**Advantages**:
Cloudflare will handle the SSL handshake and cache content at edge locations. You don't need to configure SSL certificates in AWS, and it completely hides the raw S3 URL from public visitors, enhancing security.

---

## 4. Developer Insights

Traditional media offload plugins often run into Redis object cache conflicts and PHP request timeouts when processing huge media libraries on-the-fly. 

Our recommended methodology:
1. Delegate **initial/bulk file synchronization** to the high-performance CLI utility (`aws cli` or `rclone`).
2. Let **`just-s3-offload`** handle dynamic new uploads and real-time database URL rewrites.
This hybrid approach keeps your WordPress site light, fast, and completely error-free.

---

## 5. Seamless Migration between GCS and S3

Because `just-gcs-offload` and `just-s3-offload` share a completely symmetric database metadata structure, migrating your media library from GCS to S3 (or vice versa) is incredibly straightforward:

1. **GCS Meta Structure (`_wp_gcs_info`)**: `['bucket' => ..., 'prefix' => ..., 'file' => ...]`
2. **S3 Meta Structure (`_wp_s3_info`)**: `['bucket' => ..., 'prefix' => ..., 'file' => ...]`

After syncing the physical files between your buckets using a tool like `rclone`, you only need to run a single SQL query in your WordPress database to update all metadata keys:

```sql
-- To migrate from GCS to S3:
UPDATE wp_postmeta SET meta_key = '_wp_s3_info' WHERE meta_key = '_wp_gcs_info';

-- To migrate from S3 to GCS:
UPDATE wp_postmeta SET meta_key = '_wp_gcs_info' WHERE meta_key = '_wp_s3_info';
```

Then deactivate the old plugin, activate the new one, configure the settings, and your Media Library migration is complete in seconds without any broken images!

