<?php

declare(strict_types=1);

namespace Muxi;

class WebhookVerificationException extends \Exception
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}

readonly class ContentItem
{
    public function __construct(
        public string $type,
        public ?string $text = null,
        public ?array $file = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? 'text',
            text: $data['text'] ?? null,
            file: $data['file'] ?? null
        );
    }
}

readonly class ErrorDetails
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $trace = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            code: $data['code'] ?? 'unknown',
            message: $data['message'] ?? 'Unknown error',
            trace: $data['trace'] ?? null
        );
    }
}

readonly class Clarification
{
    public function __construct(
        public string $question,
        public ?string $clarificationRequestId = null,
        public ?string $originalMessage = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            question: $data['clarification_question'] ?? '',
            clarificationRequestId: $data['clarification_request_id'] ?? null,
            originalMessage: $data['original_message'] ?? null
        );
    }
}

readonly class WebhookEvent
{
    public function __construct(
        public string $requestId,
        public string $status,
        public int $timestamp,
        public array $content,
        public ?ErrorDetails $error,
        public ?Clarification $clarification,
        public ?string $formationId,
        public ?string $userId,
        public ?float $processingTime,
        public string $processingMode,
        public ?string $webhookUrl,
        public array $raw
    ) {}

    public static function fromArray(array $data): self
    {
        $content = array_map(
            fn($item) => ContentItem::fromArray($item),
            $data['response'] ?? []
        );

        $error = isset($data['error']) ? ErrorDetails::fromArray($data['error']) : null;
        $clarification = ($data['status'] ?? '') === 'awaiting_clarification'
            ? Clarification::fromArray($data)
            : null;

        return new self(
            requestId: $data['id'] ?? '',
            status: $data['status'] ?? 'unknown',
            timestamp: $data['timestamp'] ?? 0,
            content: $content,
            error: $error,
            clarification: $clarification,
            formationId: $data['formation_id'] ?? null,
            userId: $data['user_id'] ?? null,
            processingTime: $data['processing_time'] ?? null,
            processingMode: $data['processing_mode'] ?? 'async',
            webhookUrl: $data['webhook_url'] ?? null,
            raw: $data
        );
    }
}

final class Webhook
{
    public static function verifySignature(
        string $payload,
        ?string $signatureHeader,
        string $secret,
        int $toleranceSeconds = 300
    ): bool {
        if (empty($signatureHeader)) {
            return false;
        }

        if (empty($secret)) {
            throw new WebhookVerificationException('Webhook secret is required');
        }

        // Parse signature header: "t=1234567890,v1=abc123..."
        try {
            $parts = [];
            foreach (explode(',', $signatureHeader) as $part) {
                [$key, $value] = explode('=', $part, 2);
                $parts[$key] = $value;
            }

            $timestamp = (int)($parts['t'] ?? 0);
            $signature = $parts['v1'] ?? null;

            if (!$timestamp || !$signature) {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }

        // Check timestamp tolerance
        $currentTime = time();
        if (abs($currentTime - $timestamp) > $toleranceSeconds) {
            return false;
        }

        // Compute expected signature
        $message = "{$timestamp}.{$payload}";
        $expected = hash_hmac('sha256', $message, $secret);

        // Constant-time comparison
        return hash_equals($expected, $signature);
    }

    public static function parse(string|array $payload): WebhookEvent
    {
        if (is_array($payload)) {
            $data = $payload;
        } else {
            try {
                $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new WebhookVerificationException("Invalid JSON payload: {$e->getMessage()}");
            }
        }

        return WebhookEvent::fromArray($data);
    }
}
