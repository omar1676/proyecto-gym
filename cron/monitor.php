<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$checks = [];
try { $checks['database'] = (int) Database::getInstance()->getConnection()->query('SELECT 1')->fetchColumn() === 1; } catch (Throwable $e) { $checks['database'] = false; }
$total = @disk_total_space(dirname(__DIR__)); $free = @disk_free_space(dirname(__DIR__));
$freePct = $total && $free ? round($free / $total * 100, 1) : 0;
$checks['disk'] = $freePct >= DISCO_LIBRE_MINIMO_PCT;
$backups = array_values(array_filter(glob(rtrim(COPIAS_DIR, '/\\') . '/backup_db_*.sql*') ?: [], fn($f) => !str_ends_with($f, '.sha256')));
$fileBackups = array_values(array_filter(glob(rtrim(COPIAS_DIR, '/\\') . '/backup_files_*.tar.gz') ?: [], fn($f) => !str_ends_with($f, '.sha256')));
$latest = $backups ? max(array_map('filemtime', $backups)) : 0;
$latestFiles = $fileBackups ? max(array_map('filemtime', $fileBackups)) : 0;
$checks['backup_database_recent'] = $latest > time() - 8 * 3600;
$checks['backup_files_recent'] = $latestFiles > time() - 36 * 3600;
$checks['external_backup'] = COPIAS_EXTERNAS_DIR !== '' && is_dir(COPIAS_EXTERNAS_DIR);
$errors = 0; $since = time() - 3600;
foreach (glob(rtrim(LOG_DIR, '/\\') . '/*.log*') ?: [] as $log) {
    if (!is_file($log) || filemtime($log) < $since) continue;
    $fh = @fopen($log, 'rb'); if (!$fh) continue;
    $size = filesize($log); if ($size > 1048576) fseek($fh, -1048576, SEEK_END);
    while (($line = fgets($fh)) !== false) {
        $row = json_decode($line, true);
        if (($row['level'] ?? '') === 'ERROR' && strtotime($row['timestamp'] ?? '') >= $since) $errors++;
    }
    fclose($fh);
}
$checks['error_rate'] = $errors <= LOG_ERRORS_HORA_MAX;
if (MONITOR_URL !== '') {
    $ch = curl_init(MONITOR_URL . '/health');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
    curl_exec($ch); $checks['application'] = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE) === 200; curl_close($ch);
} else $checks['application'] = null;
$result = ['status' => in_array(false, $checks, true) ? 'alert' : 'ok', 'checks' => $checks, 'disk_free_percent' => $freePct, 'backup_age_hours' => $latest ? round((time()-$latest)/3600,1) : null, 'errors_last_hour'=>$errors];
echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
exit($result['status'] === 'ok' ? 0 : 1);
