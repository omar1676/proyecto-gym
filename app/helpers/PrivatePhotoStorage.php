<?php

require_once __DIR__ . '/../config/config.php';

final class PrivatePhotoStorage
{
    private const MIME_TO_EXT = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /** @return array{path:string,mime:string,extension:string}|null */
    public static function resolve(string $filename): ?array
    {
        if ($filename === '' || basename($filename) !== $filename
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $filename)) return null;
        $root = realpath(PRIVATE_PHOTO_DIR);
        if ($root === false) return null;
        $path = realpath($root . DIRECTORY_SEPARATOR . $filename);
        if ($path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) return null;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!isset(self::MIME_TO_EXT[$mime]) || !in_array($extension, self::MIME_TO_EXT[$mime], true)) return null;
        return ['path' => $path, 'mime' => $mime, 'extension' => $extension];
    }

    public static function delete(string $filename): bool
    {
        $resolved = self::resolve($filename);
        return $resolved === null || @unlink($resolved['path']);
    }
}
