<?php

declare(strict_types=1);

namespace Muxi\Tests;

use Muxi\FormationTransport;
use Muxi\MuxiException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SseTest extends TestCase
{
    private function transport(): FormationTransport
    {
        class_exists(\Muxi\FormationClient::class);
        return new FormationTransport('http://example.com', 'admin-key', 'client-key');
    }

    public function testFlushesEventOnlyDoneFrame(): void
    {
        $transport = $this->transport();
        $method = new ReflectionMethod(FormationTransport::class, 'flushSseEvent');
        $method->setAccessible(true);

        $event = 'done';
        $dataParts = [];
        $parsed = $method->invokeArgs($transport, [&$event, &$dataParts]);

        $this->assertSame(['event' => 'done', 'data' => ''], $parsed);
        $this->assertNull($event);
        $this->assertSame([], $dataParts);
    }

    public function testPreservesMultilineData(): void
    {
        $transport = $this->transport();
        $method = new ReflectionMethod(FormationTransport::class, 'flushSseEvent');
        $method->setAccessible(true);

        $event = 'planning';
        $dataParts = ['one', 'two'];
        $parsed = $method->invokeArgs($transport, [&$event, &$dataParts]);

        $this->assertSame(['event' => 'planning', 'data' => "one\ntwo"], $parsed);
    }

    public function testRouteLevelErrorsThrowMuxiException(): void
    {
        $transport = $this->transport();
        $method = new ReflectionMethod(FormationTransport::class, 'throwIfRouteError');
        $method->setAccessible(true);

        $this->expectException(MuxiException::class);
        $this->expectExceptionMessage('RUNTIME_ERROR: boom');

        $method->invoke($transport, ['event' => 'error', 'data' => '{"error":"boom","type":"RUNTIME_ERROR"}']);
    }

    public function testParseUiWidgetsFromUiFrame(): void
    {
        $event = [
            'event' => 'ui',
            'data' => '{"ui":[{"type":"options","id":"w1","prompt":"Which?",'
                . '"options":[{"value":"us","label":"United States"}]},'
                . '{"type":"action_link","id":"w2","label":"Dash","url":"https://x.io"}]}',
        ];

        $widgets = \Muxi\FormationClient::parseUiWidgets($event);

        $this->assertCount(2, $widgets);
        $this->assertSame('options', $widgets[0]['type']);
        $this->assertSame('United States', $widgets[0]['options'][0]['label']);
        $this->assertSame('https://x.io', $widgets[1]['url']);
    }

    public function testParseUiWidgetsIgnoresOtherFrames(): void
    {
        $this->assertSame([], \Muxi\FormationClient::parseUiWidgets(['event' => 'message', 'data' => 'hi']));
        $this->assertSame([], \Muxi\FormationClient::parseUiWidgets(['event' => 'ui', 'data' => 'not json']));
        $this->assertSame([], \Muxi\FormationClient::parseUiWidgets(['event' => 'ui', 'data' => '{"ui":{}}']));
    }

    public function testUnwrapEnvelopeSurfacesIdempotencyKey(): void
    {
        $transport = $this->transport();
        $method = new ReflectionMethod(FormationTransport::class, 'unwrapEnvelope');
        $method->setAccessible(true);

        $out = $method->invoke($transport, [
            'object' => 'api_response',
            'timestamp' => 123,
            'request' => ['id' => 'req-1', 'idempotency_key' => 'idem-42'],
            'data' => ['foo' => 'bar'],
            'success' => true,
        ]);

        $this->assertSame('bar', $out['foo']);
        $this->assertSame('req-1', $out['request_id']);
        $this->assertSame('idem-42', $out['idempotency_key']);
    }

    public function testUnwrapEnvelopeOmitsIdempotencyKeyWhenAbsent(): void
    {
        $transport = $this->transport();
        $method = new ReflectionMethod(FormationTransport::class, 'unwrapEnvelope');
        $method->setAccessible(true);

        $out = $method->invoke($transport, [
            'object' => 'api_response',
            'request' => ['id' => 'req-1'],
            'data' => ['foo' => 'bar'],
            'success' => true,
        ]);

        $this->assertArrayNotHasKey('idempotency_key', $out);
    }
}
