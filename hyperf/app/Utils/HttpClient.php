<?php

declare(strict_types=1);

namespace App\Utils;

use App\Facades\Log;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Guzzle\ClientFactory;

class HttpClient
{
    protected ?Client $client = null;

    protected string $serviceName = '';

    protected string $baseUri = '';

    protected array $pendingHeaders = [];

    protected array $pendingOptions = [];

    protected int $retryTimes = 0;

    protected int $retryInterval = 200;

    protected array $defaultOptions = [
        'timeout' => 30,
        'connect_timeout' => 10,
        'http_errors' => false,
    ];

    // ────────────────────────────────────────
    //  静态工厂
    // ────────────────────────────────────────

    protected static function getClientFactory(): ClientFactory
    {
        return \Hyperf\Context\ApplicationContext::getContainer()->get(ClientFactory::class);
    }

    /**
     * 通过 service 名称创建实例，自动读取 config/autoload/http_services.php 中的配置
     *
     * HttpClient::service('user')->postJson('/api/info', ['uid' => 1])
     */
    public static function service(string $name): static
    {
        $config = app('Config');
        $serviceConfig = $config->get("http_services.{$name}");

        if (empty($serviceConfig)) {
            throw new \InvalidArgumentException("HTTP service [{$name}] not configured in config/autoload/http_services.php");
        }

        $instance = new static();
        $instance->serviceName = $name;
        $instance->baseUri = rtrim($serviceConfig['host'] ?? '', '/');
        $instance->retryTimes = $serviceConfig['retry_times'] ?? 0;
        $instance->retryInterval = $serviceConfig['retry_interval'] ?? 200;
        $instance->client = static::getClientFactory()->create([
            'timeout' => $serviceConfig['timeout'] ?? 30,
            'connect_timeout' => $serviceConfig['connect_timeout'] ?? 10,
            'http_errors' => false,
        ]);

        return $instance;
    }

    /**
     * 直接创建实例（不走 service 配置）
     */
    public static function create(array $options = []): static
    {
        $instance = new static();
        $instance->client = static::getClientFactory()->create(array_merge($instance->defaultOptions, $options));

        return $instance;
    }

    // ────────────────────────────────────────
    //  链式配置（用完即清）
    // ────────────────────────────────────────

    public function withHeaders(array $headers): static
    {
        $this->pendingHeaders = array_merge($this->pendingHeaders, $headers);
        return $this;
    }

    public function withOptions(array $options): static
    {
        $this->pendingOptions = array_merge($this->pendingOptions, $options);
        return $this;
    }

    /**
     * @param int $times      重试次数
     * @param int $intervalMs 重试间隔(毫秒)，每次递增（退避策略）
     */
    public function withRetry(int $times = 2, int $intervalMs = 200): static
    {
        $this->retryTimes = $times;
        $this->retryInterval = $intervalMs;
        return $this;
    }

    // ────────────────────────────────────────
    //  公开请求方法
    // ────────────────────────────────────────

    public function get(string $uri, array $query = [], array $headers = []): array
    {
        return $this->request('GET', $uri, [
            'query' => $query,
            'headers' => $headers,
        ]);
    }

    public function postForm(string $uri, array $params = [], array $headers = []): array
    {
        return $this->request('POST', $uri, [
            'form_params' => $params,
            'headers' => $headers,
        ]);
    }

    public function postJson(string $uri, array $data = [], array $headers = []): array
    {
        return $this->request('POST', $uri, [
            'json' => $data,
            'headers' => $headers,
        ]);
    }

    /**
     * @param array $files    [['name'=>'file','path'=>'/tmp/a.jpg','filename'=>'a.jpg'], ...]
     * @param array $formData ['key'=>'value', ...]
     */
    public function upload(string $uri, array $files, array $formData = [], array $headers = []): array
    {
        $multipart = [];

        foreach ($formData as $name => $value) {
            $multipart[] = [
                'name' => (string) $name,
                'contents' => (string) $value,
            ];
        }

        foreach ($files as $file) {
            $filePath = $file['path'] ?? '';
            if (!is_file($filePath)) {
                throw new \InvalidArgumentException("Upload file not found: {$filePath}");
            }

            $multipart[] = [
                'name' => $file['name'] ?? 'file',
                'contents' => fopen($filePath, 'r'),
                'filename' => $file['filename'] ?? basename($filePath),
            ];
        }

        return $this->request('POST', $uri, [
            'multipart' => $multipart,
            'headers' => $headers,
        ]);
    }

    // ────────────────────────────────────────
    //  核心请求引擎
    // ────────────────────────────────────────

    protected function request(string $method, string $uri, array $options = []): array
    {
        if ($this->client === null) {
            $this->client = static::getClientFactory()->create($this->defaultOptions);
        }

        $mergedHeaders = array_merge($this->pendingHeaders, $options['headers'] ?? []);
        $options['headers'] = $mergedHeaders;
        $options = array_merge($options, $this->pendingOptions);

        $maxAttempts = $this->retryTimes + 1;
        $retryInterval = $this->retryInterval;

        $this->pendingHeaders = [];
        $this->pendingOptions = [];

        $fullUrl = $this->buildUrl($uri);
        $logId = TraceId::getId();
        $startTime = microtime(true);
        $logParams = $this->buildLogParams($options);

        Log::curl('HTTP_REQUEST', $this->filterNull([
            'log_id' => $logId,
            'service' => $this->serviceName ?: null,
            'method' => $method,
            'url' => $fullUrl,
            'params' => $logParams,
            'headers' => $mergedHeaders,
        ]));

        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                $response = $this->client->request($method, $fullUrl, $options);
                $statusCode = $response->getStatusCode();
                $body = $response->getBody()->getContents();
                $elapsed = round((microtime(true) - $startTime) * 1000, 2);

                if ($statusCode >= 500 && $attempt < $maxAttempts) {
                    Log::warn('HTTP_RETRY', $this->filterNull([
                        'log_id' => $logId,
                        'service' => $this->serviceName ?: null,
                        'url' => $fullUrl,
                        'http_code' => $statusCode,
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                    ]));
                    $this->sleep($retryInterval * $attempt);
                    continue;
                }

                $decoded = json_decode($body, true);
                $responseLog = mb_strlen($body) > 5000
                    ? mb_substr($body, 0, 5000) . '...[truncated]'
                    : ($decoded ?? $body);

                $logPayload = $this->filterNull([
                    'log_id' => $logId,
                    'service' => $this->serviceName ?: null,
                    'method' => $method,
                    'url' => $fullUrl,
                    'http_code' => $statusCode,
                    'attempt' => $maxAttempts > 1 ? $attempt : null,
                    'response' => $responseLog,
                    'elapsed_ms' => $elapsed,
                ]);

                $statusCode >= 400
                    ? Log::warn('HTTP_RESPONSE_ERROR', $logPayload)
                    : Log::curl('HTTP_RESPONSE', $logPayload);

                return [
                    'http_code' => $statusCode,
                    'body' => $body,
                    'data' => $decoded,
                    'elapsed_ms' => $elapsed,
                ];
            } catch (GuzzleException $e) {
                $lastException = $e;

                if ($attempt < $maxAttempts) {
                    Log::warn('HTTP_RETRY', $this->filterNull([
                        'log_id' => $logId,
                        'service' => $this->serviceName ?: null,
                        'url' => $fullUrl,
                        'error' => $e->getMessage(),
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                    ]));
                    $this->sleep($retryInterval * $attempt);
                }
            }
        }

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);

        Log::warn('HTTP_ERROR', $this->filterNull([
            'log_id' => $logId,
            'service' => $this->serviceName ?: null,
            'method' => $method,
            'url' => $fullUrl,
            'error' => $lastException?->getMessage() ?? 'Unknown error',
            'error_code' => $lastException?->getCode() ?? 0,
            'attempts' => $attempt,
            'elapsed_ms' => $elapsed,
            'trace' => $lastException?->getTraceAsString(),
        ]));

        return [
            'http_code' => 0,
            'body' => '',
            'data' => null,
            'error' => $lastException?->getMessage() ?? 'Unknown error',
            'elapsed_ms' => $elapsed,
        ];
    }

    // ────────────────────────────────────────
    //  内部工具方法
    // ────────────────────────────────────────

    protected function buildUrl(string $uri): string
    {
        if ($this->baseUri && !str_starts_with($uri, 'http://') && !str_starts_with($uri, 'https://')) {
            return $this->baseUri . '/' . ltrim($uri, '/');
        }

        return $uri;
    }

    /**
     * 毫秒级休眠，协程环境使用协程 sleep，否则回退到 usleep
     */
    protected function sleep(int $ms): void
    {
        if (\Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::sleep($ms / 1000);
        } else {
            usleep($ms * 1000);
        }
    }

    protected function filterNull(array $data): array
    {
        return array_filter($data, fn($value) => $value !== null);
    }

    protected function buildLogParams(array $options): array|string
    {
        if (isset($options['json'])) {
            return $options['json'];
        }

        if (isset($options['form_params'])) {
            return $options['form_params'];
        }

        if (isset($options['query'])) {
            return $options['query'];
        }

        if (isset($options['multipart'])) {
            $parts = [];
            foreach ($options['multipart'] as $item) {
                if (is_resource($item['contents'] ?? null)) {
                    $parts[$item['name']] = '[FILE] ' . ($item['filename'] ?? 'unknown');
                } else {
                    $parts[$item['name']] = $item['contents'] ?? '';
                }
            }
            return $parts;
        }

        return [];
    }
}
