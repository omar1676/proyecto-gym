<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/AppLogger.php';

final class BackupStorage
{
    public static function ensureDirectory(string $dir): void
    {
        $dir = rtrim($dir, '/\\');
        $public = realpath(dirname(__DIR__, 2) . '/public');
        $parent = realpath(dirname($dir));
        $normalized = $parent ? $parent . DIRECTORY_SEPARATOR . basename($dir) : $dir;
        if ($dir === '' || ($public && str_starts_with(strtolower(str_replace('\\', '/', $normalized)), strtolower(str_replace('\\', '/', $public)) . '/'))) {
            throw new RuntimeException('El directorio de copias no puede estar dentro de public/.');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) throw new RuntimeException('No se pudo crear el directorio de copias.');
        if (!is_writable($dir)) throw new RuntimeException('El directorio de copias no tiene escritura.');
        @file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\nDeny from all\nOptions -Indexes\n");
        if (!is_file($dir . DIRECTORY_SEPARATOR . 'index.html')) @file_put_contents($dir . DIRECTORY_SEPARATOR . 'index.html', '');
    }

    public static function checksum(string $file): string
    {
        $hash = hash_file('sha256', $file);
        if ($hash === false) throw new RuntimeException('No se pudo calcular SHA-256.');
        file_put_contents($file . '.sha256', $hash . '  ' . basename($file) . PHP_EOL, LOCK_EX);
        return $hash;
    }

    public static function externalCopy(string $file): ?string
    {
        if (!defined('COPIAS_EXTERNAS_DIR') || COPIAS_EXTERNAS_DIR === '') return null;
        self::ensureDirectory(COPIAS_EXTERNAS_DIR);
        $target = rtrim(COPIAS_EXTERNAS_DIR, '/\\') . DIRECTORY_SEPARATOR . basename($file);
        if (!@copy($file, $target) || hash_file('sha256', $file) !== hash_file('sha256', $target)) {
            @unlink($target);
            throw new RuntimeException('La copia externa no superó la verificación SHA-256.');
        }
        @copy($file . '.sha256', $target . '.sha256');
        return $target;
    }

    public static function rotate(string $dir, string $prefix): int
    {
        $files = array_values(array_filter(glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $prefix . '*') ?: [], fn($f) => is_file($f) && !str_ends_with($f, '.sha256')));
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $keep = [];
        foreach ([['Y-m-d', COPIAS_DIARIAS], ['o-W', COPIAS_SEMANALES], ['Y-m', COPIAS_MENSUALES]] as [$format, $limit]) {
            $buckets = [];
            foreach ($files as $file) {
                $bucket = date($format, filemtime($file));
                if (!isset($buckets[$bucket]) && count($buckets) < $limit) {
                    $buckets[$bucket] = true;
                    $keep[$file] = true;
                }
            }
        }
        $deleted = 0;
        foreach ($files as $file) {
            if (!isset($keep[$file]) && @unlink($file)) {
                @unlink($file . '.sha256');
                $deleted++;
            }
        }
        return $deleted;
    }
}
