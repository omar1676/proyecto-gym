<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/models/CashModel.php';
require_once dirname(__DIR__, 2) . '/app/models/VentaModel.php';

$db = Database::getInstance()->getConnection();
pruebasLimpiarVentas($db, "v.idempotency_key LIKE 'f9-caja-%'");
$db->exec("DELETE cm FROM caja_movimiento cm INNER JOIN caja_sesion cs ON cs.id_sesion_caja=cm.id_sesion_caja WHERE cs.id_empresa=1 AND cs.id_gimnasio=1");
$db->exec('DELETE FROM caja_sesion WHERE id_empresa=1 AND id_gimnasio=1');
$db->exec('UPDATE producto SET stock=20 WHERE id_producto=1');

$caja = new CashModel(1, 1);
$error = '';
$idSesion = $caja->abrir('100,00', 1, $error);
check('abre caja con saldo inicial exacto', $idSesion !== null && $caja->abierta()['saldo_inicial'] === '100.00');
$error = '';
check('rechaza dos aperturas incompatibles en la sede', $caja->abrir('10.00', 1, $error) === null);

$ventas = new VentaModel(1, 1);
$error = '';
$idEfectivo = $ventas->registrar([['id_producto'=>1,'cantidad'=>1]], null, 'efectivo', 1, $error, 'f9-caja-efectivo-00000000000001');
$ventaEfectivo = $ventas->buscarPorId((int)$idEfectivo);
check('venta en efectivo genera movimiento ligado', (int)$db->query('SELECT COUNT(*) FROM caja_movimiento WHERE id_venta='.(int)$idEfectivo." AND tipo='venta' AND afecta_efectivo=1")->fetchColumn() === 1);

$error = '';
$idTarjeta = $ventas->registrar([['id_producto'=>1,'cantidad'=>1]], null, 'datafono', 1, $error, 'f9-caja-tarjeta-000000000000001');
check('venta con tarjeta queda en caja operativa', (int)$db->query('SELECT COUNT(*) FROM caja_movimiento WHERE id_venta='.(int)$idTarjeta." AND tipo='venta'")->fetchColumn() === 1);
check('tarjeta no aumenta efectivo físico', (int)$db->query('SELECT afecta_efectivo FROM caja_movimiento WHERE id_venta='.(int)$idTarjeta)->fetchColumn() === 0);

check('anular venta conserva y compensa movimiento', $ventas->anular((int)$idEfectivo, 1, 'F9 prueba caja') === true);
$compensa = $db->query('SELECT importe,afecta_efectivo FROM caja_movimiento WHERE id_venta='.(int)$idEfectivo." AND tipo='anulacion_venta'")->fetch();
check('compensación de efectivo es negativa y exacta', $compensa && Money::cents($compensa['importe']) === -Money::cents($ventaEfectivo['total']) && (int)$compensa['afecta_efectivo'] === 1);

$error = '';
$entrada = $caja->movimientoManual('ajuste_entrada', '10.00', 'F9 cambio añadido', 1, $error, 'f9000000000000000000000000000010');
$salida = $caja->movimientoManual('ajuste_salida', '3.00', 'F9 compra urgente', 1, $error, 'f9000000000000000000000000000011');
check('entrada manual exige y conserva motivo', $entrada !== null && (string)$db->query('SELECT motivo FROM caja_movimiento WHERE id_movimiento_caja='.(int)$entrada)->fetchColumn() === 'F9 cambio añadido');
check('salida manual se registra con signo negativo', $salida !== null && Money::cents($db->query('SELECT importe FROM caja_movimiento WHERE id_movimiento_caja='.(int)$salida)->fetchColumn()) === -300);
$abierta = $caja->abierta();
check('saldo esperado separa tarjeta y suma solo efectivo', $abierta['saldo_esperado_actual'] === '107.00');

$error = '';
$cierre = $caja->cerrar('105.00', 1, 'F9 faltan dos euros', $error);
check('cierre congela esperado y declarado', $cierre && $cierre['saldo_esperado'] === '107.00' && $cierre['saldo_declarado'] === '105.00');
check('cierre registra diferencia sin ocultarla', $cierre['diferencia'] === '-2.00');
$error = '';
check('segundo cierre no duplica arqueo', $caja->cerrar('105.00', 1, '', $error) === null);
check('sesión cerrada queda en histórico', count($caja->historial()) === 1 && $caja->abierta() === null);

pruebasLimpiarVentas($db, "v.idempotency_key LIKE 'f9-caja-%'");
$db->exec("DELETE FROM caja_movimiento WHERE id_sesion_caja = $idSesion");
$db->exec("DELETE FROM caja_sesion WHERE id_sesion_caja = $idSesion");
finishTests();
