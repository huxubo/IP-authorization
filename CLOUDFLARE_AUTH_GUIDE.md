# Cloudflare API 认证方式配置指南

## 支持两种认证方式

本项目支持 Cloudflare API 的两种认证方式：

### 方式一：Bearer Token 认证（推荐）

这是 Cloudflare 官方推荐的方式，更安全、更灵活。

#### 环境变量配置
```bash
CLOUDFLARE_API_TOKEN=your_api_token_here
CLOUDFLARE_ACCOUNT_ID=your_account_id_here
CLOUDFLARE_LIST_NAME=your_list_name_here
```

#### 代码中使用

```php
<?php
require_once 'CloudflareRulesListClient.php';
require_once 'HttpClient.php';

// 方式 1：直接使用实例
$client = new CloudflareRulesListClient(
    accountId: 'your_account_id',
    apiToken: 'your_api_token',
    listName: 'your_list_name'
);

// 方式 2：从环境变量自动创建（推荐）
$client = CloudflareRulesListClient::fromEnv();

// 使用示例
$items = $client->listItems();
print_r($items);
```

### 方式二：X-Auth-Email + X-Auth-Key 认证（旧版）

兼容 Cloudflare 传统认证方式，与企业接口保持一致。

#### 环境变量配置
```bash
CLOUDFLARE_EMAIL=your_email@example.com
CLOUDFLARE_API_KEY=your_api_key_here
CLOUDFLARE_ACCOUNT_ID=your_account_id_here
CLOUDFLARE_LIST_NAME=your_list_name_here
```

#### 代码中使用

```php
<?php
require_once 'CloudflareRulesListClient.php';
require_once 'HttpClient.php';

// 方式 1：直接使用实例
$client = new CloudflareRulesListClient(
    accountId: 'your_account_id',
    authEmail: 'your_email@example.com',
    authKey: 'your_api_key',
    listName: 'your_list_name'
);

// 方式 2：从环境变量自动创建
$client = CloudflareRulesListClient::fromEnv();

// 使用示例（与 Bearer Token 完全相同）
$items = $client->listItems();
print_r($items);
```

## 认证方式优先级

当两个方式同时配置时，程序优先使用 Bearer Token 认证。

## 完整 API 示例

```php
<?php
require_once 'CloudflareRulesListClient.php';
require_once 'HttpClient.php';

$client = CloudflareRulesListClient::fromEnv();

// 获取列表
$lists = $client->getLists();
print_r($lists);

// 添加 IP
try {
    $client->addItem('192.168.1.100', '测试服务器');
    echo "IP 添加成功";
} catch (Exception $e) {
    echo "添加失败: " . $e->getMessage();
}

// 查找 IP
$item = $client->findItemByIp('192.168.1.100');
if ($item) {
    echo "找到 IP: " . $item['ip'] . " - " . $item['comment'];
}

// 删除 IP
$client->deleteItemByIp('192.168.1.100');
echo "IP 删除成功";
```

## 错误处理

两种认证方式的错误处理完全一致：

```php
try {
    $items = $client->listItems();
} catch (RuntimeException $e) {
    // 认证失败、列表不存在等错误
    echo "错误: " . $e->getMessage();
}
```

## 企业集成指导

### 从旧版迁移到 Bearer Token

如果你的现有代码使用 X-Auth-Email + X-Auth-Key 方式：

1. **无需修改代码**：现有代码直接使用，只需替换环境变量
2. **获取 API Token**：在 Cloudflare 控制台 -> API Tokens -> Create Token
3. **配置权限**：选择 "Edit lists" 权限
4. **替换环境变量**：
   ```bash
   # 移除旧的
   # CLOUDFLARE_EMAIL=...
   # CLOUDFLARE_API_KEY=...
   
   # 添加新的
   CLOUDFLARE_API_TOKEN=your_new_token
   ```

### 安全最佳实践

1. **推荐使用 Bearer Token**：更细粒度的权限控制
2. **使用环境变量**：避免在代码中硬编码密钥
3. **定期轮换密钥**：每 90 天更换一次 API Token
4. **最小权限原则**：只授予必要的权限

## 参考链接

- [Cloudflare API Token 文档](https://developers.cloudflare.com/fundamentals/api/get-started/create-token/)
- [Cloudflare API Keys 文档](https://developers.cloudflare.com/fundamentals/api/get-started/keys/)