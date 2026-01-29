<?php

declare(strict_types=1);

namespace Muxi\Tests;

use Muxi\Webhook;
use Muxi\WebhookVerificationException;
use PHPUnit\Framework\TestCase;

class WebhookTest extends TestCase
{
    private string $secret = 'test_webhook_secret';
    private string $payload = '{"id":"req123","status":"completed","response":[{"type":"text","text":"Hello"}]}';

    private function createSignature(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $message = "{$timestamp}.{$payload}";
        $signature = hash_hmac('sha256', $message, $secret);
        return "t={$timestamp},v1={$signature}";
    }

    public function testVerifySignatureValidSignature(): void
    {
        $sigHeader = $this->createSignature($this->payload, $this->secret);
        
        $this->assertTrue(Webhook::verifySignature($this->payload, $sigHeader, $this->secret));
    }

    public function testVerifySignatureInvalidSignature(): void
    {
        $sigHeader = 't=' . time() . ',v1=invalidsignature';
        
        $this->assertFalse(Webhook::verifySignature($this->payload, $sigHeader, $this->secret));
    }

    public function testVerifySignatureNullHeader(): void
    {
        $this->assertFalse(Webhook::verifySignature($this->payload, null, $this->secret));
    }

    public function testVerifySignatureEmptyHeader(): void
    {
        $this->assertFalse(Webhook::verifySignature($this->payload, '', $this->secret));
    }

    public function testVerifySignatureExpiredTimestamp(): void
    {
        $oldTimestamp = time() - 600; // 10 minutes ago
        $sigHeader = $this->createSignature($this->payload, $this->secret, $oldTimestamp);
        
        $this->assertFalse(Webhook::verifySignature($this->payload, $sigHeader, $this->secret));
    }

    public function testVerifySignatureMissingSecret(): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('Webhook secret is required');
        
        Webhook::verifySignature($this->payload, 't=123,v1=abc', '');
    }

    public function testParseCompletedPayload(): void
    {
        $event = Webhook::parse($this->payload);
        
        $this->assertEquals('req123', $event->requestId);
        $this->assertEquals('completed', $event->status);
        $this->assertCount(1, $event->content);
        $this->assertEquals('text', $event->content[0]->type);
        $this->assertEquals('Hello', $event->content[0]->text);
    }

    public function testParseFailedPayload(): void
    {
        $payload = [
            'id' => 'req456',
            'status' => 'failed',
            'error' => ['code' => 'TIMEOUT', 'message' => 'Request timed out']
        ];
        
        $event = Webhook::parse($payload);
        
        $this->assertEquals('failed', $event->status);
        $this->assertNotNull($event->error);
        $this->assertEquals('TIMEOUT', $event->error->code);
        $this->assertEquals('Request timed out', $event->error->message);
    }

    public function testParseClarificationPayload(): void
    {
        $payload = [
            'id' => 'req789',
            'status' => 'awaiting_clarification',
            'clarification_question' => 'Which file do you mean?'
        ];
        
        $event = Webhook::parse($payload);
        
        $this->assertEquals('awaiting_clarification', $event->status);
        $this->assertNotNull($event->clarification);
        $this->assertEquals('Which file do you mean?', $event->clarification->question);
    }

    public function testParseInvalidJson(): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('Invalid JSON payload');
        
        Webhook::parse('not json');
    }
}
