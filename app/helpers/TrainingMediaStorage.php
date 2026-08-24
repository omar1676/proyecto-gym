<?php

require_once __DIR__ . '/../config/config.php';

/** Almacenamiento privado de imágenes de ejercicios. */
final class TrainingMediaStorage
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg','jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /** @return array{storage_key:string,mime_type:string,size_bytes:int,width:int,height:int} */
    public static function storeUploadedImage(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('La imagen no pudo recibirse correctamente.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = is_file($tmp) ? filesize($tmp) : false;
        if ($tmp === '' || $size === false || $size < 1 || $size > TRAINING_MEDIA_MAX_BYTES) {
            throw new InvalidArgumentException('La imagen supera el tamaño permitido o está vacía.');
        }
        if (!is_uploaded_file($tmp) && APP_ENV !== 'test') {
            throw new InvalidArgumentException('El archivo no procede de una subida válida.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset(self::MIME_EXTENSIONS[$mime]) || !in_array($extension, self::MIME_EXTENSIONS[$mime], true)) {
            throw new InvalidArgumentException('Formato de imagen no permitido.');
        }
        $dimensions = @getimagesize($tmp);
        if (!is_array($dimensions) || ($dimensions[0] ?? 0) < 1 || ($dimensions[1] ?? 0) < 1
            || $dimensions[0] > 8000 || $dimensions[1] > 8000) {
            throw new InvalidArgumentException('La imagen está dañada o tiene dimensiones no permitidas.');
        }
        self::ensureDirectory();
        $storageKey = 'training_' . bin2hex(random_bytes(24)) . '.' . self::MIME_EXTENSIONS[$mime][0];
        $destination = rtrim(TRAINING_MEDIA_DIR, '/\\') . DIRECTORY_SEPARATOR . $storageKey;
        $stored = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $destination) : copy($tmp, $destination);
        if (!$stored) throw new RuntimeException('No se pudo almacenar la imagen.');
        @chmod($destination, 0640);
        return [
            'storage_key' => $storageKey,
            'mime_type' => $mime,
            'size_bytes' => (int) $size,
            'width' => (int) $dimensions[0],
            'height' => (int) $dimensions[1],
        ];
    }

    /** @return array{path:string,mime_type:string,size_bytes:int}|null */
    public static function resolve(string $storageKey): ?array
    {
        if (!preg_match('/^training_[a-f0-9]{48}\.(?:jpg|png|webp)$/', $storageKey)) return null;
        $root = realpath(TRAINING_MEDIA_DIR);
        if ($root === false) return null;
        $path = realpath($root . DIRECTORY_SEPARATOR . $storageKey);
        if ($path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) return null;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        $extension = strtolower(pathinfo($storageKey, PATHINFO_EXTENSION));
        if (!isset(self::MIME_EXTENSIONS[$mime]) || !in_array($extension, self::MIME_EXTENSIONS[$mime], true)) return null;
        return ['path' => $path, 'mime_type' => $mime, 'size_bytes' => (int) filesize($path)];
    }

    public static function delete(string $storageKey): bool
    {
        $resolved = self::resolve($storageKey);
        return $resolved === null || @unlink($resolved['path']);
    }

    public static function validateVideoReference(string $url): string
    {
        $url = trim($url);
        if (strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Referencia de vídeo no válida.');
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException('La referencia de vídeo debe utilizar HTTPS.');
        }
        return $url;
    }

    private static function ensureDirectory(): void
    {
        if (!is_dir(TRAINING_MEDIA_DIR) && !mkdir(TRAINING_MEDIA_DIR, 0750, true) && !is_dir(TRAINING_MEDIA_DIR)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento privado de Training.');
        }
        // El despliegue inmutable enlaza este directorio con almacenamiento
        // compartido fuera del document root. La raíz procede de configuración
        // confiable y resolve() comprueba siempre el realpath final.
    }
}
