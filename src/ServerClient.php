<?php

declare(strict_types=1);

namespace Muxi;

class ServerConfig
{
    public function __construct(
        public readonly string $url,
        public readonly string $keyId,
        public readonly string $secretKey,
        public readonly int $maxRetries = 0,
        public readonly int $timeout = 30,
        public readonly bool $debug = false,
        public readonly ?string $_app = null  // Internal: for Console telemetry
    ) {}
}

class ServerClient
{
    private Transport $transport;

    public function __construct(ServerConfig|array $config)
    {
        if (is_array($config)) {
            $config = new ServerConfig(...$config);
        }

        $this->transport = new Transport(
            baseUrl: $config->url,
            keyId: $config->keyId,
            secretKey: $config->secretKey,
            timeout: $config->timeout,
            maxRetries: $config->maxRetries,
            debug: $config->debug,
            app: $config->_app
        );
    }

    // Unauthenticated
    public function ping(): int
    {
        $resp = $this->transport->requestJson('GET', '/ping');
        return is_array($resp) ? count($resp) : 0;
    }

    public function health(): array
    {
        return $this->transport->requestJson('GET', '/health');
    }

    // Authenticated - Server management
    public function status(): array
    {
        return $this->rpcGet('/rpc/server/status');
    }

    public function listFormations(): array
    {
        return $this->rpcGet('/rpc/formations');
    }

    public function getFormation(string $formationId): array
    {
        return $this->rpcGet("/rpc/formations/{$formationId}");
    }

    public function stopFormation(string $formationId): array
    {
        return $this->rpcPost("/rpc/formations/{$formationId}/stop", []);
    }

    public function startFormation(string $formationId): array
    {
        return $this->rpcPost("/rpc/formations/{$formationId}/start", []);
    }

    public function restartFormation(string $formationId): array
    {
        return $this->rpcPost("/rpc/formations/{$formationId}/restart", []);
    }

    public function rollbackFormation(string $formationId): array
    {
        return $this->rpcPost("/rpc/formations/{$formationId}/rollback", []);
    }

    public function deleteFormation(string $formationId): array
    {
        return $this->rpcDelete("/rpc/formations/{$formationId}");
    }

    public function cancelUpdate(string $formationId): array
    {
        return $this->rpcPost("/rpc/formations/{$formationId}/cancel-update", []);
    }

    public function deployFormation(string $formationId, array $payload): array
    {
        return $this->rpcPost("/rpc/formations/{$formationId}/deploy", $payload);
    }

    public function updateFormation(string $formationId, array $payload): array
    {
        return $this->rpcPost("/rpc/formations/{$formationId}/update", $payload);
    }

    public function getFormationLogs(string $formationId, ?int $limit = null): array
    {
        $params = $limit !== null ? ['limit' => $limit] : null;
        return $this->rpcGet("/rpc/formations/{$formationId}/logs", $params);
    }

    public function getServerLogs(?int $limit = null): array
    {
        $params = $limit !== null ? ['limit' => $limit] : null;
        return $this->rpcGet('/rpc/server/logs', $params);
    }

    // Streaming
    public function deployFormationStream(string $formationId, array $payload, callable $callback): void
    {
        $this->streamSse("/rpc/formations/{$formationId}/deploy/stream", $payload, $callback);
    }

    public function updateFormationStream(string $formationId, array $payload, callable $callback): void
    {
        $this->streamSse("/rpc/formations/{$formationId}/update/stream", $payload, $callback);
    }

    public function startFormationStream(string $formationId, callable $callback): void
    {
        $this->streamSse("/rpc/formations/{$formationId}/start/stream", [], $callback);
    }

    public function restartFormationStream(string $formationId, callable $callback): void
    {
        $this->streamSse("/rpc/formations/{$formationId}/restart/stream", [], $callback);
    }

    public function rollbackFormationStream(string $formationId, callable $callback): void
    {
        $this->streamSse("/rpc/formations/{$formationId}/rollback/stream", [], $callback);
    }

    public function streamFormationLogs(string $formationId, callable $callback): void
    {
        $this->streamSseGet("/rpc/formations/{$formationId}/logs/stream", $callback);
    }

    private function rpcGet(string $path, ?array $params = null): array
    {
        return $this->transport->requestJson('GET', $path, $params);
    }

    private function rpcPost(string $path, array $body): array
    {
        return $this->transport->requestJson('POST', $path, null, $body);
    }

    private function rpcDelete(string $path): array
    {
        return $this->transport->requestJson('DELETE', $path);
    }

    private function streamSse(string $path, array $body, callable $callback): void
    {
        $event = null;
        $dataParts = [];

        $this->transport->streamLines('POST', $path, null, $body, function ($line) use (&$event, &$dataParts, $callback) {
            $line = trim($line);
            if (str_starts_with($line, ':')) {
                return;
            }

            if ($line === '') {
                if (!empty($dataParts)) {
                    $callback(['event' => $event ?? 'message', 'data' => implode("\n", $dataParts)]);
                }
                $event = null;
                $dataParts = [];
                return;
            }

            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $dataParts[] = trim(substr($line, 5));
            }
        });
    }

    private function streamSseGet(string $path, callable $callback): void
    {
        $event = null;
        $dataParts = [];

        $this->transport->streamLines('GET', $path, null, null, function ($line) use (&$event, &$dataParts, $callback) {
            $line = trim($line);
            if (str_starts_with($line, ':')) {
                return;
            }

            if ($line === '') {
                if (!empty($dataParts)) {
                    $callback(['event' => $event ?? 'message', 'data' => implode("\n", $dataParts)]);
                }
                $event = null;
                $dataParts = [];
                return;
            }

            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $dataParts[] = trim(substr($line, 5));
            }
        });
    }
}
