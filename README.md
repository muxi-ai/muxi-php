# MUXI PHP SDK

Official PHP SDK for [MUXI](https://muxi.org) — infrastructure for AI agents.

**Highlights**
- Pure PHP with curl (no external dependencies)
- Built-in retries, idempotency, and typed errors
- Streaming helpers for chat/audio and deploy/log tails

> Need deeper usage notes? See the [User Guide](https://github.com/muxi-ai/muxi-php/blob/main/USER_GUIDE.md) for streaming, retries, and auth details.

## Requirements

- PHP 8.2+
- ext-curl
- ext-json
- ext-openssl

## Installation

```bash
composer require muxi/muxi-php
```

## Quick Start

### Server Management (Control Plane)

```php
<?php

use Muxi\ServerClient;
use Muxi\ServerConfig;

$server = new ServerClient([
    'url' => getenv('MUXI_SERVER_URL'),
    'keyId' => getenv('MUXI_KEY_ID'),
    'secretKey' => getenv('MUXI_SECRET_KEY'),
]);

// List formations
$formations = $server->listFormations();
foreach ($formations['formations'] as $f) {
    echo "{$f['id']}: {$f['status']}\n";
}

// Get server status
$status = $server->status();
echo "Uptime: {$status['uptime']}s\n";
```

### Formation Usage (Runtime API)

```php
<?php

use Muxi\FormationClient;

// Connect via server proxy
$client = new FormationClient([
    'formationId' => 'my-bot',
    'serverUrl' => getenv('MUXI_SERVER_URL'),
    'adminKey' => getenv('MUXI_ADMIN_KEY'),
    'clientKey' => getenv('MUXI_CLIENT_KEY'),
]);

// Or connect directly to formation
$client = new FormationClient([
    'url' => 'http://localhost:8001',
    'adminKey' => getenv('MUXI_ADMIN_KEY'),
    'clientKey' => getenv('MUXI_CLIENT_KEY'),
]);

// Chat (non-streaming)
$response = $client->chat(['message' => 'Hello!'], 'user123');
echo $response['message'];

// Chat (streaming)
$client->chatStream(['message' => 'Tell me a story'], 'user123', function($event) {
    $data = json_decode($event['data'], true);
    if (isset($data['text'])) {
        echo $data['text'];
    }
});

// Health check
$health = $client->health();
echo "Status: {$health['status']}\n";
```

## Webhook Verification

```php
<?php

use Muxi\Webhook;
use Muxi\WebhookVerificationException;

// In your webhook handler
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_MUXI_SIGNATURE'] ?? '';
$secret = getenv('WEBHOOK_SECRET');

if (!Webhook::verifySignature($payload, $signature, $secret)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = Webhook::parse($payload);

switch ($event->status) {
    case 'completed':
        foreach ($event->content as $item) {
            if ($item->type === 'text') {
                echo $item->text;
            }
        }
        break;
    case 'failed':
        echo "Error: {$event->error->message}\n";
        break;
    case 'awaiting_clarification':
        echo "Question: {$event->clarification->question}\n";
        break;
}
```

## Configuration

### Environment Variables

- `MUXI_DEBUG=1` - Enable debug logging

### Client Options

```php
$server = new ServerClient([
    'url' => 'https://muxi.example.com:7890',
    'keyId' => 'your-key-id',
    'secretKey' => 'your-secret-key',
    'timeout' => 30,      // Request timeout in seconds
    'maxRetries' => 3,    // Retry on 429/5xx errors
    'debug' => true,      // Enable debug logging
]);
```

## Error Handling

```php
use Muxi\NotFoundException;
use Muxi\AuthenticationException;
use Muxi\RateLimitException;
use Muxi\MuxiException;

try {
    $server->getFormation('nonexistent');
} catch (NotFoundException $e) {
    echo "Not found: {$e->getMessage()}\n";
} catch (AuthenticationException $e) {
    echo "Auth failed: {$e->getMessage()}\n";
} catch (RateLimitException $e) {
    echo "Rate limited. Retry after: {$e->retryAfter}s\n";
} catch (MuxiException $e) {
    echo "Error: {$e->getMessage()} ({$e->statusCode})\n";
}
```

## License

MIT
