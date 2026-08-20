<?php
require_once dirname(__DIR__) . '/bootstrap.php';

$sesionesTmp = dirname(__DIR__, 2) . '/pruebas/sesiones_tmp';
if (!is_dir($sesionesTmp)) mkdir($sesionesTmp, 0700, true);
session_save_path($sesionesTmp);
session_start();

$_SESSION['logueado'] = true;
$_SESSION['usuario_id'] = 1;
$_SESSION['usuario_rol'] = 'admin';
$_SESSION['gimnasio_auth_id'] = 1;
$_SESSION['usuario_nombre'] = 'daniel';
$_SESSION['usuario_nombre_real'] = 'Daniel Admin';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = ['action'=>'admin_caja'];

require_once dirname(__DIR__, 2) . '/app/controllers/AdminController.php';
$ctrl = new AdminController();
ob_start(); $ctrl->mostrarCaja(); $html = ob_get_clean();
check('renderiza pantalla de caja sin errores', strpos($html, '<h1') !== false && strpos($html, 'Caja') !== false);
check('sin sesión muestra apertura con CSRF', strpos($html, 'Confirmar apertura') !== false && strpos($html, 'name="_csrf"') !== false);
check('el menú contiene Caja', preg_match('#action=admin_caja[^>]*>.*?Caja#s', $html) === 1);
check('no expone ninguna integración DORLET', stripos($html, 'dorlet') === false && stripos($html, 'huella') === false);
finishTests();
