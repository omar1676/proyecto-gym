<?php
require_once __DIR__ . '/AppLogger.php';
require_once __DIR__ . '/SafeException.php';

final class ErrorHandler
{
    public static function register(): void
    {
        set_exception_handler(static function (Throwable $e): void {
            SafeException::log('uncaught_exception', $e, 'http.request');
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=UTF-8');
            }
            if (defined('APP_ENV') && APP_ENV === 'development') {
                echo '<h1>Error interno</h1><pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>';
            } else {
                echo '<h1>No se pudo completar la solicitud</h1><p>Inténtalo de nuevo más tarde.</p>';
            }
        });
    }
}
