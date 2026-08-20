<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/AppLogger.php';
require_once __DIR__ . '/../app/services/MigrationMaintenance.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
try {
    $db = Database::getInstance()->getConnection();
    $deleted = [];
    $deleted['login'] = $db->exec('DELETE FROM intentos_login WHERE fecha_intento < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $deleted['gimnasios'] = $db->exec('DELETE FROM intentos_gimnasio WHERE fecha_intento < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $deleted['tokens'] = $db->exec('UPDATE usuario SET reset_token=NULL, reset_expira=NULL WHERE reset_expira IS NOT NULL AND reset_expira < NOW()');
    $deleted['import_staging'] = MigrationMaintenance::purgeExpired($db);
    $logsDeleted = 0;
    if (is_dir(LOG_DIR)) {
        $limit = time() - LOG_DIAS * 86400;
        foreach (glob(rtrim(LOG_DIR, '/\\') . '/*.log*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $limit && @unlink($file)) $logsDeleted++;
        }
    }
    AppLogger::info('maintenance_ok', ['database' => $deleted, 'logs_deleted' => $logsDeleted]);
    echo json_encode(['status'=>'ok','database'=>$deleted,'logs_deleted'=>$logsDeleted]) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    AppLogger::error('maintenance_failed', ['reason'=>$e->getMessage()]);
    fwrite(STDERR, "ERROR de mantenimiento.\n"); exit(1);
}
