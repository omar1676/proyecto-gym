<?php

require_once __DIR__ . '/ClientIp.php';

final class RequestContext
{
    private const ORIGINS = ['WEB', 'CRON', 'SYSTEM', 'API', 'MOBILE'];
    private static ?string $correlationId = null;
    private static ?string $origin = null;

    public static function bootstrap(?string $origin = null): void
    {
        $origin ??= PHP_SAPI === 'cli' ? 'SYSTEM' : 'WEB';
        if (!in_array($origin, self::ORIGINS, true)) $origin = 'SYSTEM';
        self::$origin = $origin;
        self::$correlationId ??= self::uuidV4();
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('X-Correlation-ID: ' . self::$correlationId);
        }
    }

    public static function correlationId(): string
    {
        if (self::$correlationId === null) self::bootstrap();
        return (string) self::$correlationId;
    }

    public static function origin(): string
    {
        if (self::$origin === null) self::bootstrap();
        return (string) self::$origin;
    }

    public static function clientIp(): ?string
    {
        return ClientIp::address();
    }

    public static function newId(): string
    {
        return self::uuidV4();
    }

    /** Solo para tests aislados. Nunca acepta el valor de una petición web. */
    public static function resetForTests(?string $correlationId = null, ?string $origin = null): void
    {
        if (defined('APP_ENV') && APP_ENV !== 'test') {
            throw new RuntimeException('RequestContext solo se puede reiniciar en test.');
        }
        if ($correlationId !== null && !preg_match('/^[a-f0-9-]{36}$/i', $correlationId)) {
            throw new InvalidArgumentException('Correlation ID de test no válido.');
        }
        self::$correlationId = $correlationId;
        self::$origin = $origin;
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
