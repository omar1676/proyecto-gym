<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/helpers/BackupStorage.php';
require_once __DIR__ . '/../app/helpers/MigrationManager.php';
require_once __DIR__ . '/../app/helpers/AppLogger.php';
require_once __DIR__ . '/../app/helpers/Mailer.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

const MONITOR_OK = 'OK';
const MONITOR_WARNING = 'WARNING';
const MONITOR_CRITICAL = 'CRITICAL';

$checks = [];
$db = null;

try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    addCheck($checks, 'database', (int) $db->query('SELECT 1')->fetchColumn() === 1 ? MONITOR_OK : MONITOR_CRITICAL, 'consulta mínima');
} catch (Throwable $e) {
    addCheck($checks, 'database', MONITOR_CRITICAL, 'sin conexión');
}

checkService($checks, 'nginx');
checkService($checks, 'php8.3-fpm');
checkService($checks, 'mariadb');
checkService($checks, 'cron');

$total = @disk_total_space(dirname(__DIR__));
$free = @disk_free_space(dirname(__DIR__));
$freePercent = $total && $free ? round($free / $total * 100, 1) : null;
if ($freePercent === null) addCheck($checks, 'disk', MONITOR_CRITICAL, 'no medible');
elseif ($freePercent < 5) addCheck($checks, 'disk', MONITOR_CRITICAL, $freePercent . '% libre');
elseif ($freePercent < DISCO_LIBRE_MINIMO_PCT) addCheck($checks, 'disk', MONITOR_WARNING, $freePercent . '% libre');
else addCheck($checks, 'disk', MONITOR_OK, $freePercent . '% libre');

$memoryPercent = linuxMemoryAvailablePercent();
if ($memoryPercent === null) addCheck($checks, 'memory', MONITOR_WARNING, 'no medible');
elseif ($memoryPercent < 8) addCheck($checks, 'memory', MONITOR_CRITICAL, $memoryPercent . '% disponible');
elseif ($memoryPercent < 15) addCheck($checks, 'memory', MONITOR_WARNING, $memoryPercent . '% disponible');
else addCheck($checks, 'memory', MONITOR_OK, $memoryPercent . '% disponible');

checkLatestBackup($checks, 'backup_database', 'backup_db_', 8, 12);
checkLatestBackup($checks, 'backup_files', 'backup_files_', 36, 72);
checkExternalBackup($checks);
checkApplicationAndTls($checks);

foreach (['gimnera-backup-db', 'gimnera-backup-files', 'gimnera-backup-external', 'gimnera-maintenance', 'gimnera-monitor'] as $unit) {
    checkTimer($checks, $unit);
}

$errors = countRecentErrors();
if ($errors > LOG_ERRORS_HORA_MAX * 2) addCheck($checks, 'application_errors', MONITOR_CRITICAL, $errors . ' en la última hora');
elseif ($errors > LOG_ERRORS_HORA_MAX) addCheck($checks, 'application_errors', MONITOR_WARNING, $errors . ' en la última hora');
else addCheck($checks, 'application_errors', MONITOR_OK, $errors . ' en la última hora');

if ($db instanceof PDO) {
    checkSchema($checks, $db);
    checkFailedJobs($checks, $db);
} else {
    addCheck($checks, 'migrations', MONITOR_CRITICAL, 'no verificables sin DB');
    addCheck($checks, 'failed_jobs', MONITOR_WARNING, 'no verificables sin DB');
}

$status = overallStatus($checks);
$notification = updateMonitorState($status, $checks);
$result = [
    'status' => $status,
    'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'checks' => $checks,
    'notification' => $notification,
    'alert_channel_configured' => monitorAlertChannelConfigured(),
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($status === MONITOR_CRITICAL ? 2 : ($status === MONITOR_WARNING ? 1 : 0));

function addCheck(array &$checks, string $name, string $status, string $detail): void
{
    $checks[$name] = ['status' => $status, 'detail' => $detail];
}

function runSystemCommand(array $command): array
{
    $pipes = [];
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) return [127, ''];
    fclose($pipes[0]);
    $stdout = trim((string) stream_get_contents($pipes[1]));
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout];
}

function checkService(array &$checks, string $service): void
{
    if (PHP_OS_FAMILY !== 'Linux') { addCheck($checks, 'service_' . $service, MONITOR_WARNING, 'no aplicable fuera de Linux'); return; }
    [$exit, $output] = runSystemCommand(['systemctl', 'is-active', $service]);
    addCheck($checks, 'service_' . $service, $exit === 0 && $output === 'active' ? MONITOR_OK : MONITOR_CRITICAL, $output ?: 'no disponible');
}

function linuxMemoryAvailablePercent(): ?float
{
    if (!is_file('/proc/meminfo')) return null;
    $values = [];
    foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/', $line, $match)) $values[$match[1]] = (int) $match[2];
    }
    if (empty($values['MemTotal']) || !isset($values['MemAvailable'])) return null;
    return round($values['MemAvailable'] / $values['MemTotal'] * 100, 1);
}

function checkLatestBackup(array &$checks, string $name, string $prefix, int $warningHours, int $criticalHours): void
{
    $files = array_values(array_filter(
        glob(rtrim(COPIAS_DIR, '/\\') . DIRECTORY_SEPARATOR . $prefix . '*') ?: [],
        static fn(string $file): bool => is_file($file) && !str_ends_with($file, '.sha256') && !str_ends_with($file, '.manifest.json')
    ));
    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    if (!$files) { addCheck($checks, $name, MONITOR_CRITICAL, 'no existe'); return; }
    $latest = $files[0];
    try {
        BackupStorage::verifyArtifact($latest);
    } catch (Throwable $e) {
        addCheck($checks, $name, MONITOR_CRITICAL, 'integridad inválida');
        return;
    }
    $age = round((time() - filemtime($latest)) / 3600, 1);
    if ($age > $criticalHours) addCheck($checks, $name, MONITOR_CRITICAL, $age . ' h; SHA/manifiesto válidos');
    elseif ($age > $warningHours) addCheck($checks, $name, MONITOR_WARNING, $age . ' h; SHA/manifiesto válidos');
    else addCheck($checks, $name, MONITOR_OK, $age . ' h; SHA/manifiesto válidos');
}

function checkExternalBackup(array &$checks): void
{
    if (!BACKUP_EXTERNAL_ENABLED || !BACKUP_EXTERNAL_ENCRYPTED) {
        addCheck($checks, 'backup_external', MONITOR_CRITICAL, 'no configurado y/o sin cifrado declarado');
        return;
    }
    $marker = MONITOR_STATE_DIR === '' ? '' : rtrim(MONITOR_STATE_DIR, '/\\') . '/external_backup_success.json';
    $data = $marker !== '' && is_file($marker) ? json_decode((string) file_get_contents($marker), true) : null;
    $verified = is_array($data) ? strtotime((string) ($data['verified_at_utc'] ?? '')) : false;
    if (!$verified || empty($data['encrypted'])) { addCheck($checks, 'backup_external', MONITOR_CRITICAL, 'sin verificación de subida y descarga'); return; }
    $age = round((time() - $verified) / 3600, 1);
    if ($age > 36) addCheck($checks, 'backup_external', MONITOR_CRITICAL, $age . ' h desde la última verificación');
    elseif ($age > 26) addCheck($checks, 'backup_external', MONITOR_WARNING, $age . ' h desde la última verificación');
    else addCheck($checks, 'backup_external', MONITOR_OK, $age . ' h; descarga y SHA verificados');
}

function checkApplicationAndTls(array &$checks): void
{
    $parts = MONITOR_URL !== '' ? parse_url(MONITOR_URL) : false;
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        addCheck($checks, 'https', MONITOR_CRITICAL, 'MONITOR_URL no es HTTPS válido');
        addCheck($checks, 'tls_expiry', MONITOR_CRITICAL, 'no verificable');
        return;
    }
    $ch = curl_init(rtrim(MONITOR_URL, '/') . '/health');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($ch);
    curl_close($ch);
    $health = json_decode((string) $body, true);
    addCheck($checks, 'https', $curlError === 0 && $http === 200 && ($health['status'] ?? '') === 'ok' ? MONITOR_OK : MONITOR_CRITICAL, 'HTTP ' . $http);

    $context = stream_context_create(['ssl' => [
        'capture_peer_cert' => true,
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => $parts['host'],
        'SNI_enabled' => true,
    ]]);
    $socket = @stream_socket_client('ssl://' . $parts['host'] . ':443', $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) { addCheck($checks, 'tls_expiry', MONITOR_CRITICAL, 'handshake/cadena inválidos'); return; }
    $params = stream_context_get_params($socket);
    fclose($socket);
    $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
    $parsed = $certificate ? openssl_x509_parse($certificate) : false;
    $expires = (int) ($parsed['validTo_time_t'] ?? 0);
    if (!$expires) { addCheck($checks, 'tls_expiry', MONITOR_CRITICAL, 'fecha no disponible'); return; }
    $days = (int) floor(($expires - time()) / 86400);
    if ($days < 14) addCheck($checks, 'tls_expiry', MONITOR_CRITICAL, $days . ' días');
    elseif ($days < 30) addCheck($checks, 'tls_expiry', MONITOR_WARNING, $days . ' días');
    else addCheck($checks, 'tls_expiry', MONITOR_OK, $days . ' días');
}

function checkTimer(array &$checks, string $unit): void
{
    if (PHP_OS_FAMILY !== 'Linux') { addCheck($checks, 'timer_' . $unit, MONITOR_WARNING, 'no aplicable fuera de Linux'); return; }
    [$enabledExit] = runSystemCommand(['systemctl', 'is-enabled', $unit . '.timer']);
    [$activeExit, $active] = runSystemCommand(['systemctl', 'is-active', $unit . '.timer']);
    [$resultExit, $result] = runSystemCommand(['systemctl', 'show', '--property=Result', '--value', $unit . '.service']);
    $ok = $enabledExit === 0 && $activeExit === 0 && $active === 'active' && ($resultExit !== 0 || $result === '' || $result === 'success');
    addCheck($checks, 'timer_' . $unit, $ok ? MONITOR_OK : MONITOR_CRITICAL, 'timer=' . ($active ?: 'missing') . ', last=' . ($result ?: 'unknown'));
}

function countRecentErrors(): int
{
    $errors = 0;
    $since = time() - 3600;
    foreach (glob(rtrim(LOG_DIR, '/\\') . '/*.log*') ?: [] as $log) {
        if (!is_file($log) || filemtime($log) < $since) continue;
        $handle = @fopen($log, 'rb');
        if (!$handle) continue;
        $size = filesize($log);
        if ($size > 2097152) fseek($handle, -2097152, SEEK_END);
        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);
            if (($row['level'] ?? '') === 'ERROR' && strtotime((string) ($row['timestamp'] ?? '')) >= $since) $errors++;
        }
        fclose($handle);
    }
    return $errors;
}

function checkSchema(array &$checks, PDO $db): void
{
    try {
        $schema = (new MigrationManager($db))->status();
        $ok = !empty($schema['initialized']) && empty($schema['pending']) && empty($schema['checksum_mismatch']);
        addCheck($checks, 'migrations', $ok ? MONITOR_OK : MONITOR_CRITICAL, count($schema['pending'] ?? []) . ' pendientes, ' . count($schema['checksum_mismatch'] ?? []) . ' checksum mismatch');
    } catch (Throwable $e) {
        addCheck($checks, 'migrations', MONITOR_CRITICAL, 'estado no verificable');
    }
}

function checkFailedJobs(array &$checks, PDO $db): void
{
    try {
        $migrationFailed = tableExists($db, 'migration_batch')
            ? (int) $db->query("SELECT COUNT(*) FROM migration_batch WHERE status='failed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn()
            : 0;
        $accessFailed = tableExists($db, 'access_sync_job')
            ? (int) $db->query("SELECT COUNT(*) FROM access_sync_job WHERE status='FAILED' AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn()
            : 0;
        $total = $migrationFailed + $accessFailed;
        addCheck($checks, 'failed_jobs', $total > 0 ? MONITOR_WARNING : MONITOR_OK, $total . ' en 24 h');
    } catch (Throwable $e) {
        addCheck($checks, 'failed_jobs', MONITOR_WARNING, 'no verificable');
    }
}

function tableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
    $stmt->execute([':table' => $table]);
    return (int) $stmt->fetchColumn() === 1;
}

function overallStatus(array $checks): string
{
    $statuses = array_column($checks, 'status');
    if (in_array(MONITOR_CRITICAL, $statuses, true)) return MONITOR_CRITICAL;
    if (in_array(MONITOR_WARNING, $statuses, true)) return MONITOR_WARNING;
    return MONITOR_OK;
}

function updateMonitorState(string $status, array $checks): array
{
    $problems = [];
    foreach ($checks as $name => $check) if ($check['status'] !== MONITOR_OK) $problems[$name] = $check['status'];
    $fingerprint = hash('sha256', json_encode($problems));
    $stateFile = MONITOR_STATE_DIR !== '' ? rtrim(MONITOR_STATE_DIR, '/\\') . '/monitor_state.json' : '';
    $previous = $stateFile !== '' && is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
    if (!is_array($previous)) $previous = [];
    $cooldown = MONITOR_ALERT_COOLDOWN_MINUTES * 60;
    $lastNotified = strtotime((string) ($previous['last_notified_at_utc'] ?? '')) ?: 0;
    $lastAttempted = strtotime((string) ($previous['last_attempted_at_utc'] ?? '')) ?: $lastNotified;
    $changed = ($previous['fingerprint'] ?? '') !== $fingerprint;
    $recovered = $status === MONITOR_OK && !empty($previous) && ($previous['status'] ?? MONITOR_OK) !== MONITOR_OK;
    $alertRequired = $status !== MONITOR_OK && ($changed || time() - $lastAttempted >= $cooldown);
    $notificationDue = $alertRequired || $recovered;
    $delivered = false;
    if ($notificationDue && monitorAlertChannelConfigured()) {
        $delivered = sendMonitorAlert($status, $checks, $recovered);
        if (!$delivered) AppLogger::error('monitor_alert_delivery_failed', ['status' => $status]);
    }

    if ($stateFile !== '') {
        BackupStorage::ensureDirectory(dirname($stateFile));
        $state = [
            'status' => $status,
            'fingerprint' => $fingerprint,
            'last_seen_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'last_attempted_at_utc' => $notificationDue ? gmdate('Y-m-d\TH:i:s\Z') : ($previous['last_attempted_at_utc'] ?? null),
            'last_notified_at_utc' => $delivered ? gmdate('Y-m-d\TH:i:s\Z') : ($previous['last_notified_at_utc'] ?? null),
        ];
        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX);
    }
    if ($alertRequired) {
        if ($status === MONITOR_CRITICAL) AppLogger::error('monitor_state', ['status' => $status, 'problems' => array_keys($problems)]);
        else AppLogger::warning('monitor_state', ['status' => $status, 'problems' => array_keys($problems)]);
    } elseif ($recovered) {
        AppLogger::info('monitor_recovered');
    }
    return [
        'required' => $alertRequired,
        'recovered' => $recovered,
        'delivery_attempted' => $notificationDue && monitorAlertChannelConfigured(),
        'delivered' => $delivered,
        'suppressed_by_cooldown' => $status !== MONITOR_OK && !$alertRequired,
    ];
}

function monitorAlertChannelConfigured(): bool
{
    if (!filter_var(MONITOR_ALERT_EMAIL, FILTER_VALIDATE_EMAIL) || MAIL_SMTP_HOST === '') return false;
    $allowed = array_values(array_filter(array_map(
        static fn(string $email): string => strtolower(trim($email)),
        explode(',', STAGING_MAIL_ALLOWLIST)
    )));
    return in_array(strtolower(MONITOR_ALERT_EMAIL), $allowed, true);
}

function sendMonitorAlert(string $status, array $checks, bool $recovered): bool
{
    $subject = $recovered
        ? '[GIMNERA STAGING] RECUPERADO'
        : '[GIMNERA STAGING] ' . $status;
    $rows = [];
    foreach ($checks as $name => $check) {
        if (!$recovered && ($check['status'] ?? MONITOR_OK) === MONITOR_OK) continue;
        $rows[] = '<li><b>' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') . '</b>: '
            . htmlspecialchars((string) ($check['status'] ?? ''), ENT_QUOTES, 'UTF-8') . ' — '
            . htmlspecialchars((string) ($check['detail'] ?? ''), ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $intro = $recovered
        ? 'El monitor técnico de staging ha vuelto a estado OK.'
        : 'El monitor técnico de staging requiere atención.';
    $html = '<p>' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>'
        . ($rows ? '<ul>' . implode('', $rows) . '</ul>' : '')
        . '<p>UTC: ' . htmlspecialchars(gmdate('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8') . '</p>';
    return Mailer::enviar(MONITOR_ALERT_EMAIL, $subject, $html);
}
