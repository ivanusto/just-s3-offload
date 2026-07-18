# WordPress on S3 實作與轉移指南 (使用 just-s3-offload)

[English Version Guide](GUIDE.md)

本指南提供將您的 WordPress 媒體庫 (Media Library) 遷移至 Amazon S3 或 S3 相容雲端儲存（如 Cloudflare R2、Backblaze B2、DigitalOcean Spaces、MinIO 等）的完整步驟。

本專案的主要目標是提供一個極簡、高效能的合理解決方案，避免在處理海量媒體庫（如數十 GB / 4 萬個以上檔案）時遇到 PHP 逾時與記憶體限制。

---

## 1. IAM 政策設定 (AWS S3)

為了讓 WordPress 能夠通過驗證並對 S3 儲存桶進行操作，您應該建立一個具有程式存取權限 (Access Key / Secret Key) 的 IAM 使用者，並附加一個僅限制該儲存桶權限的政策。

以下是推薦的最低權限 IAM 政策：

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
*(請將 `[BUCKET_NAME]` 替換為您實際的 S3 儲存桶名稱。如果您沒有在套件設定中啟用「設定公開 ACL」選項，可以從動作中移除 `s3:PutObjectAcl`。)*

---

## 2. 首次媒體庫轉移 (CLI 同步指令)

在啟用本外掛的網址改寫功能之前，強烈建議先將現有的本機 `uploads` 資料夾同步到 S3。**確保路徑一致性至關重要！**

在您的 WordPress 根目錄下執行以下指令：

### 針對 Amazon S3 (使用 AWS CLI)：
```bash
aws s3 sync wp-content/uploads/ s3://[BUCKET_NAME]/[PREFIX]
```
*(如果需要使用物件 ACLs 進行公開讀取，請在指令後加上 `--acl public-read`。)*

### 針對 Cloudflare R2 / Backblaze B2 (使用 rclone)：
```bash
rclone sync wp-content/uploads/ myremote:[BUCKET_NAME]/[PREFIX] --progress
```

**為什麼要使用 CLI？**
透過 CLI 工具傳輸速度極快、支援多執行緒，且能百分之百保證您本機的年/月資料夾結構（例如 `/2025`、`/2026`）被完整保留，同時絕不佔用或消耗伺服器的 PHP 記憶體與連線逾時限制。

---

## 3. Cloudflare DNS & CDN 設定

如果您希望透過 Cloudflare CDN 以自訂網域（例如 `static.yblog.org`）來提供 S3 的檔案存取，請依以下步驟設定：

1. **儲存桶命名**：針對 AWS S3，如果直接使用 CNAME 路由，您的儲存桶名稱**必須**與您的子網域名稱完全相同（例如 `static.yblog.org`）。如果是 Cloudflare R2，則可直接在 R2 控制台內綁定任何自訂網域。
2. **Cloudflare DNS 記錄**：
   * **類型 (Type)**：CNAME
   * **名稱 (Name)**：`static` (或您選擇的子網域)
   * **目標 (Target)**：`[BUCKET_NAME].s3.[REGION].amazonaws.com` (或是您自訂服務商的公開網域)
   * **代理狀態 (Proxy status)**：已代理 (開啟橘色雲朵)
3. **SSL/TLS 加密模式**：將 Cloudflare SSL/TLS 加密模式設為 **Full** 或 **Full (Strict)**。

**優點**：
Cloudflare 會處理 SSL 握手並在邊緣節點快取內容。您不需要在 AWS 設定 SSL 憑證，並能完全向公眾訪客隱藏原始 S3 網址，提升安全防護。

---

## 4. 開發者心法

傳統的媒體卸載外掛在處理海量媒體庫時，經常會與 Redis 物件快取產生衝突，或在上傳大檔時發生 PHP 請求逾時。

我們推薦的最佳實踐手法：
1. 將**首次/批次檔案同步**託付給高效能的 CLI 工具 (`aws cli` 或 `rclone`)。
2. 讓 **`just-s3-offload`** 負責處理日常新增的上傳，以及即時的資料庫網址改寫。
這種混合模式能讓您的 WordPress 網站保持輕巧、飛速，且完全免除出錯風險。
