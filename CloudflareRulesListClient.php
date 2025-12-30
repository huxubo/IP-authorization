<?php

require_once __DIR__ . '/HttpClient.php';

class CloudflareRulesListClient
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    private HttpClient $httpClient;
    private string $apiToken;
    private string $accountId;
    private ?string $listId;
    private ?string $listName;

    private ?array $itemsCache = null;

    public function __construct(
        string $apiToken,
        string $accountId,
        ?string $listId = null,
        ?string $listName = null,
        ?HttpClient $httpClient = null
    ) {
        $this->apiToken = $apiToken;
        $this->accountId = $accountId;
        $this->listId = $listId;
        $this->listName = $listName;
        $this->httpClient = $httpClient ?? new HttpClient(15, 3);
    }

    public static function fromEnv(?HttpClient $httpClient = null): ?self
    {
        $token = getenv('CLOUDFLARE_API_TOKEN') ?: '';
        $accountId = getenv('CLOUDFLARE_ACCOUNT_ID') ?: '';

        if ($token === '' || $accountId === '') {
            return null;
        }

        $listId = getenv('CLOUDFLARE_LIST_ID') ?: null;
        $listName = getenv('CLOUDFLARE_LIST_NAME') ?: null;

        return new self($token, $accountId, $listId, $listName, $httpClient);
    }

    private function headers(): array
    {
        return [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    private function buildUrl(string $path, array $query = []): string
    {
        $url = rtrim(self::API_BASE, '/') . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    private function decodeResponse(string $response): array
    {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException('Cloudflare API returned invalid JSON');
        }

        if (($data['success'] ?? false) !== true) {
            $errors = $data['errors'] ?? [];
            $msg = 'Cloudflare API request failed';
            if (is_array($errors) && !empty($errors)) {
                $first = $errors[0];
                if (is_array($first) && isset($first['message'])) {
                    $msg .= ': ' . $first['message'];
                }
            }
            throw new RuntimeException($msg);
        }

        return $data;
    }

    private function request(string $method, string $path, ?array $query = null, $body = null): array
    {
        $url = $this->buildUrl($path, $query ?? []);
        $headers = $this->headers();

        $payload = null;
        if ($body !== null) {
            $payload = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        if ($method === 'GET') {
            $response = $this->httpClient->get($url, $headers);
        } elseif ($method === 'POST') {
            $response = $this->httpClient->post($url, $payload, $headers);
        } elseif ($method === 'PUT') {
            $response = $this->httpClient->put($url, $payload, $headers);
        } elseif ($method === 'PATCH') {
            $response = $this->httpClient->patch($url, $payload, $headers);
        } elseif ($method === 'DELETE') {
            $response = $this->httpClient->send($url, 'DELETE', $payload, $headers);
        } else {
            $response = $this->httpClient->send($url, $method, $payload, $headers);
        }

        return $this->decodeResponse($response);
    }

    private function ensureListId(): string
    {
        if (!empty($this->listId)) {
            return $this->listId;
        }

        $lists = $this->getLists();
        if (empty($lists)) {
            throw new RuntimeException('Cloudflare account has no rules lists');
        }

        if (!empty($this->listName)) {
            foreach ($lists as $list) {
                if (($list['name'] ?? '') === $this->listName && isset($list['id'])) {
                    $this->listId = $list['id'];
                    return $this->listId;
                }
            }
            throw new RuntimeException('Cloudflare rules list not found by name: ' . $this->listName);
        }

        if (isset($lists[0]['id'])) {
            $this->listId = $lists[0]['id'];
            return $this->listId;
        }

        throw new RuntimeException('Unable to resolve Cloudflare list id');
    }

    public function getLists(): array
    {
        $path = sprintf('accounts/%s/rules/lists', $this->accountId);
        $data = $this->request('GET', $path);
        return $data['result'] ?? [];
    }

    public function listItems(bool $useCache = true): array
    {
        if ($useCache && $this->itemsCache !== null) {
            return $this->itemsCache;
        }

        $listId = $this->ensureListId();
        $path = sprintf('accounts/%s/rules/lists/%s/items', $this->accountId, $listId);

        $all = [];
        $cursor = null;

        while (true) {
            $query = [
                'per_page' => 1000,
            ];
            if (!empty($cursor)) {
                $query['cursor'] = $cursor;
            }

            $data = $this->request('GET', $path, $query);
            $items = $data['result'] ?? [];
            if (is_array($items)) {
                $all = array_merge($all, $items);
            }

            $after = $data['result_info']['cursors']['after'] ?? null;
            if (empty($after)) {
                break;
            }
            $cursor = $after;
        }

        $this->itemsCache = $all;
        return $all;
    }

    public function findItemByIp(string $ip): ?array
    {
        $items = $this->listItems();
        foreach ($items as $item) {
            $value = $item['ip'] ?? ($item['value'] ?? null);
            if ($value === $ip) {
                return $item;
            }
        }
        return null;
    }

    public function upsertItem(string $ip, string $comment = ''): void
    {
        $item = $this->findItemByIp($ip);
        if ($item === null) {
            $this->addItem($ip, $comment);
            return;
        }

        $this->updateItemComment($ip, $comment);
    }

    public function addItem(string $ip, string $comment = ''): void
    {
        $listId = $this->ensureListId();
        $path = sprintf('accounts/%s/rules/lists/%s/items', $this->accountId, $listId);

        $this->request('POST', $path, null, [
            'items' => [
                [
                    'ip' => $ip,
                    'comment' => $comment,
                ]
            ]
        ]);

        $this->itemsCache = null;
    }

    public function deleteItemByIp(string $ip): void
    {
        $item = $this->findItemByIp($ip);
        if ($item === null) {
            return;
        }

        $id = $item['id'] ?? null;
        if (empty($id)) {
            return;
        }

        $listId = $this->ensureListId();
        $path = sprintf('accounts/%s/rules/lists/%s/items', $this->accountId, $listId);

        $this->request('DELETE', $path, null, [
            'items' => [
                ['id' => $id]
            ]
        ]);

        $this->itemsCache = null;
    }

    public function updateItemComment(string $ip, string $comment = ''): void
    {
        $item = $this->findItemByIp($ip);
        if ($item === null) {
            $this->addItem($ip, $comment);
            return;
        }

        $id = $item['id'] ?? null;
        if (empty($id)) {
            return;
        }

        $listId = $this->ensureListId();
        $path = sprintf('accounts/%s/rules/lists/%s/items', $this->accountId, $listId);

        $this->request('PUT', $path, null, [
            'items' => [
                [
                    'id' => $id,
                    'comment' => $comment,
                ]
            ]
        ]);

        $this->itemsCache = null;
    }
}
