<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/AppLogger.php';

final class BackupStorage
{
    /**
     * Devuelve una ruta impredecible incluso cuando varios procesos arrancan
     * en el mismo microsegundo. El nonce evita que el nombre sea el lock.
     */
    public static function uniqueArtifactPath(string $dir, string $prefix, string $suffix): string
    {
        self::ensureDirectory($dir);
        $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d_His_u\Z');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $nonce = bin2hex(random_bytes(8));
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR
                . $prefix . $timestamp . '_' . $nonce . $suffix;
            if (!file_exists($path) && !file_exists($path . '.sha256') && !file_exists($path . '.manifest.json')) {
                return $path;
            }
        }
        throw new RuntimeException('No se pudo reservar un nombre único de backup.');
    }

    public static function writeExclusive(string $path, string $contents): void
    {
        $handle = @fopen($path, 'xb');
        if (!$handle) throw new RuntimeException('El artefacto ya existe o no se puede crear de forma exclusiva.');
        try {
            if (fwrite($handle, $contents) === false || !fflush($handle)) {
                throw new RuntimeException('No se pudo completar el artefacto de backup.');
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($path);
            throw $e;
        }
        fclose($handle);
        @chmod($path, 0640);
    }

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
        if (file_put_contents($file . '.sha256', $hash . '  ' . basename($file) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir el sidecar SHA-256.');
        }
        @chmod($file, 0640);
        @chmod($file . '.sha256', 0640);
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
        foreach (['.sha256', '.manifest.json'] as $suffix) {
            if (is_file($file . $suffix) && !@copy($file . $suffix, $target . $suffix)) {
                @unlink($target);
                @unlink($target . '.sha256');
                @unlink($target . '.manifest.json');
                throw new RuntimeException('No se pudieron copiar los metadatos del backup externo.');
            }
        }
        return $target;
    }

    public static function verifyArtifact(string $file): array
    {
        if (!is_file($file) || !is_file($file . '.sha256') || !is_file($file . '.manifest.json')) {
            throw new RuntimeException('El backup no tiene artefacto, checksum y manifiesto completos.');
        }
        $checksumLine = trim((string) file_get_contents($file . '.sha256'));
        if (!preg_match('/^([a-f0-9]{64})\s{2}(.+)$/i', $checksumLine, $match) || basename($file) !== $match[2]) {
            throw new RuntimeException('El sidecar SHA-256 no es válido.');
        }
        $actual = hash_file('sha256', $file);
        if ($actual === false || !hash_equals(strtolower($match[1]), strtolower($actual))) {
            throw new RuntimeException('El backup no supera SHA-256.');
        }
        $manifest = json_decode((string) file_get_contents($file . '.manifest.json'), true);
        if (!is_array($manifest)
            || ($manifest['artifact'] ?? '') !== basename($file)
            || (int) ($manifest['size_bytes'] ?? -1) !== filesize($file)
            || !hash_equals(strtolower((string) ($manifest['sha256'] ?? '')), strtolower($actual))) {
            throw new RuntimeException('El manifiesto del backup no coincide con el artefacto.');
        }
        return $manifest;
    }

    public static function rotate(string $dir, string $prefix): int
    {
        $files = array_values(array_filter(
            glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $prefix . '*') ?: [],
            fn($f) => is_file($f) && !str_ends_with($f, '.sha256') && !str_ends_with($f, '.manifest.json')
        ));
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $verified = [];
        foreach ($files as $file) {
            try {
                self::verifyArtifact($file);
                $verified[] = $file;
            } catch (Throwable $e) {
                // Un artefacto incompleto se conserva para diagnóstico. Nunca
                // puede provocar el borrado de una copia válida.
            }
        }
        if (count($verified) < 2) return 0;
        $keep = [];
        foreach ([['Y-m-d', COPIAS_DIARIAS], ['o-W', COPIAS_SEMANALES], ['Y-m', COPIAS_MENSUALES]] as [$format, $limit]) {
            $buckets = [];
            foreach ($verified as $file) {
                $bucket = date($format, filemtime($file));
                if (!isset($buckets[$bucket]) && count($buckets) < $limit) {
                    $buckets[$bucket] = true;
                    $keep[$file] = true;
                }
            }
        }
        $deleted = 0;
        // La copia válida más reciente queda protegida aunque una política se
        // configure erróneamente con todos los límites a cero.
        $keep[$verified[0]] = true;
        foreach ($verified as $file) {
            if (!isset($keep[$file]) && @unlink($file)) {
                @unlink($file . '.sha256');
                @unlink($file . '.manifest.json');
                $deleted++;
            }
        }
        return $deleted;
    }
}
