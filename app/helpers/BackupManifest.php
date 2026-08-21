<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/MigrationManager.php';

final class BackupManifest
{
    public static function identity(): array
    {
        $root = dirname(__DIR__, 2);
        $resolved = realpath($root) ?: $root;
        $version = trim((string) @file_get_contents($root . '/VERSION')) ?: 'unknown';
        $release = defined('APP_RELEASE') && APP_RELEASE !== '' ? APP_RELEASE : 'unknown';
        if ($release === 'unknown' && is_dir($root . '/.git') && function_exists('exec')) {
            $output = [];
            $exit = 1;
            @exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), $output, $exit);
            if ($exit === 0 && !empty($output[0])) $release = trim((string) $output[0]);
        }

        $schema = (new MigrationManager())->status();
        return [
            'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'app_version' => $version,
            'app_release' => $release,
            'release_directory' => basename($resolved),
            'environment' => APP_ENV,
            'database' => DB_NAME,
            'schema' => [
                'latest' => $schema['latest'] ?? null,
                'applied_count' => count($schema['applied'] ?? []),
                'pending' => $schema['pending'] ?? [],
                'checksum_mismatch' => $schema['checksum_mismatch'] ?? [],
            ],
        ];
    }

    public static function writeForArtifact(string $file, string $kind, array $extra = []): array
    {
        if (!is_file($file)) throw new RuntimeException('No existe el artefacto para crear su manifiesto.');
        $sha256 = hash_file('sha256', $file);
        if ($sha256 === false) throw new RuntimeException('No se pudo calcular el hash del manifiesto.');
        $manifest = array_merge(self::identity(), [
            'kind' => $kind,
            'artifact' => basename($file),
            'size_bytes' => filesize($file),
            'sha256' => $sha256,
        ], $extra);
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($file . '.manifest.json', $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir el manifiesto del backup.');
        }
        @chmod($file . '.manifest.json', 0640);
        return $manifest;
    }
}
