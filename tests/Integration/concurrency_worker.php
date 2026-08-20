<?php
putenv('APP_ENV=test');
define('MODO_PRUEBAS', true);
require_once dirname(__DIR__, 2) . '/app/models/VentaModel.php';
$barrier = $argv[1] ?? '';
$deadline = microtime(true) + 10;
while (!is_file($barrier) && microtime(true) < $deadline) usleep(1000);
if (!is_file($barrier)) { fwrite(STDERR, 'barrier timeout'); exit(2); }
$error = '';
$model = new VentaModel(1, 1);
$id = $model->registrar([['id_producto' => 1, 'cantidad' => 1]], null, 'efectivo', 1, $error, bin2hex(random_bytes(16)));
echo json_encode(['success' => $id !== null, 'id' => $id, 'error' => $error]);
exit(0);
