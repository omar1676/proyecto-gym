<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/models/VentaModel.php';

$db = Database::getInstance()->getConnection();
$db->exec("DELETE FROM venta WHERE idempotency_key LIKE 'test-idem-%'");
$db->exec("UPDATE producto SET stock = 2 WHERE id_producto = 1");
$model = new VentaModel(1, 1);
$key = 'test-idem-' . bin2hex(random_bytes(8));
$error = '';
$first = $model->registrar([['id_producto' => 1, 'cantidad' => 1]], null, 'efectivo', 1, $error, $key);
$second = $model->registrar([['id_producto' => 1, 'cantidad' => 1]], null, 'efectivo', 1, $error, $key);
check('primera venta se crea', is_int($first) && $first > 0);
check('reenvío devuelve la misma venta', $second === $first);
check('reenvío descuenta stock una sola vez', (int) $db->query('SELECT stock FROM producto WHERE id_producto=1')->fetchColumn() === 1);
$count = $db->prepare('SELECT COUNT(*) FROM venta WHERE idempotency_key=:key');
$count->execute([':key' => $key]);
check('clave idempotente solo tiene una fila', (int) $count->fetchColumn() === 1);
$db->exec('UPDATE producto SET stock = 50 WHERE id_producto = 1');
finishTests();
