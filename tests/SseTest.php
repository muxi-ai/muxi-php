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
}
