<?php

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/AppLogger.php';
require_once __DIR__ . '/../app/helpers/RequestContext.php';
require_once __DIR__ . '/../app/services/AccessControlMode.php';
require_once __DIR__ . '/../app/services/MockAccessControlProvider.php';
require_once __DIR__ . '/../app/services/AccessControlRepository.php';
require_once __DIR__ . '/../app/services/AccessControlSyncService.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
RequestContext::bootstrap('CRON');

$mode = AccessControlMode::resolve(ACCESS_CONTROL_MODE, ACCESS_CONTROL_ACTIVE_CONFIRM);
if ($mode === AccessControlMode::DISABLED) {
    echo json_encode(['status'=>'disabled','processed'=>0]) . PHP_EOL;
    exit(0);
}

// Fase 10 solo distribuye un mock sin red. Aunque alguien configure active, el
// cron se niega a simular que existe un provider físico real.
if (ACCESS_CONTROL_PROVIDER !== 'mock' || $mode === AccessControlMode::ACTIVE) {
    AppLogger::security('access_control_provider_unavailable', [
        'provider'=>ACCESS_CONTROL_PROVIDER, 'mode'=>$mode,
    ]);
    fwrite(STDERR, "INTERFAZ REAL NO VERIFICADA. No se procesa control físico.\n");
    exit(2);
}

try {
    $repository = new AccessControlRepository(
        Database::getInstance()->getConnection(),
        ACCESS_CONTROL_MAX_ATTEMPTS,
        ACCESS_CONTROL_BACKOFF_SECONDS
    );
    $recovered = $repository->recoverStale();
    $service = new AccessControlSyncService(
        $mode,
        ACCESS_CONTROL_ACTIVE_CONFIRM,
        new MockAccessControlProvider(),
        $repository
    );
    $processed = 0;
    while ($processed < 100) {
        $result = $service->processOne('access-cron');
        if (($result['status'] ?? '') === 'EMPTY') break;
        $processed++;
    }
    AppLogger::info('access_control_shadow_cron_ok', [
        'mode'=>$mode, 'processed'=>$processed, 'recovered'=>$recovered,
    ]);
    echo json_encode([
        'status'=>'ok', 'mode'=>$mode, 'processed'=>$processed, 'recovered'=>$recovered,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    AppLogger::error('access_control_shadow_cron_failed', ['reason'=>$e->getMessage(), 'mode'=>$mode]);
    fwrite(STDERR, "ERROR procesando la cola shadow.\n");
    exit(1);
}
