<?php
require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/BackupStorage.php';
require_once dirname(__DIR__) . '/app/helpers/AppLogger.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

$options = getopt('', ['set:']);
$setInput = isset($options['set']) ? realpath((string) $options['set']) : false;
if (!$setInput || !is_file($setInput)) {
    fwrite(STDERR, "Uso: php ops/backup_external.php --set=/ruta/backup_set_UTC.json\n");
    exit(2);
}

try {
    assertExternalConfiguration();
    $backupRoot = realpath(COPIAS_DIR);
    if (!$backupRoot || dirname($setInput) !== $backupRoot || !preg_match('/^backup_set_[A-Za-z0-9_.-]+\.json$/', basename($setInput))) {
        throw new RuntimeException('El manifiesto global no pertenece al directorio de backups autorizado.');
    }
    BackupStorage::verifyArtifact($setInput);
    $set = json_decode((string) file_get_contents($setInput), true);
    if (!is_array($set) || ($set['kind'] ?? '') !== 'backup-set') throw new RuntimeException('El manifiesto global no es válido.');

    $artifacts = [$setInput, $setInput . '.sha256', $setInput . '.manifest.json'];
    foreach (['database', 'files'] as $kind) {
        $name = basename((string) ($set['artifacts'][$kind]['artifact'] ?? ''));
        if ($name === '') throw new RuntimeException('Falta un artefacto en el manifiesto global.');
        $file = $backupRoot . DIRECTORY_SEPARATOR . $name;
        BackupStorage::verifyArtifact($file);
        array_push($artifacts, $file, $file . '.sha256', $file . '.manifest.json');
    }

    $verifyDir = BACKUP_EXTERNAL_VERIFY_DIR !== '' ? BACKUP_EXTERNAL_VERIFY_DIR : $backupRoot . DIRECTORY_SEPARATOR . '.verify';
    BackupStorage::ensureDirectory($verifyDir);
    $remoteBase = rtrim(BACKUP_EXTERNAL_REMOTE, '/') . '/' . basename($setInput, '.json');
    foreach ($artifacts as $file) {
        $remote = $remoteBase . '/' . basename($file);
        runRclone(['rclone', '--config', BACKUP_EXTERNAL_CONFIG, 'copyto', $file, $remote, '--immutable', '--retries', '3', '--low-level-retries', '10']);
        $temporary = tempnam($verifyDir, 'external-verify-');
        if ($temporary === false) throw new RuntimeException('No se pudo crear el archivo temporal de verificación.');
        try {
            runRclone(['rclone', '--config', BACKUP_EXTERNAL_CONFIG, 'copyto', $remote, $temporary, '--retries', '3']);
            $localHash = hash_file('sha256', $file);
            $remoteHash = hash_file('sha256', $temporary);
            if ($localHash === false || $remoteHash === false || !hash_equals($localHash, $remoteHash)) {
                throw new RuntimeException('La descarga de verificación externa no coincide.');
            }
        } finally {
            @unlink($temporary);
        }
    }

    if (MONITOR_STATE_DIR !== '') {
        BackupStorage::ensureDirectory(MONITOR_STATE_DIR);
        $marker = [
            'verified_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'set' => basename($setInput),
            'sha256' => hash_file('sha256', $setInput),
            'encrypted' => true,
        ];
        $markerJson = json_encode($marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $markerPath = rtrim(MONITOR_STATE_DIR, '/\\') . '/external_backup_success.json';
        if ($markerJson === false || file_put_contents($markerPath, $markerJson . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo actualizar la evidencia del backup externo verificado.');
        }
        @chmod($markerPath, 0640);
    }
    AppLogger::info('backup_external_ok', ['set' => basename($setInput), 'artifacts' => count($artifacts), 'encrypted' => true]);
    echo json_encode(['status' => 'verified-external', 'set' => basename($setInput), 'artifacts' => count($artifacts)], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    AppLogger::error('backup_external_failed', ['reason' => $e->getMessage()]);
    fwrite(STDERR, 'BACKUP EXTERNO DETENIDO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function assertExternalConfiguration(): void
{
    if (!BACKUP_EXTERNAL_ENABLED) throw new RuntimeException('BACKUP_EXTERNAL_ENABLED=false; no se transferirá nada.');
    if (!BACKUP_EXTERNAL_ENCRYPTED) throw new RuntimeException('El destino externo no está declarado como cifrado.');
    if (BACKUP_EXTERNAL_CONFIG === '' || !is_file(BACKUP_EXTERNAL_CONFIG)) throw new RuntimeException('Falta la configuración rclone fuera de Git.');
    if ((fileperms(BACKUP_EXTERNAL_CONFIG) & 0007) !== 0) throw new RuntimeException('La configuración rclone tiene permisos para otros usuarios.');
    if (!preg_match('~^[A-Za-z0-9_.-]+:[A-Za-z0-9_./-]*$~', BACKUP_EXTERNAL_REMOTE)) throw new RuntimeException('BACKUP_EXTERNAL_REMOTE no es válido.');
    runRclone(['rclone', '--version']);
}

function runRclone(array $command): void
{
    $pipes = [];
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('rclone no está instalado o no se pudo iniciar.');
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) throw new RuntimeException('rclone devolvió error: ' . mb_substr(trim($stderr), 0, 300));
}
