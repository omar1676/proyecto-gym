<?php

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__) . '/Support/AccessControlTestFactory.php';
require_once dirname(__DIR__, 2) . '/app/services/AccessEligibilityService.php';
require_once dirname(__DIR__, 2) . '/app/services/MockAccessControlProvider.php';
require_once dirname(__DIR__, 2) . '/app/services/AccessControlRepository.php';
require_once dirname(__DIR__, 2) . '/app/services/AccessControlSyncService.php';

$db = Database::getInstance()->getConnection();
AccessControlTestFactory::cleanup($db);
$tenant = AccessControlTestFactory::createTenant($db, 'sync');
$repository = new AccessControlRepository($db, 3, 5);

$eligibility = new AccessEligibilityService($tenant['empresa'], $tenant['sede']);
$logical = $eligibility->decidir($tenant['member']);
check('eligibility produce AccessDecision formal', $logical instanceof AccessDecision);
check('socio sin membresía queda bloqueado con código estable', $logical->estado() === 'BLOQUEADO' && $logical->reasonCode() === 'NO_ACTIVE_MEMBERSHIP');

$mapId = $repository->mapIdentity($tenant['empresa'], $tenant['sede'], $tenant['member'], 'mock', 'opaque-sync');
$sameMap = $repository->mapIdentity($tenant['empresa'], $tenant['sede'], $tenant['member'], 'mock', 'opaque-sync');
check('mapeo exacto es idempotente', $mapId > 0 && $mapId === $sameMap);

$shadowProvider = new MockAccessControlProvider();
$shadow = new AccessControlSyncService('shadow', false, $shadowProvider, $repository);
$shadowResult = $shadow->request($logical, $tenant['actor'], 'test-suite');
check('shadow registra lo que haría', $shadowResult['status'] === 'SYNCED');
check('shadow nunca llama al provider', $shadowProvider->processedCount() === 0);
$shadowDuplicate = $shadow->request($logical, $tenant['actor'], 'test-suite');
check('shadow repetido no crea otra operación', $shadowDuplicate['duplicate'] === true);
$jobs = $repository->listJobs($tenant['empresa'], $tenant['sede']);
check('idempotencia conserva un solo job shadow', count($jobs) === 1);

$disabledDecision = new AccessDecision($tenant['empresa'], $tenant['sede'], $tenant['member'], 'REVISAR', 'PAYMENT_REVIEW', null, null, 'disabled-v1');
$disabled = new AccessControlSyncService('disabled', false, new MockAccessControlProvider(), $repository);
$disabledResult = $disabled->request($disabledDecision, $tenant['actor'], 'test-suite');
check('disabled no encola', $disabledResult['status'] === 'DISABLED' && count($repository->listJobs($tenant['empresa'], $tenant['sede'])) === 1);

$activeProvider = new MockAccessControlProvider();
$activeProvider->addCredential($tenant['empresa'], $tenant['sede'], $tenant['member'], 'opaque-sync');
$active = new AccessControlSyncService('active', true, $activeProvider, $repository);
$blocked = new AccessDecision($tenant['empresa'], $tenant['sede'], $tenant['member'], 'BLOQUEADO', 'MEMBER_INACTIVE', null, null, 'active-v1');
$queued = $active->request($blocked, $tenant['actor'], 'test-suite');
check('active confirmado deja un job pendiente', $queued['status'] === 'PENDING' && $queued['queued'] === true);
$processed = $active->processOne('test-worker');
check('mock active procesa el job', $processed['status'] === 'SYNCED' && $processed['result'] === 'SUCCESS');
check('provider recibe una sola operación', $activeProvider->processedCount() === 1);

$retryDecision = new AccessDecision($tenant['empresa'], $tenant['sede'], $tenant['member'], 'REVISAR', 'RETURNED_PAYMENT', null, null, 'retry-v1');
$active->request($retryDecision, $tenant['actor'], 'test-suite');
$activeProvider->queueSyncOutcome(AccessControlResult::TIMEOUT);
$retry = $active->processOne('test-worker');
check('timeout pasa a RETRY', $retry['status'] === 'RETRY' && $retry['result'] === 'TIMEOUT');
$db->prepare("UPDATE access_sync_job SET next_attempt_at=NOW() WHERE id_job=:id")->execute([':id'=>$retry['job_id']]);
$retried = $active->processOne('test-worker');
check('reintento posterior puede sincronizar', $retried['status'] === 'SYNCED');
$retryRow = array_values(array_filter($repository->listJobs($tenant['empresa'], $tenant['sede']), fn($job) => (int) $job['id_job'] === $retry['job_id']))[0] ?? [];
check('reintento queda contado', (int) ($retryRow['attempts'] ?? 0) === 2);

$downProvider = new MockAccessControlProvider(false);
$downService = new AccessControlSyncService('active', true, $downProvider, $repository);
$downDecision = new AccessDecision($tenant['empresa'], $tenant['sede'], $tenant['member'], 'PERMITIDO', 'MEMBERSHIP_ACTIVE', null, null, 'down-v1');
$downService->request($downDecision, $tenant['actor'], 'test-suite');
$down = $downService->processOne('test-worker-down');
check('proveedor caído no autoriza y programa retry', $down['status'] === 'RETRY' && $down['result'] === 'UNAVAILABLE');

$missingMember = AccessControlTestFactory::createMember($db, $tenant['empresa'], $tenant['sede'], 'sync_missing');
$repository->mapIdentity($tenant['empresa'], $tenant['sede'], $missingMember, 'mock', 'opaque-missing');
$logDirExisted = is_dir(LOG_DIR);
if (!$logDirExisted && !mkdir(LOG_DIR, 0770, true) && !is_dir(LOG_DIR)) {
    throw new RuntimeException('No se pudo crear el directorio temporal de logs del test.');
}
$applicationLog = rtrim(LOG_DIR, '/\\') . DIRECTORY_SEPARATOR . 'application-' . date('Y-m-d') . '.log';
$applicationLogExisted = is_file($applicationLog);
$logOffset = is_file($applicationLog) ? (int) filesize($applicationLog) : 0;
$missingDecision = new AccessDecision($tenant['empresa'], $tenant['sede'], $missingMember, 'BLOQUEADO', 'NO_ACTIVE_MEMBERSHIP', null, null, 'missing-v1');
$active->request($missingDecision, $tenant['actor'], 'test-suite');
$missing = $active->processOne('test-worker');
check('identidad externa inexistente no se inventa', $missing['status'] === 'RETRY' && $missing['result'] === 'NOT_FOUND');
$criticalDenyLogged = false;
clearstatcache(true, $applicationLog);
if (is_file($applicationLog) && filesize($applicationLog) > $logOffset) {
    $handle = fopen($applicationLog, 'rb');
    if (is_resource($handle)) {
        fseek($handle, $logOffset);
        $tail = stream_get_contents($handle);
        fclose($handle);
        foreach (preg_split('/\R/', (string)$tail, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $entry = json_decode($line, true);
            if (($entry['level'] ?? null) === 'CRITICAL'
                && ($entry['event'] ?? null) === 'access_control_deny_sync_failed'
                && (int)($entry['context']['member_id'] ?? 0) === $missingMember
                && ($entry['context']['decision_state'] ?? null) === 'BLOQUEADO') {
                $criticalDenyLogged = true;
            }
        }
    }
}
check('fallo al sincronizar DENY se eleva a CRITICAL', $criticalDenyLogged);

$audit = $repository->listAudit($tenant['empresa'], $tenant['sede'], 100);
check('auditoría conserva tenant, sede, socio y correlación', count($audit) >= 6 && !empty($audit[0]['correlation_id']));
check('auditoría no tiene columnas biométricas', count(array_filter(array_keys($audit[0]), fn($key) => preg_match('/huella|biometr|finger|template|minut/i', $key))) === 0);
$metrics = $repository->metrics($tenant['empresa'], $tenant['sede']);
check('métricas preparan éxito, fallos, retries y pendientes', isset($metrics['jobs']['SYNCED'], $metrics['jobs']['RETRY'], $metrics['attempts']['retries'], $metrics['audit']['avg_latency_ms']));
check('métricas separan decisiones por estado', isset($metrics['decisions']['PERMITIDO'], $metrics['decisions']['BLOQUEADO'], $metrics['decisions']['REVISAR']));

if (!$applicationLogExisted && is_file($applicationLog)) unlink($applicationLog);
if (!$logDirExisted && is_dir(LOG_DIR)) @rmdir(LOG_DIR);

AccessControlTestFactory::cleanup($db);
finishTests();
