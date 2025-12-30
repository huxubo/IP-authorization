<?php

interface HttpClientInterface
{
    public function get(string $url, array $headers = []): string;
    public function post(string $url, $data = null, array $headers = []): string;
    public function put(string $url, $data = null, array $headers = []): string;
    public function patch(string $url, $data = null, array $headers = []): string;
    public function delete(string $url, array $headers = []): string;
    public function head(string $url, array $headers = []): array;
    public function options(string $url, array $headers = []): string;
}

class HttpClient implements HttpClientInterface
{
    private $timeout;
    private $maxRedirects;
    private $networkInterface = null;
    private $ipVersion = null; // 4, 6, or null for auto
    private $curlOptions = [];

    public function __construct(int $timeout = 10, int $maxRedirects = 5)
    {
        $this->timeout = $timeout;
        $this->maxRedirects = $maxRedirects;
    }

    /**
     * 设置使用的网络接口
     * @param string $interface 网卡名称或IP地址 (如: "eth0", "192.168.1.100")
     * @return self
     */
    public function setNetworkInterface(string $interface): self
    {
        $this->networkInterface = $interface;
        return $this;
    }

    /**
     * 设置IP协议版本
     * @param int|null $version 4=IPv4, 6=IPv6, null=自动
     * @return self
     */
    public function setIpVersion(?int $version): self
    {
        if (!in_array($version, [4, 6, null])) {
            throw new InvalidArgumentException("IP version must be 4, 6, or null");
        }
        $this->ipVersion = $version;
        return $this;
    }

    /**
     * 设置自定义cURL选项
     * @param array $options
     * @return self
     */
    public function setCurlOptions(array $options): self
    {
        $this->curlOptions = $options;
        return $this;
    }

    /**
     * 添加自定义cURL选项
     * @param int $option
     * @param mixed $value
     * @return self
     */
    public function addCurlOption(int $option, $value): self
    {
        $this->curlOptions[$option] = $value;
        return $this;
    }

    public function get(string $url, array $headers = []): string
    {
        return $this->request($url, 'GET', null, $headers);
    }

    public function post(string $url, $data = null, array $headers = []): string
    {
        return $this->request($url, 'POST', $data, $headers);
    }

    public function put(string $url, $data = null, array $headers = []): string
    {
        return $this->request($url, 'PUT', $data, $headers);
    }

    public function patch(string $url, $data = null, array $headers = []): string
    {
        return $this->request($url, 'PATCH', $data, $headers);
    }

    public function delete(string $url, array $headers = []): string
    {
        return $this->request($url, 'DELETE', null, $headers);
    }

    public function head(string $url, array $headers = []): array
    {
        $ch = curl_init();
        
        $options = $this->getBaseOptions();
        $options[CURLOPT_URL] = $url;
        $options[CURLOPT_CUSTOMREQUEST] = 'HEAD';
        $options[CURLOPT_NOBODY] = true;
        $options[CURLOPT_HEADER] = true;
        $options[CURLOPT_HTTPHEADER] = $this->prepareHeaders($headers);
        
        curl_setopt_array($ch, $options);
        
        $response = curl_exec($ch);
        
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP HEAD request failed: " . $error);
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        
        curl_close($ch);
        
        return [
            'status_code' => $httpCode,
            'headers' => $this->parseHeaders($headers)
        ];
    }

    public function options(string $url, array $headers = []): string
    {
        return $this->request($url, 'OPTIONS', null, $headers);
    }

    /**
     * 发送JSON格式的POST请求
     */
    public function postJson(string $url, array $data, array $headers = []): string
    {
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        $headers = array_merge([
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData)
        ], $headers);
        
        return $this->post($url, $jsonData, $headers);
    }

    /**
     * 发送JSON格式的PUT请求
     */
    public function putJson(string $url, array $data, array $headers = []): string
    {
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        $headers = array_merge([
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData)
        ], $headers);
        
        return $this->put($url, $jsonData, $headers);
    }

    /**
     * 发送表单格式的POST请求
     */
    public function postForm(string $url, array $data, array $headers = []): string
    {
        $formData = http_build_query($data);
        $headers = array_merge([
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: ' . strlen($formData)
        ], $headers);
        
        return $this->post($url, $formData, $headers);
    }

    /**
     * 发送multipart/form-data格式的POST请求
     */
    public function postMultipart(string $url, array $data, array $headers = []): string
    {
        // 移除默认的Content-Type，让cURL自动设置
        $filteredHeaders = [];
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') === false) {
                $filteredHeaders[] = $header;
            }
        }
        
        return $this->post($url, $data, $filteredHeaders);
    }

    /**
     * 发送异步请求
     */
    public function requestAsync(string $url, string $method = 'GET', $data = null, array $headers = [], callable $callback = null): void
    {
        $ch = curl_init();
        
        $options = $this->getBaseOptions();
        $options[CURLOPT_URL] = $url;
        $options[CURLOPT_CUSTOMREQUEST] = $method;
        $options[CURLOPT_HTTPHEADER] = $this->prepareHeaders($headers);
        
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            if ($data !== null) {
                $options[CURLOPT_POSTFIELDS] = $data;
            }
        }
        
        // 设置为非阻塞
        $options[CURLOPT_TIMEOUT_MS] = 1;
        $options[CURLOPT_NOSIGNAL] = 1;
        
        if ($callback) {
            $options[CURLOPT_WRITEFUNCTION] = function($ch, $data) use ($callback) {
                $callback($data);
                return strlen($data);
            };
        }
        
        curl_setopt_array($ch, $options);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * 设置代理
     */
    public function setProxy(string $proxy, string $type = 'http', int $port = 80): self
    {
        $this->addCurlOption(CURLOPT_PROXY, $proxy);
        $this->addCurlOption(CURLOPT_PROXYPORT, $port);
        
        if ($type === 'socks5') {
            $this->addCurlOption(CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        } elseif ($type === 'socks4') {
            $this->addCurlOption(CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
        } else {
            $this->addCurlOption(CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }
        
        return $this;
    }

    /**
     * 设置HTTP认证
     */
    public function setAuth(string $username, string $password, int $type = CURLAUTH_BASIC): self
    {
        $this->addCurlOption(CURLOPT_HTTPAUTH, $type);
        $this->addCurlOption(CURLOPT_USERPWD, $username . ':' . $password);
        return $this;
    }

    /**
     * 获取请求的详细信息
     */
    public function getInfo(string $url, string $method = 'GET', $data = null, array $headers = []): array
    {
        $ch = curl_init();
        
        $options = $this->getBaseOptions();
        $options[CURLOPT_URL] = $url;
        $options[CURLOPT_CUSTOMREQUEST] = $method;
        $options[CURLOPT_RETURNTRANSFER] = true;
        $options[CURLOPT_HEADER] = true;
        $options[CURLOPT_HTTPHEADER] = $this->prepareHeaders($headers);
        
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            if ($data !== null) {
                $options[CURLOPT_POSTFIELDS] = $data;
            }
        }
        
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request failed: " . $error);
        }
        
        $info = curl_getinfo($ch);
        curl_close($ch);
        
        return $info;
    }

    private function request(string $url, string $method, $data = null, array $headers = []): string
    {
        $ch = curl_init();
        
        $options = $this->getBaseOptions();
        $options[CURLOPT_URL] = $url;
        $options[CURLOPT_CUSTOMREQUEST] = $method;
        $options[CURLOPT_HTTPHEADER] = $this->prepareHeaders($headers);
        
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            if ($data !== null) {
                $options[CURLOPT_POSTFIELDS] = $data;
            }
        }
        
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP {$method} request failed: " . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new RuntimeException("HTTP request failed with status: " . $httpCode, $httpCode);
        }

        return $response;
    }

    /**
     * 获取基础的cURL选项
     */
    private function getBaseOptions(): array
    {
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => $this->maxRedirects,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => '',
        ];
        
        // 设置网络接口
        if ($this->networkInterface !== null) {
            $options[CURLOPT_INTERFACE] = $this->networkInterface;
        }
        
        // 设置IP版本
        if ($this->ipVersion !== null) {
            if ($this->ipVersion === 4) {
                $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            } elseif ($this->ipVersion === 6) {
                $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V6;
            }
        }
        
        // 合并自定义选项
        foreach ($this->curlOptions as $key => $value) {
            $options[$key] = $value;
        }
        
        return $options;
    }

    private function prepareHeaders(array $headers): array
    {
        $defaultHeaders = [
            'User-Agent: okhttp/3.12.0',
            'Accept: */*',
            'Connection: close'
        ];

        return array_merge($defaultHeaders, $headers);
    }

    /**
     * 解析响应头
     */
    private function parseHeaders(string $headers): array
    {
        $result = [];
        $lines = explode("\r\n", $headers);
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $result[trim($key)] = trim($value);
            }
        }
        
        return $result;
    }
}
