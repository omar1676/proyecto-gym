<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/config/database.php';
require_once dirname(__DIR__) . '/app/helpers/RequestContext.php';
require_once dirname(__DIR__) . '/app/helpers/AppLogger.php';
require_once dirname(__DIR__) . '/app/services/AccessPolicyService.php';

RequestContext::bootstrap('CRON');
$db = Database::getInstance()->getConnection();
$lockName = 'gimnera:access-policy-expiry';
$lock = $db->prepare('SELECT GET_LOCK(:name, 0)');
$lock->execute([':name'=>$lockName]);
if ((int)$lock->fetchColumn() !== 1) {
    fwrite(STDERR, "ACCESS_POLICY_EXPIRY_ALREADY_RUNNING\n");
    exit(3);
}

$totals = ['expired'=>0, 'converted'=>0, 'skipped'=>0, 'companies'=>0];
try {
    $companies = $db->query("SELECT DISTINCT id_empresa FROM access_policy WHERE state='TEMPORARY'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($companies as $companyId) {
        $service = new AccessPolicyService($db, (int)$companyId, null, null, 'system');
        $result = $service->expireDue(5000);
        foreach (['expired','converted','skipped'] as $key) $totals[$key] += (int)$result[$key];
        $totals['companies']++;
    }
    AppLogger::info('access_policy_expiry_completed', $totals);
    echo json_encode(['status'=>'OK'] + $totals, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    AppLogger::error('access_policy_expiry_failed', [
        'error_class'=>get_class($error),
        'correlation_id'=>RequestContext::correlationId(),
    ]);
    fwrite(STDERR, "ACCESS_POLICY_EXPIRY_FAILED\n");
    exit(1);
} finally {
    try {
        $release = $db->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute([':name'=>$lockName]);
    } catch (Throwable) {
    }
}
