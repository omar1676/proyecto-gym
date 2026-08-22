<?php

require_once __DIR__ . '/AppLogger.php';

/** Telemetría de excepciones sin mensaje, SQL, rutas absolutas ni PII. */
final class SafeException
{
    /** @return array{component:string,error_class:string,safe_code:string,error_fingerprint:string} */
    public static function context(Throwable $error, string $component): array
    {
        $component = preg_replace('/[^a-z0-9_.:-]/i', '_', $component) ?: 'unknown';
        $code = (string) $error->getCode();
        if (!preg_match('/^[A-Z0-9_-]{1,12}$/i', $code)) $code = 'UNCLASSIFIED';
        $fingerprint = hash('sha256', implode('|', [
            get_class($error), $code, basename($error->getFile()), (string) $error->getLine(),
        ]));
        return [
            'component' => mb_substr($component, 0, 80),
            'error_class' => get_class($error),
            'safe_code' => $code,
            'error_fingerprint' => $fingerprint,
        ];
    }

    public static function log(string $event, Throwable $error, string $component): void
    {
        AppLogger::error($event, self::context($error, $component));
    }
}
