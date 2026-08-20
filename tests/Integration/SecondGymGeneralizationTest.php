<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';

$db = Database::getInstance()->getConnection();
$demo = null;

try {
    $demo = DemoGymFactory::create($db);
    $empresaId = $demo['empresa'];
    $sedeCentro = $demo['sedes'][0];
    $sedeRio = $demo['sedes'][1];

    check('segundo gimnasio nace como empresa independiente', $empresaId > 1);
    check('segundo gimnasio dispone de dos sedes propias', (int) $db->query("SELECT COUNT(*) FROM gimnasio WHERE id_empresa = {$empresaId}")->fetchColumn() === 2);
    check('dirección pertenece a empresa y no a una sede', (int) $db->query("SELECT COUNT(*) FROM usuario WHERE id_usuario = {$demo['direccion']} AND id_empresa = {$empresaId} AND id_gimnasio IS NULL AND rol = 'direccion'")->fetchColumn() === 1);
    check('recepción queda limitada a su sede', (int) $db->query("SELECT COUNT(*) FROM usuario WHERE id_usuario = {$demo['recepcion']} AND id_empresa = {$empresaId} AND id_gimnasio = {$sedeCentro} AND rol = 'recepcion'")->fetchColumn() === 1);
    check('socios sintéticos pertenecen a empresa y sede', (int) $db->query("SELECT COUNT(*) FROM usuario WHERE id_empresa = {$empresaId} AND id_gimnasio = {$sedeCentro} AND rol = 'socio'")->fetchColumn() === 2);
    check('tarifa pertenece al tenant y a su sede', (int) $db->query("SELECT COUNT(*) FROM tipo_membresia WHERE id_tipo_membresia = {$demo['tarifa']} AND id_empresa = {$empresaId} AND id_gimnasio = {$sedeCentro}")->fetchColumn() === 1);
    check('producto pertenece a la sede y conserva stock tras venta', (int) $db->query("SELECT stock FROM producto WHERE id_producto = {$demo['producto']} AND id_gimnasio = {$sedeCentro}")->fetchColumn() === 8);
    check('venta queda ligada a socio, empleado y sede correctos', (int) $db->query("SELECT COUNT(*) FROM venta WHERE id_venta = {$demo['venta']} AND id_gimnasio = {$sedeCentro} AND id_socio = {$demo['socios'][0]} AND id_usuario_registro = {$demo['recepcion']}")->fetchColumn() === 1);
    check('caja y movimiento conservan empresa y sede', (int) $db->query("SELECT COUNT(*) FROM caja_movimiento WHERE id_venta = {$demo['venta']} AND id_empresa = {$empresaId} AND id_gimnasio = {$sedeCentro} AND id_sesion_caja = {$demo['sesion_caja']}")->fetchColumn() === 1);

    $otraEmpresa = (int) $db->query("SELECT id_empresa FROM empresa WHERE id_empresa <> {$empresaId} ORDER BY id_empresa LIMIT 1")->fetchColumn();
    check('la base de prueba contiene un tenant previo distinto', $otraEmpresa > 0 && $otraEmpresa !== $empresaId);
    check('otra empresa no ve el socio demo', (new UserModel(null, $otraEmpresa))->buscarPorId($demo['socios'][0]) === null);
    check('otra empresa no ve el producto demo', (new ProductoModel(null, $otraEmpresa))->buscarPorId($demo['producto']) === null);
    check('otra empresa no ve la venta demo', (new VentaModel(null, $otraEmpresa))->buscarPorId($demo['venta']) === null);
    check('otra empresa no ve las sedes demo', (new GimnasioModel($otraEmpresa))->buscarPorId($sedeRio) === null);
    check('la empresa demo sí ve datos de sus dos sedes', count((new GimnasioModel($empresaId))->listar()) === 2);
    check('el aprovisionamiento técnico pendiente está explicitado', $demo['aprovisionamiento_sql'] === ['empresa', 'direccion']);
} catch (Throwable $e) {
    check('configuración completa de Gimnasio Demo Norte', false, $e->getMessage());
} finally {
    DemoGymFactory::cleanup($db);
}

finishTests();
