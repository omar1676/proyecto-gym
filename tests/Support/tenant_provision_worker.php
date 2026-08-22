<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/TenantOnboardingFactory.php';

$barrier = (string) ($argv[1] ?? '');
$payload = json_decode((string) base64_decode((string) ($argv[2] ?? ''), true), true);
$deadline = microtime(true) + 15;
while (!is_file($barrier) && microtime(true) < $deadline) usleep(1000);
if (!is_file($barrier) || !is_array($payload)) exit(2);

try {
    $db = Database::getInstance()->getConnection();
    $result = (new TenantProvisioningService($db, 6))->provision($payload);
    echo json_encode(['ok' => true, 'created' => $result['created'], 'company_id' => $result['company_id']]);
    exit(0);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'type' => get_class($e)]);
    exit(10);
}
