<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$db = Database::getInstance()->getConnection();
pruebasLimpiarVentas($db, 'v.idempotency_key IS NOT NULL');
$db->exec('UPDATE producto SET stock=1 WHERE id_producto=1');
$barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gym_stock_' . bin2hex(random_bytes(8));
$worker = __DIR__ . '/concurrency_worker.php';
$processes = [];
for ($i = 0; $i < 2; $i++) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, $worker, $barrier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    if (is_resource($process)) $processes[] = [$process, $pipes];
}
touch($barrier);
$results = [];
foreach ($processes as [$process, $pipes]) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $results[] = ['exit' => $exit, 'data' => json_decode($stdout, true), 'stderr' => $stderr];
}
@unlink($barrier);
$successes = array_filter($results, fn($r) => $r['exit'] === 0 && !empty($r['data']['success']));
check('arrancan dos procesos independientes', count($results) === 2);
check('solo una venta concurrente gana el último artículo', count($successes) === 1);
check('stock final es cero, nunca negativo', (int) $db->query('SELECT stock FROM producto WHERE id_producto=1')->fetchColumn() === 0);
check('solo existe una venta válida concurrente', (int) $db->query("SELECT COUNT(*) FROM venta WHERE idempotency_key IS NOT NULL")->fetchColumn() === 1);
$db->exec('UPDATE producto SET stock=50 WHERE id_producto=1');
finishTests();
