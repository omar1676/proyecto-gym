<?php

require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/BackupRetention.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$apply = in_array('--apply', $argv, true);
if ($apply && (APP_ENV !== 'staging' || !in_array('--confirm-staging', $argv, true))) {
    fwrite(STDERR, "La retención real exige staging y --confirm-staging.\n"); exit(2);
}
if (!BACKUP_EXTERNAL_ENABLED || !BACKUP_EXTERNAL_ENCRYPTED || BACKUP_EXTERNAL_REMOTE === '') {
    fwrite(STDERR, "El remote externo cifrado no está habilitado.\n"); exit(2);
}

function retentionRclone(array $args): string
{
    $command = array_merge(['rclone', '--config', BACKUP_EXTERNAL_CONFIG], $args);
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('No se pudo ejecutar rclone.');
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    if ($exit !== 0) throw new RuntimeException('rclone devolvió un error sin aplicar retención.');
    return (string) $out;
}

try {
    $rows = json_decode(retentionRclone(['lsjson', BACKUP_EXTERNAL_REMOTE, '--dirs-only']), true, 512, JSON_THROW_ON_ERROR);
    $items = [];
    foreach ($rows as $row) {
        $name = rtrim((string) ($row['Name'] ?? ''), '/');
        if (!preg_match('/^backup_set_[A-Za-z0-9_.-]+$/', $name)) continue;
        $files = preg_split('/\r?\n/', trim(retentionRclone(['lsf', rtrim(BACKUP_EXTERNAL_REMOTE, '/') . '/' . $name, '--files-only']))) ?: [];
        $files = array_values(array_filter($files));
        $verified = BackupRetention::verifiedSetFiles($files);
        $timestamp = BackupRetention::timestampFromSetName($name);
        $items[] = [
            'name' => $name,
            // Los prefijos S3 simulados como carpetas no tienen un ModTime
            // fiable. La fecha procede del nombre generado por el servidor.
            'timestamp' => $timestamp ?? 0,
            'verified' => $verified && $timestamp !== null,
        ];
    }
    $plan = BackupRetention::plan($items, COPIAS_DIARIAS, COPIAS_SEMANALES, COPIAS_MENSUALES);
    if ($apply) {
        foreach ($plan['delete'] as $name) {
            if (!preg_match('/^backup_set_[A-Za-z0-9_.-]+$/', $name)) throw new RuntimeException('Nombre remoto rechazado.');
            retentionRclone(['purge', rtrim(BACKUP_EXTERNAL_REMOTE, '/') . '/' . $name]);
        }
    }
    echo json_encode(['status' => 'OK', 'mode' => $apply ? 'applied' : 'dry-run'] + $plan, JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'RETENCIÓN EXTERNA DETENIDA: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
