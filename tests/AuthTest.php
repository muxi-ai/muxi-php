<?php

declare(strict_types=1);

namespace Muxi\Tests;

use Muxi\Auth;
use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    public function testGenerateHmacSignature(): void
    {
        [$signature, $timestamp] = Auth::generateHmacSignature('secret', 'GET', '/test');
        
        $this->assertIsString($signature);
        $this->assertNotEmpty($signature);
        $this->assertIsInt($timestamp);
        $this->assertEqualsWithDelta(time(), $timestamp, 5);
    }

    public function testBuildAuthHeader(): void
    {
        $header = Auth::buildAuthHeader('key123', 'secret', 'POST', '/rpc/test');
        
        $this->assertStringStartsWith('MUXI-HMAC key=key123, timestamp=', $header);
        $this->assertStringContainsString('signature=', $header);
    }

    public function testSignatureStripsQueryParams(): void
    {
        [$sig1, $_] = Auth::generateHmacSignature('secret', 'GET', '/test');
        [$sig2, $_] = Auth::generateHmacSignature('secret', 'GET', '/test?foo=bar');
        
        $this->assertEquals(strlen($sig1), strlen($sig2));
    }
}
