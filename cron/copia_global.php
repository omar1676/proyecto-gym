<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/helpers/BackupStorage.php';
require_once __DIR__ . '/../app/helpers/BackupManifest.php';
require_once __DIR__ . '/../app/helpers/AppLogger.php';
require_once __DIR__ . '/../app/helpers/RequestContext.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
RequestContext::bootstrap('CRON');

$started = time();
$root = dirname(__DIR__);
$destination = rtrim(COPIAS_DIR, '/\\');

try {
    BackupStorage::ensureDirectory($destination);
    runBackup([PHP_BINARY, $root . '/cron/copia_seguridad.php']);
    runBackup([PHP_BINARY, $root . '/cron/copia_archivos.php']);

    $database = newestArtifact($destination, 'backup_db_', $started);
    $files = newestArtifact($destination, 'backup_files_', $started);
    $databaseManifest = BackupStorage::verifyArtifact($database);
    $filesManifest = BackupStorage::verifyArtifact($files);

    $setFile = BackupStorage::uniqueArtifactPath($destination, 'backup_set_', '.json');
    $set = array_merge(BackupManifest::identity(), [
        'kind' => 'backup-set',
        'artifacts' => [
            'database' => $databaseManifest,
            'files' => $filesManifest,
        ],
        'total_size_bytes' => filesize($database) + filesize($files),
        'external_transfer' => 'not_requested',
    ]);
    $json = json_encode($set, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('No se pudo escribir el manifiesto global.');
    }
    BackupStorage::writeExclusive($setFile, $json . PHP_EOL);
    $sha256 = BackupStorage::checksum($setFile);
    BackupManifest::writeForArtifact($setFile, 'backup-set', [
        'database_artifact' => basename($database),
        'files_artifact' => basename($files),
    ]);
    $rotated = BackupStorage::rotate($destination, 'backup_set_');
    AppLogger::info('backup_set_ok', [
        'file' => basename($setFile),
        'sha256' => $sha256,
        'bytes' => filesize($database) + filesize($files),
        'rotated' => $rotated,
    ]);
    echo json_encode([
        'status' => 'verified-local',
        'set' => basename($setFile),
        'sha256' => $sha256,
        'database' => basename($database),
        'files' => basename($files),
        'external' => 'NOT_CONFIGURED',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    AppLogger::error('backup_set_failed', ['reason' => $e->getMessage()]);
    fwrite(STDERR, 'ERROR: no se pudo completar el backup global: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function runBackup(array $command): void
{
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar un componente del backup.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($stdout !== '') fwrite(STDOUT, $stdout);
    if ($exit !== 0) throw new RuntimeException(trim($stderr) ?: 'Un componente del backup devolvió error.');
}

function newestArtifact(string $directory, string $prefix, int $started): string
{
    $candidates = array_values(array_filter(
        glob($directory . DIRECTORY_SEPARATOR . $prefix . '*') ?: [],
        static fn(string $file): bool => is_file($file)
            && !str_ends_with($file, '.sha256')
            && !str_ends_with($file, '.manifest.json')
            && filemtime($file) >= $started - 2
    ));
    usort($candidates, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    if (!$candidates) throw new RuntimeException('No se encontró el artefacto recién generado para ' . $prefix);
    return $candidates[0];
}
