<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/helpers/BackupStorage.php';
require_once __DIR__ . '/../app/helpers/BackupManifest.php';
require_once __DIR__ . '/../app/helpers/AppLogger.php';
require_once __DIR__ . '/../app/helpers/Retry.php';
require_once __DIR__ . '/../app/helpers/RequestContext.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
RequestContext::bootstrap('CRON');

$root = dirname(__DIR__);

try {
    if (!BACKUP_EXTERNAL_ENABLED || !BACKUP_EXTERNAL_ENCRYPTED) {
        throw new RuntimeException('El backup externo cifrado no está habilitado.');
    }

    BackupStorage::ensureDirectory(COPIAS_DIR);
    $database = newestVerifiedArtifact(COPIAS_DIR, 'backup_db_', 8);
    $files = newestVerifiedArtifact(COPIAS_DIR, 'backup_files_', 36);
    $databaseManifest = BackupStorage::verifyArtifact($database);
    $filesManifest = BackupStorage::verifyArtifact($files);

    $set = BackupStorage::uniqueArtifactPath(COPIAS_DIR, 'backup_set_', '.json');
    $payload = array_merge(BackupManifest::identity(), [
        'kind' => 'backup-set',
        'artifacts' => [
            'database' => $databaseManifest,
            'files' => $filesManifest,
        ],
        'total_size_bytes' => filesize($database) + filesize($files),
        'external_transfer' => 'requested',
    ]);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('No se pudo serializar el manifiesto global.');
    BackupStorage::writeExclusive($set, $json . PHP_EOL);
    BackupStorage::checksum($set);
    BackupManifest::writeForArtifact($set, 'backup-set', [
        'database_artifact' => basename($database),
        'files_artifact' => basename($files),
    ]);
    BackupStorage::verifyArtifact($set);

    runCommandWithRetry([PHP_BINARY, $root . '/ops/backup_external.php', '--set=' . $set], 3);

    AppLogger::info('backup_external_scheduled_ok', ['set' => basename($set)]);
    echo json_encode([
        'status' => 'verified-external',
        'set' => basename($set),
        'encrypted' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    AppLogger::error('backup_external_scheduled_failed', ['reason' => $e->getMessage()]);
    fwrite(STDERR, 'BACKUP EXTERNO PROGRAMADO DETENIDO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function runCommandWithRetry(array $command, int $attempts): void
{
    Retry::limited(static function (int $attempt) use ($command, $attempts): void {
        $pipes = [];
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar un componente del backup.');
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit === 0) return;
        AppLogger::warning('backup_external_retry', ['attempt' => $attempt, 'maximum' => $attempts]);
        throw new RuntimeException($stderr !== '' ? mb_substr($stderr, 0, 500) : 'Un componente del backup devolvió error.');
    }, $attempts, 250);
}

function newestVerifiedArtifact(string $directory, string $prefix, int $maximumAgeHours): string
{
    $candidates = array_values(array_filter(
        glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $prefix . '*') ?: [],
        static fn(string $file): bool => is_file($file)
            && !str_ends_with($file, '.sha256')
            && !str_ends_with($file, '.manifest.json')
    ));
    usort($candidates, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    foreach ($candidates as $candidate) {
        if (time() - filemtime($candidate) > $maximumAgeHours * 3600) break;
        try {
            BackupStorage::verifyArtifact($candidate);
            return $candidate;
        } catch (Throwable $e) {
            continue;
        }
    }
    throw new RuntimeException('No existe un backup local reciente y válido para ' . $prefix);
}
