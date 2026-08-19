<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/MigrationException.php';

/** Staging privado de archivos de importación. Nunca acepta nombres del cliente. */
final class MigrationStorage
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?: IMPORT_DIR, '/\\');
        $this->ensurePrivateDirectory();
    }

    private function ensurePrivateDirectory(): void
    {
        $public = realpath(dirname(__DIR__, 2) . '/public');
        $parent = realpath(dirname($this->dir));
        $prospective = $parent ? $parent . DIRECTORY_SEPARATOR . basename($this->dir) : $this->dir;
        $normal = static fn(string $path): string => strtolower(str_replace('\\', '/', $path));
        if ($this->dir === '' || ($public && str_starts_with($normal($prospective), $normal($public) . '/'))) {
            throw new MigrationException('El staging de importación no puede estar dentro de public/.', 'unsafe_storage');
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true)) {
            throw new MigrationException('No se pudo preparar el staging de importación.', 'storage_unavailable');
        }
        if (!is_writable($this->dir)) {
            throw new MigrationException('El staging de importación no tiene escritura.', 'storage_unavailable');
        }
        @file_put_contents($this->dir . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\nDeny from all\nOptions -Indexes\n", LOCK_EX);
        if (!is_file($this->dir . DIRECTORY_SEPARATOR . 'index.html')) {
            @file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'index.html', '', LOCK_EX);
        }
    }

    public function storeUploaded(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new MigrationException('La subida del archivo no se completó.', 'upload_failed');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new MigrationException('El archivo subido no es válido.', 'invalid_upload');
        }
        return $this->store($tmp, true);
    }

    /** Solo para CLI/tests y procesos administrativos con una ruta ya autorizada. */
    public function storePath(string $source): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new MigrationException('La importación por ruta solo está disponible por CLI.', 'cli_only');
        }
        return $this->store($source, false);
    }

    private function store(string $source, bool $uploaded): array
    {
        $real = realpath($source);
        if (!$real || !is_file($real) || !is_readable($real)) {
            throw new MigrationException('No se puede leer el archivo de importación.', 'file_unreadable');
        }
        $size = filesize($real);
        if ($size === false || $size <= 0 || $size > IMPORT_MAX_BYTES) {
            throw new MigrationException('El archivo está vacío o supera el tamaño permitido.', 'file_size');
        }
        $key = bin2hex(random_bytes(16)) . '.csv';
        $target = $this->path($key);
        $ok = $uploaded ? @move_uploaded_file($real, $target) : @copy($real, $target);
        if (!$ok) {
            throw new MigrationException('No se pudo guardar el archivo en staging.', 'storage_write_failed');
        }
        @chmod($target, 0640);
        $hash = hash_file('sha256', $target);
        if ($hash === false) {
            @unlink($target);
            throw new MigrationException('No se pudo verificar el archivo guardado.', 'hash_failed');
        }
        return ['storage_key' => $key, 'path' => $target, 'size' => (int) $size, 'hash' => $hash];
    }

    public function path(string $key): string
    {
        if (!preg_match('/^[a-f0-9]{32}\.csv$/', $key)) {
            throw new MigrationException('Referencia de archivo no válida.', 'invalid_storage_key');
        }
        return $this->dir . DIRECTORY_SEPARATOR . $key;
    }

    public function verify(string $key, string $expectedHash): string
    {
        $path = $this->path($key);
        if (!is_file($path) || !is_readable($path)) {
            throw new MigrationException('El archivo temporal ya no está disponible.', 'staged_file_missing');
        }
        $actual = hash_file('sha256', $path);
        if ($actual === false || !hash_equals($expectedHash, $actual)) {
            throw new MigrationException('El archivo temporal no supera la verificación.', 'staged_file_changed');
        }
        return $path;
    }

    public function delete(?string $key): bool
    {
        if (!$key) return true;
        try {
            $path = $this->path($key);
        } catch (MigrationException) {
            return false;
        }
        return !is_file($path) || @unlink($path);
    }
}
