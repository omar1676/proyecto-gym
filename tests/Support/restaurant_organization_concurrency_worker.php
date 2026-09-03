<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/RestaurantOrganizationTestFactory.php';

$barrier = (string) ($argv[1] ?? '');
$companyId = (int) ($argv[2] ?? 0);
$actorId = (int) ($argv[3] ?? 0);
$idempotencyKey = (string) ($argv[4] ?? '');
if ($barrier === '' || $companyId <= 0 || $actorId <= 0 || $idempotencyKey === '') {
    exit(2);
}
for ($attempt = 0; $attempt < 500 && !is_file($barrier); $attempt++) {
    usleep(10000);
}
if (!is_file($barrier)) {
    exit(3);
}

try {
    $db = Database::getInstance()->getConnection();
    $service = new RestaurantOrganizationService($db, $actorId);
    $result = $service->provision(RestaurantOrganizationTestFactory::input(
        $companyId,
        'concurrente',
        ['idempotency_key' => $idempotencyKey]
    ));
    echo json_encode(['success' => true, 'result' => $result], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'error' => get_class($error)], JSON_THROW_ON_ERROR);
    exit(1);
}
