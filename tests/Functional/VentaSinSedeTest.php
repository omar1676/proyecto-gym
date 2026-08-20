<?php
require_once dirname(__DIR__) . '/bootstrap.php';

$sesionesTmp = dirname(__DIR__, 2) . '/pruebas/sesiones_tmp';
if (!is_dir($sesionesTmp)) mkdir($sesionesTmp, 0700, true);
session_save_path($sesionesTmp);
session_start();

$db = Database::getInstance()->getConnection();
$idDireccion = (int) $db->query("SELECT id_usuario FROM usuario WHERE rol IN ('direccion','superadmin') ORDER BY FIELD(rol,'direccion','superadmin'), id_usuario LIMIT 1")->fetchColumn();
if ($idDireccion <= 0) {
    $idDireccion = (int) $db->query("SELECT id_usuario FROM usuario WHERE rol = 'superadmin' ORDER BY id_usuario LIMIT 1")->fetchColumn();
}

$_SESSION['logueado'] = true;
$_SESSION['usuario_id'] = $idDireccion;
$_SESSION['usuario_rol'] = 'superadmin';
$_SESSION['gimnasio_auth_id'] = 1;
$_SESSION['usuario_nombre'] = 'fase7';
$_SESSION['usuario_nombre_real'] = 'Dirección Fase 7';
unset($_SESSION['gimnasio_activo']);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = ['action' => 'admin_ventas'];

require_once dirname(__DIR__, 2) . '/app/controllers/AdminController.php';
$ctrl = new AdminController();
ob_start();
$ctrl->mostrarVentas();
$html = ob_get_clean();

check('la vista global avisa antes de iniciar una venta', strpos($html, 'Selecciona una sede antes de iniciar una venta.') !== false);
check('la vista global no renderiza el formulario de cobro', strpos($html, 'id="form-venta"') === false);
check('el aviso explica el impacto en stock y caja', strpos($html, 'El stock, la caja y la numeración') !== false);
finishTests();
