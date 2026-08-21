<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/models/MembresiaModel.php';
require_once dirname(__DIR__, 2) . '/app/models/SepaModel.php';
require_once dirname(__DIR__, 2) . '/app/services/SocioFinancialService.php';
require_once dirname(__DIR__, 2) . '/app/services/AccessEligibilityService.php';

$db = Database::getInstance()->getConnection();
$socio = 3; $empresa = 1; $sede = 1;
pruebasLimpiarRemesas($db, "r.concepto LIKE 'F9 TEST %'");
$db->exec("DELETE FROM mandato_sepa WHERE id_socio = $socio");
pruebasLimpiarMembresias($db, "sm.id_socio = $socio");

$m = new MembresiaModel($sede, $empresa);
$sepa = new SepaModel($sede, $empresa);
$finanzas = new SocioFinancialService($empresa, $sede);
$acceso = new AccessEligibilityService($empresa, $sede);
$tipo = $db->query("SELECT * FROM tipo_membresia WHERE id_empresa=1 AND nombre='Mensual' ORDER BY id_tipo_membresia LIMIT 1")->fetch();
$precioOriginal = $tipo['precio'];

$error = '';
$clave = 'f9000000000000000000000000000001';
$idMembresia = $m->contratar($socio, (int) $tipo['id_tipo_membresia'], 'efectivo', $error, null, 'mostrador', $clave, 1);
check('membresía genera contrato', $idMembresia !== null);
$obligacion = $db->query('SELECT * FROM obligacion_pago WHERE id_socio_membresia=' . (int) $idMembresia)->fetch();
$cobro = $db->query('SELECT * FROM cobro WHERE id_socio_membresia=' . (int) $idMembresia)->fetch();
check('contrato genera una obligación separada', $obligacion && $obligacion['importe'] === $precioOriginal);
check('efectivo genera cobro confirmado', $cobro && $cobro['estado'] === 'confirmado' && $cobro['metodo'] === 'efectivo');
check('obligación queda pagada', $obligacion['estado'] === 'pagada');

$db->prepare('UPDATE tipo_membresia SET precio=:precio WHERE id_tipo_membresia=:id')->execute([':precio'=>'50.00', ':id'=>(int)$tipo['id_tipo_membresia']]);
$historico = $db->query('SELECT sm.precio_pagado,o.importe,c.importe AS cobrado FROM socio_membresia sm JOIN obligacion_pago o ON o.id_socio_membresia=sm.id_socio_membresia JOIN cobro c ON c.id_obligacion=o.id_obligacion WHERE sm.id_socio_membresia=' . (int)$idMembresia)->fetch();
check('cambiar tarifa no altera precio del contrato', $historico['precio_pagado'] === $precioOriginal);
check('cambiar tarifa no altera obligación histórica', $historico['importe'] === $precioOriginal);
check('cambiar tarifa no altera cobro histórico', $historico['cobrado'] === $precioOriginal);
$db->prepare('UPDATE tipo_membresia SET precio=:precio WHERE id_tipo_membresia=:id')->execute([':precio'=>$precioOriginal, ':id'=>(int)$tipo['id_tipo_membresia']]);

$estado = $finanzas->estado($socio);
check('socio pagado queda al corriente', $estado['estado_economico'] === 'AL_CORRIENTE' && $estado['deuda_cents'] === 0);
check('acceso lógico permitido sin incidencia', $acceso->evaluar($socio)['estado'] === 'PERMITIDO');
$reenvio = $m->contratar($socio, (int) $tipo['id_tipo_membresia'], 'efectivo', $error, null, 'mostrador', $clave, 1);
check('doble envío devuelve la misma membresía', $reenvio === $idMembresia);
check('doble envío no duplica obligación ni cobro',
    (int)$db->query('SELECT COUNT(*) FROM obligacion_pago WHERE id_socio_membresia='.(int)$idMembresia)->fetchColumn() === 1
    && (int)$db->query('SELECT COUNT(*) FROM cobro WHERE id_socio_membresia='.(int)$idMembresia)->fetchColumn() === 1);

pruebasLimpiarMembresias($db, "sm.id_socio = $socio");
$error = '';
$ibanOriginal = $db->query('SELECT iban FROM usuario WHERE id_usuario=' . $socio)->fetchColumn();
$db->prepare('UPDATE usuario SET iban=:iban WHERE id_usuario=:id')->execute([':iban'=>'ES9121000418450200051332', ':id'=>$socio]);
$idMembresia = $m->contratar($socio, (int) $tipo['id_tipo_membresia'], 'transferencia', $error, null, 'mostrador', 'f9000000000000000000000000000002', 1);
$estado = $finanzas->estado($socio);
check('domiciliación nace pendiente, no pagada por defecto', $estado['estado_economico'] === 'PENDIENTE' && $estado['deuda_cents'] === Money::cents($precioOriginal));
check('deuda pendiente deja acceso en revisar, no bloquea sin política', $acceso->evaluar($socio)['estado'] === 'REVISAR');

$error = '';
$sepa->crearMandato($socio, 'ES9121000418450200051332', '2026-08-01', $error);
$idRemesa = $sepa->crearRemesa([$idMembresia], 'F9 TEST Cuota', '2026-08-22', 1, $error, 'f9000000000000000000000000000003');
check('remesa crea intento presentado', (string)$db->query('SELECT estado FROM cobro WHERE id_socio_membresia='.(int)$idMembresia)->fetchColumn() === 'presentado');
check('remesa solo transiciona borrador a enviada', $sepa->marcarEnviada((int)$idRemesa) === true && $sepa->marcarEnviada((int)$idRemesa) === false);
check('remesa enviada se confirma una vez', $sepa->marcarCobrada((int)$idRemesa, 1) === true && $sepa->marcarCobrada((int)$idRemesa, 1) === false);
$estado = $finanzas->estado($socio);
check('cobro confirmado deja deuda cero', $estado['deuda_cents'] === 0 && $estado['estado_economico'] === 'AL_CORRIENTE');

$idRecibo = (int)$db->query('SELECT id_recibo FROM remesa_recibo WHERE id_remesa='.(int)$idRemesa)->fetchColumn();
check('devolución se registra una sola vez', $sepa->marcarDevuelto($idRecibo, 'F9 fondos insuficientes', 1) === true);
check('devolución repetida se rechaza', $sepa->marcarDevuelto($idRecibo, 'F9 repetida', 1) === false);
$estado = $finanzas->estado($socio);
check('devolución reactiva deuda exacta', $estado['estado_economico'] === 'DEVUELTO' && $estado['deuda_cents'] === Money::cents($precioOriginal));
check('cobro original permanece como devuelto', (string)$db->query('SELECT estado FROM cobro WHERE id_remesa_recibo='.$idRecibo)->fetchColumn() === 'devuelto');

$idObligacion = (int)$db->query('SELECT id_obligacion FROM obligacion_pago WHERE id_socio_membresia='.(int)$idMembresia)->fetchColumn();
$stmt = $db->prepare("INSERT INTO cobro (id_empresa,id_gimnasio,id_socio,id_obligacion,id_socio_membresia,concepto,importe,metodo,estado,id_usuario,origen,idempotency_key) VALUES (1,1,:socio,:obligacion,:membresia,'Pago posterior',:importe,'transferencia','confirmado',1,'ajuste','f9-pago-posterior')");
$stmt->execute([':socio'=>$socio, ':obligacion'=>$idObligacion, ':membresia'=>$idMembresia, ':importe'=>$precioOriginal]);
$estado = $finanzas->estado($socio);
check('pago posterior resuelve la deuda sin borrar devolución', $estado['deuda_cents'] === 0 && $estado['recibos_devueltos'] === 1 && $estado['estado_economico'] === 'AL_CORRIENTE');

// Dos obligaciones sintéticas prueban suma decimal y un importe grande razonable.
$insertO = $db->prepare("INSERT INTO obligacion_pago (id_empresa,id_gimnasio,id_socio,concepto,importe,fecha_emision,fecha_vencimiento,estado,origen,idempotency_key) VALUES (1,1,:socio,:concepto,:importe,CURDATE(),CURDATE(),'pendiente','ajuste',:clave)");
$insertO->execute([':socio'=>$socio, ':concepto'=>'F9 decimal', ':importe'=>'29.99', ':clave'=>'f9-deuda-decimal']);
$insertO->execute([':socio'=>$socio, ':concepto'=>'F9 grande', ':importe'=>'9999.99', ':clave'=>'f9-deuda-grande']);
$estado = $finanzas->estado($socio);
check('varias deudas suman céntimos exactos', $estado['deuda_cents'] === 1002998 && $estado['deuda'] === '10029.98');

$db->exec("DELETE FROM obligacion_pago WHERE idempotency_key IN ('f9-deuda-decimal','f9-deuda-grande')");
pruebasLimpiarRemesas($db, "r.concepto LIKE 'F9 TEST %'");
$db->exec("DELETE FROM mandato_sepa WHERE id_socio = $socio");
pruebasLimpiarMembresias($db, "sm.id_socio = $socio");
$db->prepare('UPDATE usuario SET iban=:iban WHERE id_usuario=:id')->execute([':iban'=>$ibanOriginal !== false ? $ibanOriginal : null, ':id'=>$socio]);
finishTests();
