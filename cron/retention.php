<?php

require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/config/database.php';
require_once dirname(__DIR__) . '/app/helpers/AppLogger.php';
require_once dirname(__DIR__) . '/app/helpers/RequestContext.php';
require_once dirname(__DIR__) . '/app/helpers/SchemaCompatibility.php';
require_once dirname(__DIR__) . '/app/services/RetentionService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
RequestContext::bootstrap('CRON');
$company = null;
$date = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--company=')) $company = (int)substr($argument, 10);
    if (str_starts_with($argument, '--date=')) $date = substr($argument, 7);
}

try {
    $db = Database::getInstance()->getConnection();
    SchemaCompatibility::assertRuntime($db, dirname(__DIR__));
    $params = [];
    $sql = "SELECT id_empresa FROM empresa WHERE estado='activa' AND onboarding_state='ACTIVE'";
    if ($company !== null) {
        if ($company <= 0) throw new InvalidArgumentException('Empresa no válida.');
        $sql .= ' AND id_empresa=:company';
        $params[':company'] = $company;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $companies = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($company !== null && $companies === []) throw new DomainException('La empresa no está operativa para Retention.');
    $failed = 0;
    foreach ($companies as $companyId) {
        try {
            $result = (new RetentionService($db, $companyId))->run($date);
            echo sprintf(
                "RETENTION_OK company=%d evaluated=%d attention=%d high=%d returned=%d reused=%s\n",
                $companyId, $result['evaluated'], $result['attention'], $result['high_attention'], $result['returned'],
                $result['reused'] ? 'yes' : 'no'
            );
        } catch (Throwable $error) {
            $failed++;
            AppLogger::error('retention_job_company_failed', ['company_id'=>$companyId]);
            fwrite(STDERR, "RETENTION_FAILED company={$companyId}\n");
        }
    }
    exit($failed === 0 ? 0 : 1);
} catch (Throwable $error) {
    AppLogger::error('retention_job_failed');
    fwrite(STDERR, "RETENTION_JOB_FAILED\n");
    exit(1);
}
