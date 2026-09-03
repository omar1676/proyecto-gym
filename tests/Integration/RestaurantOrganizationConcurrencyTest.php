<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/RestaurantOrganizationTestFactory.php';

$db = Database::getInstance()->getConnection();
$companies = [];
$barrier = null;
try {
    $companyId = RestaurantOrganizationTestFactory::createCompany($db, 'concurrency');
    $companies[] = $companyId;
    $actorId = RestaurantOrganizationTestFactory::actor($db);
    $input = RestaurantOrganizationTestFactory::input($companyId, 'concurrente');
    $barrier = sys_get_temp_dir() . '/gimnera-restaurants-' . bin2hex(random_bytes(8));
    $worker = dirname(__DIR__) . '/Support/restaurant_organization_concurrency_worker.php';
    $running = [];
    for ($i = 0; $i < 2; $i++) {
        $command = [PHP_BINARY, $worker, $barrier, (string) $companyId, (string) $actorId, $input['idempotency_key']];
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $spec, $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
        if (is_resource($process)) {
            fclose($pipes[0]);
            $running[] = [$process, $pipes];
        }
    }
    touch($barrier);
    $results = [];
    foreach ($running as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $results[] = [
            'exit' => proc_close($process),
            'data' => json_decode((string) $stdout, true),
            'stderr' => (string) $stderr,
        ];
    }

    check('dos procesos independientes terminan', count($results) === 2);
    check('ambos observan éxito idempotente', count(array_filter(
        $results,
        static fn(array $row): bool => $row['exit'] === 0 && !empty($row['data']['success'])
    )) === 2);
    $accountIds = array_unique(array_map(
        static fn(array $row): int => (int) ($row['data']['result']['account_id'] ?? 0),
        $results
    ));
    check('ambos procesos observan el mismo account', count($accountIds) === 1 && reset($accountIds) > 0);
    $duplicates = array_map(
        static fn(array $row): bool => (bool) ($row['data']['result']['duplicate'] ?? false),
        $results
    );
    sort($duplicates);
    check('concurrencia produce un ganador y un reintento', $duplicates === [false, true]);
    check('DB conserva un account, una marca, una entidad y un local',
        (int) $db->query("SELECT COUNT(*) FROM restaurant_account WHERE id_empresa={$companyId}")->fetchColumn() === 1
        && (int) $db->query("SELECT COUNT(*) FROM restaurant_brand WHERE id_empresa={$companyId}")->fetchColumn() === 1
        && (int) $db->query("SELECT COUNT(*) FROM restaurant_legal_entity WHERE id_empresa={$companyId}")->fetchColumn() === 1
        && (int) $db->query("SELECT COUNT(*) FROM restaurant_location WHERE id_empresa={$companyId}")->fetchColumn() === 1
    );
    check('solo el ganador registra éxito',
        (int) $db->query(
            "SELECT COUNT(*) FROM log_actividad
              WHERE id_empresa={$companyId} AND accion='RESTAURANT_ORGANIZATION_PROVISIONED' AND resultado='exito'"
        )->fetchColumn() === 1
    );
    check('workers no filtran SQL ni secretos', count(array_filter(
        $results,
        static fn(array $row): bool => preg_match('/SQLSTATE|password|token|Fatal error|Uncaught/i', $row['stderr']) === 1
    )) === 0);
} catch (Throwable $error) {
    check('concurrencia Restaurants completa', false);
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
} finally {
    if ($barrier !== null && is_file($barrier)) {
        unlink($barrier);
    }
    RestaurantOrganizationTestFactory::cleanup($db, $companies);
}
finishTests();
