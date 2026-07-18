# Just S3 Offload

[English Version README](README.md) | [WordPress on S3 Implementation Guide](GUIDE.md) | [WordPress on S3 中文實作指南](GUIDE.zh-TW.md)

一個輕量、無外部依賴的 WordPress 擴充套件，用於將您的媒體庫 (Media Library) 卸載 (Offload) 到 Amazon S3 或 S3 相容的雲端儲存空間（如 Cloudflare R2、Backblaze B2、DigitalOcean Spaces、MinIO 等）。

> [!NOTE]
> 需要 Google Cloud Storage (GCS) 支援嗎？請參考我們的姊妹專案：[Just GCS Offload](https://github.com/ivanusto/just-gcs-offload)。

與其他臃腫的雲端儲存外掛不同，**Just S3 Offload** 的設計宗旨是盡可能小巧高效。它使用 WordPress HTTP API 以及純 PHP 原生加密函式 (`hash_hmac`) 來實現 AWS Signature Version 4 (SigV4) 簽章，完全繞過了龐大的官方 AWS SDK。

## 功能特點

* **零外部依賴**：大小僅數十 KB，無臃腫的 `vendor/` 目錄或外部函式庫。
* **AWS Signature Version 4 (SigV4)**：使用 PHP 原生加密函式，安全地在純 PHP 中計算請求簽章。
* **S3 相容儲存支援**：開箱即用支援自訂 S3 端點 (Endpoint)（Cloudflare R2、MinIO、Backblaze B2、DigitalOcean Spaces 等）與路徑式 (Path-Style) 網址。
* **自動媒體卸載**：在上傳媒體時，自動將原圖和所有產生的縮圖（thumbnails）上傳至 S3。
* **網址與 Srcset 改寫**：無縫改寫圖片 URL 以及響應式 `srcset` 路徑，指向 S3 或自訂的 CDN 網域。
* **選用本機清理**：可選擇在成功上傳至 S3 後，刪除伺服器本機的檔案複本以節省磁碟空間。
* **自動刪除**：在 WordPress 後台永久刪除媒體附件時，自動從 S3 刪除原始檔案與所有尺寸的圖片。
* **WP-CLI 整合**：提供強大的命令列工具，可批次轉移現有媒體庫項目並同步資料庫中的卸載標記。
* **連線測試**：設定頁面提供簡單的按鈕，用於測試讀取/寫入/刪除權限。

## 系統需求

* PHP 7.4 或更高版本
* 已啟用 PHP Hash 擴充功能
* WordPress 5.0 或更高版本

## 安裝步驟

1. 從 [Releases](https://github.com/ivanusto/just-s3-offload/releases) 頁面下載最新的 `just-s3-offload.zip`。
2. 在 WordPress 後台，前往 **外掛 -> 安裝外掛 -> 上傳外掛**，選擇該 zip 檔案，然後點擊 **立即安裝**。
3. 啟用此外掛。

## S3 儲存桶 (Bucket) 設定

為確保您卸載的圖片能被公開讀取，請使用以下方法之一設定 S3 儲存桶：

### 方法 A：透過儲存桶政策 (Bucket Policy) 公開存取（推薦）
1. 保持儲存桶的預設 ACL 為私有 (Private)，若要直接讀取檔案，請關閉封鎖公開存取設定。
2. 設定儲存桶政策 (Bucket Policy)，允許 `GetObject` 權限給 `*` 或特定來源，或者將儲存桶對接到 Cloudflare 等 CDN 進行公開分發。
3. 在 WordPress 設定中，保持 **「設定公開 ACL」** 選項為**停用**狀態。

### 方法 B：物件 ACLs (細粒度權限控制)
1. 在您的儲存桶設定中，確保已啟用 ACL（例如啟用物件寫入者擁有權或儲存桶擁有者慣用）。
2. 在 WordPress 設定中，啟用 **「設定公開 ACL」** 選項。這會為每個上傳的物件套用 `public-read` ACL 標頭。

## 設定選項

在 WordPress 控制台中前往 **設定 -> S3 Offload** 來設定以下選項：

* **Access Key ID**：您的 S3 帳號存取金鑰。
* **Secret Access Key**：您的 S3 帳號私密金鑰。
* **S3 Region**：儲存桶所在的區域（例如 `us-east-1`、`ap-northeast-1`）。
* **S3 Bucket Name**：目標 S3 儲存桶名稱（例如 `my-wordpress-bucket`）。
* **Custom Endpoint URL**：（選填）針對 S3 相容的雲端儲存，請輸入自訂端點（例如 `https://<accountid>.r2.cloudflarestorage.com` 或 `https://s3.us-west-004.backblazeb2.com`）。
* **Use Path-Style URLs**：如果您的 S3 相容儲存需要路徑格式的網址，請勾選此項（例如 `endpoint/bucket/file` 而非 `bucket.endpoint/file`）。
* **Folder Path Prefix**：（選填）儲存桶內部的資料夾路徑前綴（例如 `wp-content/uploads`）。請勿在開頭或結尾加上斜線。
* **Custom Domain / CDN URL**：（選填）自訂網域或 CDN 對照網址（例如 `https://cdn.example.com`）。如果留空，將使用預設的 S3 網址結構。
* **Cache-Control Header**：套用到上傳物件的 Cache-Control 標頭（預設為 `public, max-age=31536000`）。
* **Set Public ACL**：勾選此項以將上傳物件的 ACL 設為 public-read。
* **Delete Local Files**：勾選此項可在成功上傳到 S3 後刪除本機的檔案複本。*注意：刪除本機檔案可能會導致 WordPress 內建的圖片編輯器（裁剪/旋轉）功能失效。*

點擊 **「執行連線測試」** 即可驗證您的憑證與權限是否設定正確。

## WP-CLI 命令

針對開發者與系統管理員，本外掛提供了自訂的 WP-CLI 指令來進行批次操作與轉移。

### 1. 同步資料庫元數據 (Metadata)
如果您已經使用 `aws s3 sync` 或 `rclone` 等工具將檔案複製到 S3 儲存桶，可以使用此指令在資料庫中產生卸載元數據（`_wp_s3_info`），以便 WordPress 開始重寫 URL。
```bash
wp s3-offload sync-metadata [--bucket=<bucket>] [--prefix=<prefix>] [--overwrite]
```

### 2. 批次上傳本機檔案
掃描所有現有的媒體庫附件，將其上傳到 S3，並更新其資料庫記錄。
```bash
wp s3-offload sync-all [--delete-local] [--overwrite]
```
* 使用 `--delete-local` 在成功上傳後移除本機複本。
* 使用 `--overwrite` 重新上傳已被標記為同步的檔案。

## 疑難排解

如果您遇到任何問題（例如後台媒體庫出現白畫面或嚴重錯誤）：
1. 在您的 `wp-config.php` 中啟用除錯模式：
   ```php
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   define( 'WP_DEBUG_DISPLAY', false );
   ```
2. 重新觸發錯誤。
3. 檢查記錄檔：
   * WordPress 除錯記錄：`wp-content/debug.log`
   * 網頁伺服器錯誤記錄：`/var/log/apache2/error.log` 或 `/var/log/nginx/error.log`

## 授權條款

本專案採用 MIT 授權條款。
