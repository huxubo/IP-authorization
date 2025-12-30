# IP Authorization Admin Console

一个基于 PHP 的管理后台，用于管理 IP 授权白名单。支持本地 SQLite 存储，并可与 Cloudflare Rules Lists（IP 列表）同步。

## 功能特性

- 管理允许的 IP 地址（支持 IPv4/IPv6 及 CIDR）。
- **Cloudflare 同步**：自动将本地白名单更改同步到 Cloudflare。
- 管理员身份验证：支持密码和验证码登录。
- 响应式 UI：基于 Tailwind CSS 构建。
- 会话管理：支持自动超时和安全控制。

## 安装设置

1. 将代码部署到支持 PHP 的服务器。
2. 确保已启用 `GD` 扩展（验证码功能需要）。
3. 确保已启用 `PDO_SQLITE` 扩展。
4. 确保目录具有写权限，以便创建 `SQLite.db` 数据库文件。

## Cloudflare 同步配置

此功能可将本地 IP 白名单同步到 Cloudflare 的“IP 列表”（位于 WAF > 工具 > IP 列表）。

### 环境变量配置

在服务器上设置以下环境变量：

- `CLOUDFLARE_API_TOKEN`: 您的 Cloudflare API 令牌（需具有读取和修改账号规则列表的权限）。
- `CLOUDFLARE_ACCOUNT_ID`: 您的 Cloudflare 账号 ID。
- `CLOUDFLARE_LIST_ID` (可选): 要同步的特定列表 ID。
- `CLOUDFLARE_LIST_NAME` (可选): 要同步的列表名称（若未提供 `CLOUDFLARE_LIST_ID` 则使用此项）。

**注意**：如果既未提供 `CLOUDFLARE_LIST_ID` 也未提供 `CLOUDFLARE_LIST_NAME`，系统将默认使用账号下的第一个规则列表。

### 工作原理

1. **初始同步**：首次运行且本地数据库为空时，系统会尝试从 Cloudflare 获取现有 IP 并填充本地数据库。
2. **实时同步**：在管理后台添加、编辑或删除 IP 时，更改会立即同步到 Cloudflare。
3. **冲突处理**：如果添加的 IP 在 Cloudflare 中已存在，其注释（Description）将被更新。

## 使用说明

1. 在浏览器中访问 `login.php`。
2. 使用默认密码登录：`admin123`。
3. 通过仪表盘管理 IP。
4. **建议**：首次登录后立即修改管理员密码。

---

# IP Authorization Admin Console (English)

A PHP-based admin console for managing an IP authorization whitelist. It supports local storage in SQLite and synchronization with Cloudflare Rules Lists.

## Features

- Manage allowed IP addresses (IPv4/IPv6 and CIDR).
- **Cloudflare Synchronization**: Automatically sync local whitelist changes to Cloudflare.
- Admin authentication with password and captcha.
- Responsive Tailwind UI.
- Session-based access control.

## Setup

1.  **Deploy to a PHP-enabled server.**
2.  **Ensure the `GD` extension is enabled** (required for captcha).
3.  **Ensure `PDO_SQLITE` is enabled.**
4.  **Set write permissions** for the directory to allow creating `SQLite.db`.

## Cloudflare Synchronization

This feature allows you to synchronize your local IP whitelist with a Cloudflare "IP List" (found in WAF > Tools > IP Lists).

### Configuration

Set the following environment variables on your server:

- `CLOUDFLARE_API_TOKEN`: Your Cloudflare API Token (must have permission to read/write Account Rules Lists).
- `CLOUDFLARE_ACCOUNT_ID`: Your Cloudflare Account ID.
- `CLOUDFLARE_LIST_ID` (Optional): The ID of the specific list to sync with.
- `CLOUDFLARE_LIST_NAME` (Optional): The name of the list to sync with (used if `CLOUDFLARE_LIST_ID` is not provided).

**Note:** If neither `CLOUDFLARE_LIST_ID` nor `CLOUDFLARE_LIST_NAME` is provided, the system will use the first rules list found in your Cloudflare account.

### How it works

1.  **Initial Sync:** When you first run the application and the local database is empty, it will attempt to fetch existing IPs from the configured Cloudflare list and populate the local database.
2.  **Real-time Updates:** Whenever you add, update, or remove an IP through the admin console, the change is immediately pushed to the Cloudflare API.
3.  **Conflict Handling:** If an IP added to the local console already exists in Cloudflare, its comment will be updated.

## Usage

1.  Access `login.php` in your browser.
2.  Log in with the default password: `admin123`.
3.  Manage your IPs through the dashboard.
4.  **Important:** Change the admin password immediately after first login.
