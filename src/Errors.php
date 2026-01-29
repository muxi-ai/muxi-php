<?php

declare(strict_types=1);

namespace Muxi;

use Exception;

class MuxiException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode,
        public readonly array $details = []
    ) {
        parent::__construct($errorCode ? "{$errorCode}: {$message}" : $message);
    }
}

class AuthenticationException extends MuxiException {}
class AuthorizationException extends MuxiException {}
class NotFoundException extends MuxiException {}
class ConflictException extends MuxiException {}
class ValidationException extends MuxiException {}

class RateLimitException extends MuxiException
{
    public function __construct(
        string $message,
        int $statusCode,
        public readonly ?int $retryAfter = null,
        array $details = []
    ) {
        parent::__construct('RATE_LIMITED', $message, $statusCode, $details);
    }
}

class ServerException extends MuxiException {}
class ConnectionException extends MuxiException {}

final class ErrorMapper
{
    public static function map(
        int $status,
        ?string $code,
        string $message,
        ?array $details = null,
        ?int $retryAfter = null
    ): MuxiException {
        return match ($status) {
            401 => new AuthenticationException($code ?? 'UNAUTHORIZED', $message, $status, $details ?? []),
            403 => new AuthorizationException($code ?? 'FORBIDDEN', $message, $status, $details ?? []),
            404 => new NotFoundException($code ?? 'NOT_FOUND', $message, $status, $details ?? []),
            409 => new ConflictException($code ?? 'CONFLICT', $message, $status, $details ?? []),
            422 => new ValidationException($code ?? 'VALIDATION_ERROR', $message, $status, $details ?? []),
            429 => new RateLimitException($message ?: 'Too Many Requests', $status, $retryAfter, $details ?? []),
            500, 501, 502, 503, 504 => new ServerException($code ?? 'SERVER_ERROR', $message, $status, $details ?? []),
            default => new MuxiException($code ?? 'ERROR', $message, $status, $details ?? []),
        };
    }
}
