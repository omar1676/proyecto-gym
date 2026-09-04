<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/RestaurantCatalogTestFactory.php';

/** @return list<array{exit:int,data:?array,stderr:string}> */
function runR02Pair(string $operation, int $actor, array $inputs): array
{
    $barrier = sys_get_temp_dir() . '/gimnera-r02-' . bin2hex(random_bytes(8));
    $worker = dirname(__DIR__) . '/Support/restaurant_catalog_concurrency_worker.php';
    $running = [];
    try {
        foreach ($inputs as $input) {
            $command = [PHP_BINARY, $worker, $barrier, (string) $actor, $operation, base64_encode(json_encode($input, JSON_THROW_ON_ERROR))];
            $spec = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
            $process = proc_open($command, $spec, $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
            if (!is_resource($process)) throw new RuntimeException('No se inició worker R02.');
            fclose($pipes[0]);
            $running[] = [$process, $pipes];
        }
        touch($barrier);
        $results = [];
        foreach ($running as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $results[] = ['exit' => proc_close($process), 'data' => json_decode((string) $stdout, true), 'stderr' => (string) $stderr];
        }
        return $results;
    } finally {
        if (is_file($barrier)) unlink($barrier);
    }
}

function r02OneWinner(array $results): bool
{
    return count(array_filter($results, static fn(array $row): bool => !empty($row['data']['success']))) === 1
        && count(array_filter($results, static fn(array $row): bool => empty($row['data']['success']))) === 1;
}

$db = Database::getInstance()->getConnection();
$companies = [];
try {
    $foundation = RestaurantCatalogTestFactory::foundation($db, 'r02-concurrency');
    $companies[] = $foundation['company_id'];
    $scope = RestaurantCatalogTestFactory::scope($foundation);
    $demo = RestaurantCatalogTestFactory::demo($db, $foundation, 'concurrency');

    $sameCreate = $scope + [
        'name' => 'Producto Concurrente', 'status' => 'ACTIVE',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('concurrency:create-product'),
    ];
    $createResults = runR02Pair('create_product', $foundation['actor_id'], [$sameCreate, $sameCreate]);
    check('dos procesos de create terminan', count($createResults) === 2);
    check('create simultáneo es idempotente para ambos', count(array_filter(
        $createResults, static fn(array $row): bool => !empty($row['data']['success'])
    )) === 2);
    $createdIds = array_unique(array_map(static fn(array $row): int => (int) ($row['data']['result']['product_id'] ?? 0), $createResults));
    check('create simultáneo conserva un solo producto', count($createdIds) === 1 && reset($createdIds) > 0);

    $productId = $demo['products']['Producto Simple'];
    $updates = [];
    foreach ([1,2] as $worker) {
        $updates[] = $scope + [
            'product_id' => $productId, 'expected_version' => 1,
            'name' => 'Producto editado ' . $worker, 'status' => 'ACTIVE',
            'idempotency_key' => RestaurantCatalogTestFactory::uuid('concurrency:update-product:' . $worker),
        ];
    }
    $updateResults = runR02Pair('update_product', $foundation['actor_id'], $updates);
    check('edición concurrente produce un ganador y un conflicto', r02OneWinner($updateResults));
    check('producto termina en versión 2 sin lost update', (int) $db->query(
        'SELECT version FROM restaurant_product WHERE id_restaurant_product=' . $productId
    )->fetchColumn() === 2);

    $priceInputs = [];
    foreach ([['9.00',1], ['10.00',2]] as [$amount,$worker]) {
        $priceInputs[] = $scope + [
            'product_id' => $productId, 'amount' => $amount, 'scope_type' => 'BRAND', 'expected_version' => 0,
            'idempotency_key' => RestaurantCatalogTestFactory::uuid('concurrency:price:' . $worker),
        ];
    }
    $priceResults = runR02Pair('set_price', $foundation['actor_id'], $priceInputs);
    check('precio concurrente produce un ganador y un conflicto', r02OneWinner($priceResults));
    check('precio concurrente conserva una fila y un evento',
        (int) $db->query('SELECT COUNT(*) FROM restaurant_price WHERE id_restaurant_product=' . $productId)->fetchColumn() === 1
        && (int) $db->query('SELECT COUNT(*) FROM restaurant_price_history WHERE id_restaurant_product=' . $productId)->fetchColumn() === 1
    );

    $availabilityInputs = [];
    foreach ([[true,1], [false,2]] as [$value,$worker]) {
        $availabilityInputs[] = $scope + [
            'product_id' => $productId, 'is_available' => $value, 'scope_type' => 'BRAND', 'expected_version' => 0,
            'idempotency_key' => RestaurantCatalogTestFactory::uuid('concurrency:availability:' . $worker),
        ];
    }
    $availabilityResults = runR02Pair('set_availability', $foundation['actor_id'], $availabilityInputs);
    check('disponibilidad concurrente produce un ganador y un conflicto', r02OneWinner($availabilityResults));
    check('disponibilidad concurrente conserva una fila y un evento',
        (int) $db->query('SELECT COUNT(*) FROM restaurant_availability WHERE id_restaurant_product=' . $productId)->fetchColumn() === 1
        && (int) $db->query('SELECT COUNT(*) FROM restaurant_availability_history WHERE id_restaurant_product=' . $productId)->fetchColumn() === 1
    );

    $groupInputs = [];
    foreach ([1,2] as $worker) {
        $groupInputs[] = $scope + [
            'group_id' => $demo['groups']['Salsas'], 'expected_version' => 1,
            'name' => 'Salsas editadas ' . $worker, 'required' => false,
            'min_selections' => 0, 'max_selections' => 3, 'status' => 'ACTIVE',
            'idempotency_key' => RestaurantCatalogTestFactory::uuid('concurrency:group:' . $worker),
        ];
    }
    $groupResults = runR02Pair('update_group', $foundation['actor_id'], $groupInputs);
    check('grupo concurrente produce un ganador y un conflicto', r02OneWinner($groupResults));
    check('grupo termina en versión 2 sin lost update', (int) $db->query(
        'SELECT version FROM restaurant_modifier_group WHERE id_restaurant_modifier_group=' . $demo['groups']['Salsas']
    )->fetchColumn() === 2);

    $allResults = array_merge($createResults, $updateResults, $priceResults, $availabilityResults, $groupResults);
    check('workers no exponen SQL ni secretos', count(array_filter(
        $allResults,
        static fn(array $row): bool => preg_match('/SQLSTATE|password|token|Fatal error|Uncaught/i', $row['stderr']) === 1
    )) === 0);
} catch (Throwable $error) {
    check('concurrencia R02 completa', false);
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
} finally {
    RestaurantCatalogTestFactory::cleanup($db, $companies);
}
finishTests();
