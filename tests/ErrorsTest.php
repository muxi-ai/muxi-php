<?php

declare(strict_types=1);

namespace Muxi\Tests;

use Muxi\ErrorMapper;
use Muxi\AuthenticationException;
use Muxi\AuthorizationException;
use Muxi\NotFoundException;
use Muxi\ConflictException;
use Muxi\ValidationException;
use Muxi\RateLimitException;
use Muxi\ServerException;
use Muxi\MuxiException;
use PHPUnit\Framework\TestCase;

class ErrorsTest extends TestCase
{
    public function testMap401ToAuthenticationException(): void
    {
        $error = ErrorMapper::map(401, 'INVALID_KEY', 'Invalid API key');
        
        $this->assertInstanceOf(AuthenticationException::class, $error);
        $this->assertEquals(401, $error->statusCode);
        $this->assertEquals('INVALID_KEY', $error->errorCode);
    }

    public function testMap403ToAuthorizationException(): void
    {
        $error = ErrorMapper::map(403, 'FORBIDDEN', 'Access denied');
        
        $this->assertInstanceOf(AuthorizationException::class, $error);
        $this->assertEquals(403, $error->statusCode);
    }

    public function testMap404ToNotFoundException(): void
    {
        $error = ErrorMapper::map(404, 'NOT_FOUND', 'Resource not found');
        
        $this->assertInstanceOf(NotFoundException::class, $error);
        $this->assertEquals(404, $error->statusCode);
    }

    public function testMap409ToConflictException(): void
    {
        $error = ErrorMapper::map(409, 'CONFLICT', 'Already exists');
        
        $this->assertInstanceOf(ConflictException::class, $error);
        $this->assertEquals(409, $error->statusCode);
    }

    public function testMap422ToValidationException(): void
    {
        $error = ErrorMapper::map(422, 'VALIDATION_ERROR', 'Invalid input');
        
        $this->assertInstanceOf(ValidationException::class, $error);
        $this->assertEquals(422, $error->statusCode);
    }

    public function testMap429ToRateLimitException(): void
    {
        $error = ErrorMapper::map(429, null, 'Rate limited', null, 60);
        
        $this->assertInstanceOf(RateLimitException::class, $error);
        $this->assertEquals(429, $error->statusCode);
        $this->assertEquals(60, $error->retryAfter);
    }

    public function testMap5xxToServerException(): void
    {
        $error = ErrorMapper::map(500, 'INTERNAL', 'Server error');
        
        $this->assertInstanceOf(ServerException::class, $error);
        $this->assertEquals(500, $error->statusCode);
    }

    public function testMapUnknownToMuxiException(): void
    {
        $error = ErrorMapper::map(418, 'TEAPOT', "I'm a teapot");
        
        $this->assertInstanceOf(MuxiException::class, $error);
        $this->assertNotInstanceOf(AuthenticationException::class, $error);
    }
}
