<?php
require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/MigrationManager.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
$productionConfirmed = in_array('--confirm-production', $args, true);
if (APP_ENV === 'production' && !$productionConfirmed) { fwrite(STDERR, "Producción exige --confirm-production y backup previo.\n"); exit(1); }
try {
    $manager = new MigrationManager();
    if (in_array('--baseline-current', $args, true)) $manager->baselineExisting();
    elseif (in_array('--fresh', $args, true)) $manager->migrateFresh();
    elseif (!in_array('--status', $args, true)) $manager->migratePending();
    $status = $manager->status();
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(!$status['initialized'] || $status['pending'] || $status['checksum_mismatch'] ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'MIGRACIÓN DETENIDA: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
