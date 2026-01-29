<?php

declare(strict_types=1);

namespace Muxi;

final class Auth
{
    public static function generateHmacSignature(string $secretKey, string $method, string $path): array
    {
        $timestamp = time();
        $signPath = explode('?', $path)[0];
        $message = "{$timestamp};{$method};{$signPath}";
        
        $signature = base64_encode(hash_hmac('sha256', $message, $secretKey, true));
        
        return [$signature, $timestamp];
    }

    public static function buildAuthHeader(string $keyId, string $secretKey, string $method, string $path): string
    {
        [$signature, $timestamp] = self::generateHmacSignature($secretKey, $method, $path);
        return "MUXI-HMAC key={$keyId}, timestamp={$timestamp}, signature={$signature}";
    }
}
