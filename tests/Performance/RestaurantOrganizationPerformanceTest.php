<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/RestaurantOrganizationTestFactory.php';

$db = Database::getInstance()->getConnection();
$companies = [];
try {
    $actor = RestaurantOrganizationTestFactory::actor($db);
    $service = new RestaurantOrganizationService($db, $actor);
    $durations = [];
    $accountIds = [];
    for ($index = 1; $index <= 100; $index++) {
        $companyId = RestaurantOrganizationTestFactory::createCompany($db, 'scale-' . $index);
        $companies[] = $companyId;
        $start = hrtime(true);
        $result = $service->provision(RestaurantOrganizationTestFactory::input($companyId, 'scale-' . $index));
        $durations[] = (hrtime(true) - $start) / 1_000_000;
        $accountIds[$companyId] = (int) $result['account_id'];
    }
    sort($durations);
    $p95 = $durations[(int) floor((count($durations) - 1) * 0.95)];

    check('100 organizaciones sintéticas se aprovisionan sin error', count($accountIds) === 100);
    check('100 organizaciones conservan 100 accounts aislados', (int) $db->query(
        "SELECT COUNT(*) FROM restaurant_account WHERE id_empresa IN (" . implode(',', $companies) . ')'
    )->fetchColumn() === 100);
    $firstCompany = $companies[0];
    $lastCompany = $companies[99];
    check('lookup scoped no cruza primero y último tenant',
        $service->findScoped($firstCompany, $accountIds[$lastCompany]) === null
        && $service->findScoped($lastCompany, $accountIds[$firstCompany]) === null
    );
    $plan = $db->query(
        "EXPLAIN SELECT * FROM restaurant_account WHERE id_empresa={$firstCompany} LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    check('lookup de organización usa índice contractual',
        !empty($plan['key']) && str_contains((string) $plan['key'], 'uq_restaurant_account_company')
    );
    check('p95 local de foundation queda bajo umbral no regresivo de 500 ms', is_finite($p95) && $p95 < 500.0);
    echo 'METRIC restaurants_foundation_100_p95_ms=' . number_format($p95, 3, '.', '') . "\n";
} catch (Throwable $error) {
    check('performance Restaurants completa', false);
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
} finally {
    RestaurantOrganizationTestFactory::cleanup($db, $companies);
}
finishTests();
