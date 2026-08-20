<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/models/CashModel.php';

$operacion = $argv[1] ?? '';
$barrera = $argv[2] ?? '';
$limite = microtime(true) + 10;
while (!is_file($barrera) && microtime(true) < $limite) usleep(10000);
$caja = new CashModel(1, 1); $error = '';
$resultado = $operacion === 'abrir'
    ? $caja->abrir('10.00', 1, $error)
    : $caja->cerrar('10.00', 1, 'cierre concurrente', $error);
echo json_encode(['ok'=>$resultado !== null, 'error'=>$error]);
