<?php

declare(strict_types=1);

namespace Muxi;

use CurlHandle;

class Transport
{
    private const RETRY_STATUSES = [429, 500, 502, 503, 504];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $keyId,
        private readonly string $secretKey,
        private readonly int $timeout = 30,
        private readonly int $maxRetries = 0,
        private readonly bool $debug = false
    ) {}

    public function requestJson(
        string $method,
        string $path,
        ?array $params = null,
        mixed $body = null
    ): mixed {
        [$url, $fullPath] = $this->buildUrl($path, $params);
        $headers = $this->buildHeaders($method, $fullPath);

        $attempt = 0;
        $backoff = 0.5;

        while (true) {
            $startTime = microtime(true);
            
            try {
                $response = $this->executeRequest($method, $url, $headers, $body);
                $elapsed = microtime(true) - $startTime;
                $this->log("{$method} {$fullPath} -> {$response['status']} ({$elapsed}s)");

                if ($response['status'] >= 400) {
                    $this->handleErrorResponse($response, $method, $url, $attempt, $backoff);
                    $backoff *= 2;
                    $attempt++;
                    if ($attempt <= $this->maxRetries) {
                        continue;
                    }
                }

                return $this->parseResponse($response);
            } catch (ConnectionException $e) {
                if ($attempt < $this->maxRetries) {
                    $sleepFor = min($backoff, 30);
                    $this->log("retry {$method} {$fullPath} after {$sleepFor}s due to connection error");
                    usleep((int)($sleepFor * 1_000_000));
                    $backoff *= 2;
                    $attempt++;
                    continue;
                }
                throw $e;
            }
        }
    }

    public function streamLines(
        string $method,
        string $path,
        ?array $params = null,
        mixed $body = null,
        callable $callback = null
    ): void {
        [$url, $fullPath] = $this->buildUrl($path, $params);
        $headers = $this->buildHeaders($method, $fullPath, 'text/event-stream');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($callback) {
                if ($callback) {
                    foreach (explode("\n", $data) as $line) {
                        $callback($line);
                    }
                }
                return strlen($data);
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        curl_exec($ch);
        curl_close($ch);
    }

    private function buildUrl(string $path, ?array $params): array
    {
        $relPath = str_starts_with($path, '/') ? $path : "/{$path}";
        $query = '';
        if ($params) {
            $filtered = array_filter($params, fn($v) => $v !== null);
            if ($filtered) {
                $query = '?' . http_build_query($filtered);
            }
        }
        $fullPath = $relPath . $query;
        return [rtrim($this->baseUrl, '/') . $fullPath, $fullPath];
    }

    private function buildHeaders(string $method, string $path, string $accept = 'application/json'): array
    {
        return [
            'Authorization' => Auth::buildAuthHeader($this->keyId, $this->secretKey, $method, $path),
            'Content-Type' => 'application/json',
            'Accept' => $accept,
            'X-Muxi-SDK' => 'php/' . Version::VERSION,
            'X-Muxi-Client' => 'php/' . PHP_VERSION,
            'X-Muxi-Idempotency-Key' => $this->generateUuid(),
        ];
    }

    private function formatHeaders(array $headers): array
    {
        return array_map(fn($k, $v) => "{$k}: {$v}", array_keys($headers), array_values($headers));
    }

    private function executeRequest(string $method, string $url, array $headers, mixed $body): array
    {
        $ch = curl_init($url);
        
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_HEADER => true,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ConnectionException('CONNECTION_ERROR', $error, 0);
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        return [
            'status' => $status,
            'headers' => $this->parseHeaders($headerStr),
            'body' => $body,
        ];
    }

    private function parseHeaders(string $headerStr): array
    {
        $headers = [];
        foreach (explode("\r\n", $headerStr) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        return $headers;
    }

    private function handleErrorResponse(array $response, string $method, string $url, int $attempt, float $backoff): void
    {
        $status = $response['status'];
        $retryAfter = isset($response['headers']['retry-after']) ? (int)$response['headers']['retry-after'] : null;

        $payload = null;
        if ($response['body']) {
            try {
                $payload = json_decode($response['body'], true);
            } catch (\Exception $e) {
                $payload = null;
            }
        }

        $code = $payload['code'] ?? $payload['error'] ?? 'ERROR';
        $message = $payload['message'] ?? 'Unknown error';

        if (in_array($status, self::RETRY_STATUSES) && $attempt < $this->maxRetries) {
            $sleepFor = min($backoff, 30);
            $this->log("retry {$method} {$url} after {$sleepFor}s due to {$status}");
            usleep((int)($sleepFor * 1_000_000));
            return;
        }

        throw ErrorMapper::map($status, $code, $message, $payload, $retryAfter);
    }

    private function parseResponse(array $response): mixed
    {
        if (empty($response['body'])) {
            return null;
        }

        try {
            $parsed = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            return $this->unwrapEnvelope($parsed);
        } catch (\JsonException $e) {
            return $response['body'];
        }
    }

    private function unwrapEnvelope(mixed $obj): mixed
    {
        if (!is_array($obj) || !array_key_exists('data', $obj)) {
            return $obj;
        }

        $req = $obj['request'] ?? [];
        $requestId = $req['id'] ?? $obj['request_id'] ?? null;
        $ts = $obj['timestamp'] ?? null;
        $data = $obj['data'];

        if (is_array($data)) {
            $out = $data;
            if ($requestId !== null) {
                $out['request_id'] = $out['request_id'] ?? $requestId;
            }
            if ($ts !== null) {
                $out['timestamp'] = $out['timestamp'] ?? $ts;
            }
            return $out;
        }

        return $data ?? $obj;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function log(string $msg): void
    {
        if ($this->debug || getenv('MUXI_DEBUG') === '1') {
            error_log("[MUXI] {$msg}");
        }
    }
}
