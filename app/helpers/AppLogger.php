<?php

final class AppLogger
{
    public static function write(string $nivel, string $evento, array $contexto = []): void
    {
        $permitidos = ['INFO', 'WARNING', 'ERROR', 'SECURITY'];
        $nivel = in_array($nivel, $permitidos, true) ? $nivel : 'INFO';
        require_once __DIR__ . '/RequestContext.php';
        $contexto = self::sanitize($contexto);
        $linea = (string) json_encode([
            'timestamp' => date(DATE_ATOM), 'level' => $nivel, 'event' => $evento,
            'correlation_id' => RequestContext::correlationId(),
            'origin' => RequestContext::origin(),
            'context' => $contexto,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $dir = defined('LOG_DIR') ? LOG_DIR : '';
        if ($dir !== '' && is_dir($dir) && is_writable($dir)) {
            $prefijo = $nivel === 'SECURITY' ? 'security' : 'application';
            $archivo = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $prefijo . '-' . date('Y-m-d') . '.log';
            if (is_file($archivo) && filesize($archivo) >= (defined('LOG_MAX_BYTES') ? LOG_MAX_BYTES : 10485760)) {
                $archivo .= '.' . date('His');
            }
            if (@file_put_contents($archivo, $linea . PHP_EOL, FILE_APPEND | LOCK_EX) !== false) return;
        }
        error_log($linea);
    }

    public static function info(string $evento, array $contexto = []): void { self::write('INFO', $evento, $contexto); }
    public static function warning(string $evento, array $contexto = []): void { self::write('WARNING', $evento, $contexto); }
    public static function error(string $evento, array $contexto = []): void { self::write('ERROR', $evento, $contexto); }
    public static function security(string $evento, array $contexto = []): void { self::write('SECURITY', $evento, $contexto); }

    private static function sanitize(array $context): array
    {
        foreach (array_keys($context) as $key) {
            if (preg_match('/pass|contras|token|cookie|session|csrf|iban|secret|clave/i', (string) $key)) {
                unset($context[$key]);
                continue;
            }
            if (is_array($context[$key])) $context[$key] = self::sanitize($context[$key]);
            elseif (is_string($context[$key])) {
                // Fingerprint irreversible de diagnóstico: no es un secreto y
                // perderlo impediría correlacionar excepciones iguales.
                if ($key === 'error_fingerprint' && preg_match('/^[a-f0-9]{64}$/', $context[$key])) continue;
                $context[$key] = self::sanitizeString($context[$key]);
            }
        }
        return $context;
    }

    private static function sanitizeString(string $value): string
    {
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);
        if (preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE|REPLACE)\b.+\b(?:FROM|INTO|SET|WHERE|VALUES)\b/is', $value)) {
            return '[SQL_REDACTED]';
        }
        $patterns = [
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i' => '[EMAIL_REDACTED]',
            '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/i' => '[IBAN_REDACTED]',
            '/\b(?:[XYZ]\d{7}[A-Z]|\d{8}[A-Z])\b/i' => '[DOCUMENT_REDACTED]',
            '/\b(?:password|passwd|contrasena|contraseña|token|secret|cookie|session|csrf)\s*[:=]\s*[^\s,;]+/i' => '[SECRET_REDACTED]',
            '/\bmysql:[^\s]+/i' => '[DSN_REDACTED]',
            '/\b[A-Z]:\\(?:[^\\\s]+\\)*[^\s]*/i' => '[PATH_REDACTED]',
            '#(?<![A-Za-z0-9])/(?:var|home|srv|etc|opt|tmp|usr)/[^\s]*#i' => '[PATH_REDACTED]',
            '/\b[A-Za-z0-9+\/_-]{32,}={0,2}\b/' => '[SECRET_REDACTED]',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $value = (string) preg_replace($pattern, $replacement, $value);
        }
        return mb_substr($value, 0, 500);
    }
}
