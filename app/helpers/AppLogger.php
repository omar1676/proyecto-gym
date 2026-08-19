<?php

final class AppLogger
{
    public static function write(string $nivel, string $evento, array $contexto = []): void
    {
        $permitidos = ['INFO', 'WARNING', 'ERROR', 'SECURITY'];
        $nivel = in_array($nivel, $permitidos, true) ? $nivel : 'INFO';
        foreach (array_keys($contexto) as $clave) {
            if (preg_match('/pass|contras|token|cookie|session|csrf|iban|secret|clave/i', (string) $clave)) {
                unset($contexto[$clave]);
            }
        }
        $linea = (string) json_encode([
            'timestamp' => date(DATE_ATOM), 'level' => $nivel, 'event' => $evento,
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
}
