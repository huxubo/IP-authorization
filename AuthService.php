<?php

require_once __DIR__ . '/CloudflareRulesListClient.php';

interface AuthInterface
{
    public function isIpAllowed(string $ip): bool;
    public function addAllowedIp(string $ip, string $description = ''): bool;
    public function removeAllowedIp(string $ip): bool;
    public function getAllowedIps(): array;
    public function saveAllowedIps(): bool;
}

class AuthService implements AuthInterface
{
    private PDO $pdo;
    private array $allowedIps = [];
    private ?CloudflareRulesListClient $cloudflareClient = null;

    public function __construct(string $dbFile)
    {
        $dir = dirname($dbFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->initSchema();
        $this->cloudflareClient = CloudflareRulesListClient::fromEnv();
        $this->loadAllowedIps();
    }

    /* ================= 初始化 ================= */

    private function initSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS allowed_ips (
                ip TEXT PRIMARY KEY,
                description TEXT,
                created_at TEXT,
                updated_at TEXT
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS config (
                key TEXT PRIMARY KEY,
                value TEXT
            )
        ");

        // 初始化默认配置
        if ($this->getConfig('admin_password') === null) {
            $this->setConfig('admin_password', password_hash('admin123', PASSWORD_DEFAULT));
        }

        if ($this->getConfig('settings') === null) {
            $this->setConfig('settings', json_encode([
                'session_timeout' => 86400,
                'default_per_page' => 10
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /* ================= IP 白名单 ================= */

    public function isIpAllowed(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach ($this->allowedIps as $entry) {
            if ($this->matchIp($ip, $entry['ip'])) {
                return true;
            }
        }
        return false;
    }

    public function addAllowedIp(string $ip, string $description = ''): bool
    {
        if (!$this->validateIpFormat($ip)) {
            throw new InvalidArgumentException("Invalid IP format: {$ip}");
        }

        if ($this->ipEntryExists($ip)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO allowed_ips (ip, description, created_at, updated_at)
                VALUES (:ip, :d, :c, :u)
            ");

            $ok = $stmt->execute([
                ':ip' => $ip,
                ':d'  => $description,
                ':c'  => $now,
                ':u'  => $now
            ]);

            if (!$ok) {
                $this->pdo->rollBack();
                return false;
            }

            if ($this->cloudflareClient !== null) {
                $this->cloudflareClient->upsertItem($ip, $description);
            }

            $this->pdo->commit();
            $this->loadAllowedIps();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function removeAllowedIp(string $ip): bool
    {
        if (!$this->ipEntryExists($ip)) {
            return false;
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("DELETE FROM allowed_ips WHERE ip = :ip");
            $stmt->execute([':ip' => $ip]);

            if ($stmt->rowCount() === 0) {
                $this->pdo->rollBack();
                return false;
            }

            if ($this->cloudflareClient !== null) {
                $this->cloudflareClient->deleteItemByIp($ip);
            }

            $this->pdo->commit();
            $this->loadAllowedIps();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getAllowedIps(): array
    {
        return $this->allowedIps;
    }

    public function saveAllowedIps(): bool
    {
        // SQLite 即时写入，保持接口但无需实际操作
        return true;
    }

    public function updateIpEntry(string $ip, array $data): bool
    {
        if (!array_key_exists('description', $data)) {
            return false;
        }

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                "UPDATE allowed_ips 
                 SET description = :description, updated_at = :updated_at 
                 WHERE ip = :ip"
            );

            $result = $stmt->execute([
                ':description' => $data['description'],
                ':updated_at'  => date('Y-m-d H:i:s'),
                ':ip'          => $ip
            ]);

            if (!$result || $stmt->rowCount() === 0) {
                $this->pdo->rollBack();
                return false;
            }

            if ($this->cloudflareClient !== null) {
                $this->cloudflareClient->updateItemComment($ip, $data['description']);
            }

            $this->pdo->commit();
            $this->loadAllowedIps();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function renameAllowedIp(string $originalIp, string $newIp, string $description = ''): bool
    {
        if (!$this->validateIpFormat($newIp)) {
            throw new InvalidArgumentException("Invalid IP format: {$newIp}");
        }

        if (!$this->ipEntryExists($originalIp)) {
            return false;
        }

        if ($originalIp !== $newIp && $this->ipEntryExists($newIp)) {
            return false;
        }

        $existing = null;
        foreach ($this->allowedIps as $entry) {
            if (($entry['ip'] ?? '') === $originalIp) {
                $existing = $entry;
                break;
            }
        }

        $createdAt = $existing['created_at'] ?? date('Y-m-d H:i:s');
        $updatedAt = date('Y-m-d H:i:s');
        $newItemExisted = false;

        if ($this->cloudflareClient !== null) {
            $newItemExisted = $this->cloudflareClient->findItemByIp($newIp) !== null;

            try {
                $this->cloudflareClient->upsertItem($newIp, $description);
                if ($originalIp !== $newIp) {
                    $this->cloudflareClient->deleteItemByIp($originalIp);
                }
            } catch (Throwable $e) {
                if ($originalIp !== $newIp && !$newItemExisted) {
                    try {
                        $this->cloudflareClient->deleteItemByIp($newIp);
                    } catch (Throwable $ignored) {
                    }
                }

                throw $e;
            }
        }

        $this->pdo->beginTransaction();

        try {
            if ($originalIp === $newIp) {
                $stmt = $this->pdo->prepare(
                    "UPDATE allowed_ips 
                     SET description = :description, updated_at = :updated_at 
                     WHERE ip = :ip"
                );

                $ok = $stmt->execute([
                    ':description' => $description,
                    ':updated_at'  => $updatedAt,
                    ':ip'          => $originalIp
                ]);

                if (!$ok || $stmt->rowCount() === 0) {
                    $this->pdo->rollBack();
                    return false;
                }
            } else {
                $deleteStmt = $this->pdo->prepare("DELETE FROM allowed_ips WHERE ip = :ip");
                $deleteStmt->execute([':ip' => $originalIp]);

                if ($deleteStmt->rowCount() === 0) {
                    $this->pdo->rollBack();

                    if ($this->cloudflareClient !== null) {
                        try {
                            if (!$newItemExisted) {
                                $this->cloudflareClient->deleteItemByIp($newIp);
                            }
                            $this->cloudflareClient->upsertItem($originalIp, $existing['description'] ?? '');
                        } catch (Throwable $ignored) {
                        }
                    }

                    return false;
                }

                $insertStmt = $this->pdo->prepare(
                    "INSERT INTO allowed_ips (ip, description, created_at, updated_at)
                     VALUES (:ip, :d, :c, :u)"
                );

                $ok = $insertStmt->execute([
                    ':ip' => $newIp,
                    ':d'  => $description,
                    ':c'  => $createdAt,
                    ':u'  => $updatedAt
                ]);

                if (!$ok) {
                    $this->pdo->rollBack();

                    if ($this->cloudflareClient !== null) {
                        try {
                            if (!$newItemExisted) {
                                $this->cloudflareClient->deleteItemByIp($newIp);
                            }
                            $this->cloudflareClient->upsertItem($originalIp, $existing['description'] ?? '');
                        } catch (Throwable $ignored) {
                        }
                    }

                    return false;
                }
            }

            $this->pdo->commit();
            $this->loadAllowedIps();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            if ($this->cloudflareClient !== null && $originalIp !== $newIp) {
                try {
                    if (!$newItemExisted) {
                        $this->cloudflareClient->deleteItemByIp($newIp);
                    }
                    $this->cloudflareClient->upsertItem($originalIp, $existing['description'] ?? '');
                } catch (Throwable $rollbackError) {
                }
            }

            throw $e;
        }
    }

    private function loadAllowedIps(): void
    {
        $this->allowedIps = $this->pdo
            ->query("SELECT * FROM allowed_ips ORDER BY created_at ASC")
            ->fetchAll();

        if (!empty($this->allowedIps)) {
            return;
        }

        $this->initializeFromCloudflare();
        $this->allowedIps = $this->pdo
            ->query("SELECT * FROM allowed_ips ORDER BY created_at ASC")
            ->fetchAll();

        if (!empty($this->allowedIps)) {
            return;
        }

        $this->initializeDefaultIps();
        $this->allowedIps = $this->pdo
            ->query("SELECT * FROM allowed_ips ORDER BY created_at ASC")
            ->fetchAll();
    }

    private function insertAllowedIpRow(string $ip, string $description = '', ?string $createdAt = null, ?string $updatedAt = null): bool
    {
        if (!$this->validateIpFormat($ip)) {
            return false;
        }

        $createdAt = $createdAt ?? date('Y-m-d H:i:s');
        $updatedAt = $updatedAt ?? $createdAt;

        $stmt = $this->pdo->prepare(
            "INSERT OR IGNORE INTO allowed_ips (ip, description, created_at, updated_at)
             VALUES (:ip, :d, :c, :u)"
        );

        return $stmt->execute([
            ':ip' => $ip,
            ':d'  => $description,
            ':c'  => $createdAt,
            ':u'  => $updatedAt,
        ]);
    }

    private function initializeFromCloudflare(): void
    {
        if ($this->cloudflareClient === null) {
            return;
        }

        try {
            $items = $this->cloudflareClient->listItems(false);
        } catch (Throwable $e) {
            return;
        }

        if (empty($items) || !is_array($items)) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        foreach ($items as $item) {
            $ip = $item['ip'] ?? ($item['value'] ?? null);
            if (!is_string($ip) || $ip === '') {
                continue;
            }

            $comment = $item['comment'] ?? '';
            if (!is_string($comment)) {
                $comment = '';
            }

            $this->insertAllowedIpRow($ip, $comment, $now, $now);
        }
    }

    private function initializeDefaultIps(): void
    {
        $defaults = [
            ['127.0.0.1', '本地回环地址'],
            ['::1', 'IPv6本地回环地址'],
        ];

        foreach ($defaults as [$ip, $desc]) {
            $this->insertAllowedIpRow($ip, $desc);
        }
    }

    /* ================= 配置（原 JSON config） ================= */

    private function getConfig(string $key): ?string
    {
        $stmt = $this->pdo->prepare("SELECT value FROM config WHERE key = :k");
        $stmt->execute([':k' => $key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : null;
    }

    private function setConfig(string $key, string $value): void
    {
        // 先尝试更新
        $updateStmt = $this->pdo->prepare("UPDATE config SET value = :v WHERE key = :k");
        $updateStmt->execute([':k' => $key, ':v' => $value]);
        
        // 如果没有更新成功，则插入
        if ($updateStmt->rowCount() === 0) {
            $insertStmt = $this->pdo->prepare("INSERT INTO config (key, value) VALUES (:k, :v)");
            $insertStmt->execute([':k' => $key, ':v' => $value]);
        }
    }

    public function getAdminPassword(): string
    {
        $password = $this->getConfig('admin_password');
        return $password ?? password_hash('admin123', PASSWORD_DEFAULT);
    }

    public function updateAdminPassword(string $newPassword): bool
    {
        $this->setConfig('admin_password', password_hash($newPassword, PASSWORD_DEFAULT));
        return true;
    }

    public function getSessionTimeout(): int
    {
        $settings = json_decode($this->getConfig('settings') ?? '{}', true);
        $timeout = (int)($settings['session_timeout'] ?? 1800);
        return max(300, min(86400, $timeout));
    }

    public function getDefaultPerPage(): int
    {
        $settings = json_decode($this->getConfig('settings') ?? '{}', true);
        $perPage = (int)($settings['default_per_page'] ?? 10);
        return max(1, min(100, $perPage));
    }

    /* ================= 你原来的工具方法（未改） ================= */

    private function ipEntryExists(string $ip): bool
    {
        foreach ($this->allowedIps as $e) {
            if ($e['ip'] === $ip) {
                return true;
            }
        }
        return false;
    }

    private function matchIp(string $ip, string $allowedIp): bool
    {
        if (strpos($allowedIp, '/') === false) {
            return $ip === $allowedIp;
        }

        [$subnet, $mask] = explode('/', $allowedIp);
        $mask = (int)$mask;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->matchIPv4($ip, $subnet, $mask);
        }
        return $this->matchIPv6($ip, $subnet, $mask);
    }

    private function matchIPv4(string $ip, string $subnet, int $mask): bool
    {
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        
        $maskLong = -1 << (32 - $mask);
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    private function matchIPv6(string $ip, string $subnet, int $mask): bool
    {
        $ipBinary = inet_pton($ip);
        $subnetBinary = inet_pton($subnet);
        $maskBinary = $this->createIPv6Mask($mask);
        
        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }
        
        for ($i = 0; $i < 16; $i++) {
            $ipByte = ord($ipBinary[$i]) & ord($maskBinary[$i]);
            $subnetByte = ord($subnetBinary[$i]) & ord($maskBinary[$i]);
            if ($ipByte !== $subnetByte) {
                return false;
            }
        }
        return true;
    }

    private function createIPv6Mask(int $mask): string
    {
        $binary = '';
        for ($i = 0; $i < 16; $i++) {
            if ($mask >= 8) {
                $binary .= chr(255);
                $mask -= 8;
            } elseif ($mask > 0) {
                $binary .= chr(bindec(str_pad(str_repeat('1', $mask), 8, '0')));
                $mask = 0;
            } else {
                $binary .= chr(0);
            }
        }
        return $binary;
    }

    private function validateIpFormat(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (strpos($ip, '/') !== false) {
            [$subnet, $mask] = explode('/', $ip, 2);
            if (!filter_var($subnet, FILTER_VALIDATE_IP)) {
                return false;
            }
            $max = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;
            return is_numeric($mask) && $mask >= 0 && $mask <= $max;
        }
        return false;
    }
}
