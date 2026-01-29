<?php

declare(strict_types=1);

namespace Muxi;

class FormationConfig
{
    public function __construct(
        public readonly ?string $formationId = null,
        public readonly ?string $url = null,
        public readonly ?string $serverUrl = null,
        public readonly ?string $baseUrl = null,
        public readonly ?string $adminKey = null,
        public readonly ?string $clientKey = null,
        public readonly int $maxRetries = 0,
        public readonly int $timeout = 30,
        public readonly bool $debug = false
    ) {}
}

class FormationTransport
{
    private const RETRY_STATUSES = [429, 500, 502, 503, 504];

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $adminKey,
        private readonly ?string $clientKey,
        private readonly int $timeout = 30,
        private readonly int $maxRetries = 0,
        private readonly bool $debug = false
    ) {}

    public function requestJson(
        string $method,
        string $path,
        ?array $params = null,
        mixed $body = null,
        bool $useAdmin = true,
        string $userId = ''
    ): mixed {
        [$url, $fullPath] = $this->buildUrl($path, $params);
        $headers = $this->buildHeaders($useAdmin, $userId, $body !== null ? 'application/json' : null);

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

    public function streamSse(
        string $method,
        string $path,
        ?array $params = null,
        mixed $body = null,
        bool $useAdmin = true,
        string $userId = '',
        callable $callback = null
    ): void {
        [$url, $_] = $this->buildUrl($path, $params);
        $headers = $this->buildHeaders($useAdmin, $userId, $body !== null ? 'application/json' : null, 'text/event-stream');

        $event = null;
        $dataParts = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$event, &$dataParts, $callback) {
                foreach (explode("\n", $data) as $rawLine) {
                    $line = trim($rawLine);
                    if (str_starts_with($line, ':')) {
                        continue;
                    }

                    if ($line === '') {
                        if (!empty($dataParts)) {
                            if ($callback) {
                                $callback(['event' => $event ?? 'message', 'data' => implode("\n", $dataParts)]);
                            }
                        }
                        $event = null;
                        $dataParts = [];
                        continue;
                    }

                    if (str_starts_with($line, 'event:')) {
                        $event = trim(substr($line, 6));
                    } elseif (str_starts_with($line, 'data:')) {
                        $dataParts[] = trim(substr($line, 5));
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

    private function buildHeaders(bool $useAdmin, string $userId, ?string $contentType = null, string $accept = 'application/json'): array
    {
        $headers = [
            'X-Muxi-SDK' => 'php/' . Version::VERSION,
            'X-Muxi-Client' => 'php/' . Version::VERSION,
            'X-Muxi-Idempotency-Key' => $this->generateUuid(),
            'Accept' => $accept,
        ];

        if ($useAdmin) {
            if (empty($this->adminKey)) {
                throw new \InvalidArgumentException('admin key required');
            }
            $headers['X-MUXI-ADMIN-KEY'] = $this->adminKey;
        } else {
            if (empty($this->clientKey)) {
                throw new \InvalidArgumentException('client key required');
            }
            $headers['X-MUXI-CLIENT-KEY'] = $this->clientKey;
        }

        if (!empty($userId)) {
            $headers['X-Muxi-User-ID'] = $userId;
        }

        if ($contentType) {
            $headers['Content-Type'] = $contentType;
        }

        return $headers;
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

class FormationClient
{
    private FormationTransport $transport;

    public function __construct(FormationConfig|array $config)
    {
        if (is_array($config)) {
            $config = new FormationConfig(...$config);
        }

        $baseUrl = $this->buildBaseUrl($config);
        $this->transport = new FormationTransport(
            baseUrl: $baseUrl,
            adminKey: $config->adminKey,
            clientKey: $config->clientKey,
            timeout: $config->timeout,
            maxRetries: $config->maxRetries,
            debug: $config->debug
        );
    }

    // Health / status
    public function health(): array
    {
        return $this->transport->requestJson('GET', '/health', useAdmin: false);
    }

    public function getStatus(): array
    {
        return $this->transport->requestJson('GET', '/status', useAdmin: true);
    }

    public function getConfig(): array
    {
        return $this->transport->requestJson('GET', '/config', useAdmin: true);
    }

    public function getFormationInfo(): array
    {
        return $this->transport->requestJson('GET', '/formation', useAdmin: true);
    }

    // Agents / MCP
    public function getAgents(): array
    {
        return $this->transport->requestJson('GET', '/agents', useAdmin: true);
    }

    public function getAgent(string $agentId): array
    {
        return $this->transport->requestJson('GET', "/agents/{$agentId}", useAdmin: true);
    }

    public function getMcpServers(): array
    {
        return $this->transport->requestJson('GET', '/mcp/servers', useAdmin: true);
    }

    public function getMcpServer(string $serverId): array
    {
        return $this->transport->requestJson('GET', "/mcp/servers/{$serverId}", useAdmin: true);
    }

    public function getMcpTools(): array
    {
        return $this->transport->requestJson('GET', '/mcp/tools', useAdmin: true);
    }

    // Secrets
    public function getSecrets(): array
    {
        return $this->transport->requestJson('GET', '/secrets', useAdmin: true);
    }

    public function getSecret(string $key): array
    {
        return $this->transport->requestJson('GET', "/secrets/{$key}", useAdmin: true);
    }

    public function setSecret(string $key, string $value): void
    {
        $this->transport->requestJson('PUT', "/secrets/{$key}", body: ['value' => $value], useAdmin: true);
    }

    public function deleteSecret(string $key): void
    {
        $this->transport->requestJson('DELETE', "/secrets/{$key}", useAdmin: true);
    }

    // Chat
    public function chat(array $payload, string $userId = ''): array
    {
        return $this->transport->requestJson('POST', '/chat', body: $payload, useAdmin: false, userId: $userId);
    }

    public function chatStream(array $payload, string $userId = '', callable $callback = null): void
    {
        $body = array_merge($payload, ['stream' => true]);
        $this->transport->streamSse('POST', '/chat', body: $body, useAdmin: false, userId: $userId, callback: $callback);
    }

    public function audioChat(array $payload, string $userId = ''): array
    {
        return $this->transport->requestJson('POST', '/audiochat', body: $payload, useAdmin: false, userId: $userId);
    }

    public function audioChatStream(array $payload, string $userId = '', callable $callback = null): void
    {
        $body = array_merge($payload, ['stream' => true]);
        $this->transport->streamSse('POST', '/audiochat', body: $body, useAdmin: false, userId: $userId, callback: $callback);
    }

    // Sessions / requests
    public function getSessions(string $userId, ?int $limit = null): array
    {
        return $this->transport->requestJson('GET', '/sessions', params: ['user_id' => $userId, 'limit' => $limit], useAdmin: false, userId: $userId);
    }

    public function getSession(string $sessionId, string $userId): array
    {
        return $this->transport->requestJson('GET', "/sessions/{$sessionId}", useAdmin: false, userId: $userId);
    }

    public function getSessionMessages(string $sessionId, string $userId): array
    {
        return $this->transport->requestJson('GET', "/sessions/{$sessionId}/messages", useAdmin: false, userId: $userId);
    }

    public function restoreSession(string $sessionId, string $userId, array $messages): void
    {
        $this->transport->requestJson('POST', "/sessions/{$sessionId}/restore", body: ['messages' => $messages], useAdmin: false, userId: $userId);
    }

    public function getRequests(string $userId): array
    {
        return $this->transport->requestJson('GET', '/requests', useAdmin: false, userId: $userId);
    }

    public function getRequestStatus(string $requestId, string $userId): array
    {
        return $this->transport->requestJson('GET', "/requests/{$requestId}", useAdmin: false, userId: $userId);
    }

    public function cancelRequest(string $requestId, string $userId): void
    {
        $this->transport->requestJson('DELETE', "/requests/{$requestId}", useAdmin: false, userId: $userId);
    }

    // Memory
    public function getMemoryConfig(): array
    {
        return $this->transport->requestJson('GET', '/memory', useAdmin: true);
    }

    public function getMemories(string $userId, ?int $limit = null): array
    {
        return $this->transport->requestJson('GET', '/memories', params: ['user_id' => $userId, 'limit' => $limit], useAdmin: false, userId: $userId);
    }

    public function addMemory(string $userId, string $type, string $detail): array
    {
        return $this->transport->requestJson('POST', '/memories', body: ['user_id' => $userId, 'type' => $type, 'detail' => $detail], useAdmin: false, userId: $userId);
    }

    public function deleteMemory(string $userId, string $memoryId): void
    {
        $this->transport->requestJson('DELETE', "/memories/{$memoryId}", params: ['user_id' => $userId], useAdmin: false, userId: $userId);
    }

    public function getUserBuffer(string $userId): array
    {
        return $this->transport->requestJson('GET', '/memory/buffer', params: ['user_id' => $userId], useAdmin: false, userId: $userId);
    }

    public function clearUserBuffer(string $userId): array
    {
        return $this->transport->requestJson('DELETE', '/memory/buffer', params: ['user_id' => $userId], useAdmin: false, userId: $userId);
    }

    public function clearSessionBuffer(string $userId, string $sessionId): array
    {
        return $this->transport->requestJson('DELETE', "/memory/buffer/{$sessionId}", params: ['user_id' => $userId], useAdmin: false, userId: $userId);
    }

    public function clearAllBuffers(): array
    {
        return $this->transport->requestJson('DELETE', '/memory/buffer', useAdmin: true);
    }

    public function getBufferStats(): array
    {
        return $this->transport->requestJson('GET', '/memory/stats', useAdmin: true);
    }

    // Scheduler
    public function getSchedulerConfig(): array
    {
        return $this->transport->requestJson('GET', '/scheduler', useAdmin: true);
    }

    public function getSchedulerJobs(string $userId): array
    {
        return $this->transport->requestJson('GET', '/scheduler/jobs', params: ['user_id' => $userId], useAdmin: true);
    }

    public function getSchedulerJob(string $jobId): array
    {
        return $this->transport->requestJson('GET', "/scheduler/jobs/{$jobId}", useAdmin: true);
    }

    public function createSchedulerJob(string $type, string $schedule, string $message, string $userId): array
    {
        return $this->transport->requestJson('POST', '/scheduler/jobs', body: ['type' => $type, 'schedule' => $schedule, 'message' => $message, 'user_id' => $userId], useAdmin: true);
    }

    public function deleteSchedulerJob(string $jobId): void
    {
        $this->transport->requestJson('DELETE', "/scheduler/jobs/{$jobId}", useAdmin: true);
    }

    // Async / logging / a2a
    public function getAsyncConfig(): array
    {
        return $this->transport->requestJson('GET', '/async', useAdmin: true);
    }

    public function getA2aConfig(): array
    {
        return $this->transport->requestJson('GET', '/a2a', useAdmin: true);
    }

    public function getLoggingConfig(): array
    {
        return $this->transport->requestJson('GET', '/logging', useAdmin: true);
    }

    public function getLoggingDestinations(): array
    {
        return $this->transport->requestJson('GET', '/logging/destinations', useAdmin: true);
    }

    // Credentials / identifiers
    public function listCredentialServices(): array
    {
        return $this->transport->requestJson('GET', '/credentials/services', useAdmin: true);
    }

    public function listCredentials(string $userId): array
    {
        return $this->transport->requestJson('GET', '/credentials', useAdmin: false, userId: $userId);
    }

    public function getCredential(string $credentialId, string $userId): array
    {
        return $this->transport->requestJson('GET', "/credentials/{$credentialId}", useAdmin: false, userId: $userId);
    }

    public function createCredential(string $userId, array $payload): array
    {
        return $this->transport->requestJson('POST', '/credentials', body: $payload, useAdmin: false, userId: $userId);
    }

    public function deleteCredential(string $credentialId, string $userId): array
    {
        return $this->transport->requestJson('DELETE', "/credentials/{$credentialId}", useAdmin: false, userId: $userId);
    }

    public function getUserIdentifiersForUser(string $userId): array
    {
        return $this->transport->requestJson('GET', "/users/identifiers/{$userId}", useAdmin: true);
    }

    public function linkUserIdentifier(string $muxiUserId, array $identifiers): array
    {
        return $this->transport->requestJson('POST', '/users/identifiers', body: ['muxi_user_id' => $muxiUserId, 'identifiers' => $identifiers], useAdmin: true);
    }

    public function unlinkUserIdentifier(string $identifier): void
    {
        $this->transport->requestJson('DELETE', "/users/identifiers/{$identifier}", useAdmin: true);
    }

    // Overlord / LLM
    public function getOverlordConfig(): array
    {
        return $this->transport->requestJson('GET', '/overlord', useAdmin: true);
    }

    public function getOverlordPersona(): array
    {
        return $this->transport->requestJson('GET', '/overlord/persona', useAdmin: true);
    }

    public function getLlmSettings(): array
    {
        return $this->transport->requestJson('GET', '/llm/settings', useAdmin: true);
    }

    // Triggers / SOP / Audit
    public function getTriggers(): array
    {
        return $this->transport->requestJson('GET', '/triggers', useAdmin: false);
    }

    public function getTrigger(string $name): array
    {
        return $this->transport->requestJson('GET', "/triggers/{$name}", useAdmin: false);
    }

    public function fireTrigger(string $name, mixed $data, bool $async = false, string $userId = ''): array
    {
        return $this->transport->requestJson('POST', "/triggers/{$name}", params: ['async' => $async ? 'true' : 'false'], body: $data, useAdmin: false, userId: $userId);
    }

    public function getSops(): array
    {
        return $this->transport->requestJson('GET', '/sops', useAdmin: false);
    }

    public function getSop(string $name): array
    {
        return $this->transport->requestJson('GET', "/sops/{$name}", useAdmin: false);
    }

    public function getAuditLog(): array
    {
        return $this->transport->requestJson('GET', '/audit', useAdmin: true);
    }

    public function clearAuditLog(): void
    {
        $this->transport->requestJson('DELETE', '/audit?confirm=clear-audit-log', useAdmin: true);
    }

    // Streaming
    public function streamEvents(string $userId, callable $callback): void
    {
        $this->transport->streamSse('GET', '/events', params: ['user_id' => $userId], useAdmin: false, userId: $userId, callback: $callback);
    }

    public function streamRequest(string $userId, string $sessionId, string $requestId, callable $callback): void
    {
        $this->transport->streamSse('GET', "/events/{$sessionId}/{$requestId}", useAdmin: false, userId: $userId, callback: $callback);
    }

    public function streamLogs(?array $filters = null, callable $callback = null): void
    {
        $this->transport->streamSse('GET', '/logs', params: $filters, useAdmin: true, callback: $callback);
    }

    // Resolve user
    public function resolveUser(string $identifier, bool $createUser = false): array
    {
        return $this->transport->requestJson('POST', '/users/resolve', body: ['identifier' => $identifier, 'create_user' => $createUser], useAdmin: false);
    }

    private function buildBaseUrl(FormationConfig $config): string
    {
        if ($config->baseUrl) {
            return rtrim($config->baseUrl, '/');
        }
        if ($config->url) {
            return rtrim($config->url, '/') . '/v1';
        }
        if ($config->serverUrl && $config->formationId) {
            return rtrim($config->serverUrl, '/') . "/api/{$config->formationId}/v1";
        }
        throw new \InvalidArgumentException('must set baseUrl, url, or serverUrl+formationId');
    }
}
