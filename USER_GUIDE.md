# MUXI PHP SDK User Guide

## Installation

```bash
composer require muxi/muxi-php
```

## Requirements

- PHP 8.1+
- ext-curl
- ext-json

## Quickstart

```php
<?php
require_once 'vendor/autoload.php';

use Muxi\ServerClient;
use Muxi\FormationClient;

// Server client (management, HMAC auth)
$server = new ServerClient([
    'url' => 'https://server.example.com',
    'keyId' => '<key_id>',
    'secretKey' => '<secret_key>',
]);
print_r($server->status());

// Formation client (runtime, key auth)
$formation = new FormationClient([
    'serverUrl' => 'https://server.example.com',
    'formationId' => '<formation_id>',
    'clientKey' => '<client_key>',
    'adminKey' => '<admin_key>',
]);
print_r($formation->health());
```

## Clients

- **ServerClient** (management, HMAC): deploy/list/update formations, server health/status, server logs.
- **FormationClient** (runtime, client/admin keys): chat/audio (streaming), agents, secrets, MCP, memory, scheduler, sessions/requests, identifiers, credentials, triggers/SOPs/audit, async/A2A/logging config, overlord/LLM settings, events/logs streaming.

## Streaming

```php
// Chat streaming (callback)
$formation->chatStream(['message' => 'Tell me a story'], 'user-123', function($event) {
    if ($event['event'] === 'message') {
        echo $event['data'];
    }
});

// Event streaming
$formation->streamEvents('user-123', function($event) {
    print_r($event);
});

// Log streaming (admin)
$formation->streamLogs(['level' => 'info'], function($log) {
    print_r($log);
});
```

## Auth & Headers

- **ServerClient**: HMAC with `keyId`/`secretKey` on `/rpc` endpoints.
- **FormationClient**: `X-MUXI-CLIENT-KEY` or `X-MUXI-ADMIN-KEY` on `/api/{formation}/v1`. Override `baseUrl` for direct access (e.g., `http://localhost:9012/v1`).
- **Idempotency**: `X-Muxi-Idempotency-Key` auto-generated on every request.
- **SDK headers**: `X-Muxi-SDK`, `X-Muxi-Client` set automatically.

## Timeouts & Retries

- Default timeout: 30s (no timeout for streaming).
- Retries: `maxRetries` with exponential backoff on 429/5xx/connection errors; respects `Retry-After`.
- Debug logging: enabled when `debug: true` or `MUXI_DEBUG=1`.

## Error Handling

```php
use Muxi\AuthenticationException;
use Muxi\RateLimitException;
use Muxi\NotFoundException;
use Muxi\MuxiException;

try {
    $formation->chat(['message' => 'hello']);
} catch (AuthenticationException $e) {
    echo "Auth failed: {$e->getMessage()}\n";
} catch (RateLimitException $e) {
    echo "Rate limited. Retry after: {$e->retryAfter}s\n";
} catch (NotFoundException $e) {
    echo "Not found: {$e->getMessage()}\n";
} catch (MuxiException $e) {
    echo "{$e->errorCode}: {$e->getMessage()} ({$e->statusCode})\n";
}
```

Error types: `AuthenticationException`, `AuthorizationException`, `NotFoundException`, `ValidationException`, `RateLimitException`, `ServerException`, `ConnectionException`.

## Notable Endpoints (FormationClient)

| Category | Methods |
|----------|---------|
| Chat/Audio | `chat`, `chatStream`, `audioChat`, `audioChatStream` |
| Memory | `getMemoryConfig`, `getMemories`, `addMemory`, `deleteMemory`, `getUserBuffer`, `clearUserBuffer`, `clearSessionBuffer`, `clearAllBuffers`, `getBufferStats` |
| Scheduler | `getSchedulerConfig`, `getSchedulerJobs`, `getSchedulerJob`, `createSchedulerJob`, `deleteSchedulerJob` |
| Sessions | `getSessions`, `getSession`, `getSessionMessages`, `restoreSession` |
| Requests | `getRequests`, `getRequestStatus`, `cancelRequest` |
| Agents/MCP | `getAgents`, `getAgent`, `getMcpServers`, `getMcpServer`, `getMcpTools` |
| Secrets | `getSecrets`, `getSecret`, `setSecret`, `deleteSecret` |
| Credentials | `listCredentialServices`, `listCredentials`, `getCredential`, `createCredential`, `deleteCredential` |
| Identifiers | `getUserIdentifiersForUser`, `linkUserIdentifier`, `unlinkUserIdentifier` |
| Triggers/SOP | `getTriggers`, `getTrigger`, `fireTrigger`, `getSops`, `getSop` |
| Audit | `getAuditLog`, `clearAuditLog` |
| Config | `getStatus`, `getConfig`, `getFormationInfo`, `getAsyncConfig`, `getA2aConfig`, `getLoggingConfig`, `getLoggingDestinations`, `getOverlordConfig`, `getOverlordPersona`, `getLlmSettings` |
| Streaming | `streamEvents`, `streamLogs`, `streamRequest` |
| User | `resolveUser` |

## Webhook Verification

```php
use Muxi\Webhook;

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_MUXI_SIGNATURE'] ?? '';

if (!Webhook::verifySignature($payload, $signature, $_ENV['WEBHOOK_SECRET'])) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = Webhook::parse($payload);

switch ($event->status) {
    case 'completed':
        foreach ($event->content as $item) {
            if ($item->type === 'text') echo $item->text;
        }
        break;
    case 'failed':
        echo "Error: {$event->error?->message}";
        break;
    case 'awaiting_clarification':
        echo "Question: {$event->clarification?->question}";
        break;
}
```

## Testing Locally

```bash
cd php
composer install
./vendor/bin/phpunit
```
