<?php

require_once __DIR__ . '/../config/config.php';

final class SecurityHeaders
{
    private static function https(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function apply(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) return;
        if (in_array(APP_ENV, ['staging', 'production'], true) && !self::https()) {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
            if ($host !== '' && preg_match('/^[a-z0-9.-]+(?::\d+)?$/i', $host)) {
                header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
                exit;
            }
        }
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; font-src 'self' data:; img-src 'self' https://cdn.jsdelivr.net data: blob:");
        if (APP_ENV === 'production' && self::https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        if (APP_ENV === 'test') header('X-App-Environment: test');
    }
}
