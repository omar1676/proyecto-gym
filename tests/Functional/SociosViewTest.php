<?php
require_once dirname(__DIR__) . '/bootstrap.php';

$sesionesTmp = dirname(__DIR__, 2) . '/pruebas/sesiones_tmp';
if (!is_dir($sesionesTmp)) {
    mkdir($sesionesTmp, 0700, true);
}
session_save_path($sesionesTmp);
session_start();

$db = Database::getInstance()->getConnection();
$idSuperadmin = (int) $db->query("SELECT id_usuario FROM usuario WHERE rol = 'superadmin' ORDER BY id_usuario LIMIT 1")->fetchColumn();
$_SESSION['logueado'] = true;
$_SESSION['usuario_id'] = $idSuperadmin;
$_SESSION['usuario_rol'] = 'superadmin';
$_SESSION['gimnasio_auth_id'] = 1;
$_SESSION['usuario_nombre'] = 'admin';
$_SESSION['usuario_nombre_real'] = 'Admin Sistema';

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = ['action' => 'admin_socios'];

require_once dirname(__DIR__, 2) . '/app/controllers/AdminController.php';
$ctrl = new AdminController();
ob_start();
$ctrl->mostrarSocios();
$html = ob_get_clean();

$encontroFormulario = preg_match(
    '#<form[^>]+action="index\.php\?action=admin_socio_editar"[^>]*>(.*?)</form>#s',
    $html,
    $formulario
) === 1;

check('renderiza el formulario de edición de socio', $encontroFormulario);
check(
    'Guardar cambios pertenece al formulario de edición',
    $encontroFormulario && strpos($formulario[1], 'Guardar cambios') !== false
);
check(
    'el formulario de edición no está oculto',
    $encontroFormulario && stripos($formulario[0], 'display:none') === false
);
check('el buscador informa que admite teléfono', strpos($html, 'email, teléfono o DNI') !== false);
check('edición permite corregir DNI/NIE', strpos($html, 'id="editar-dni"') !== false);
check('edición envía versión optimista', strpos($html, 'id="editar-profile-version"') !== false);
check('alta y edición preparan campos DNI accesibles', strpos($html, '<label for="alta-dni"') !== false
    && strpos($html, '<label for="editar-dni"') !== false
    && substr_count($html, 'name="dni"') >= 2);
check('layout de socios puede encogerse sin empujar el viewport', strpos($html, '<main class="flex-1 min-w-0') !== false);
check('el listado informa total y página actual', strpos($html, 'resultado') !== false && strpos($html, 'Página 1 de') !== false);
check('las operaciones conservan búsqueda y página', strpos($html, 'name="volver_buscar"') !== false && strpos($html, 'name="volver_pagina"') !== false);
check('los modales principales declaran semántica dialog', substr_count($html, 'role="dialog"') >= 3 && substr_count($html, 'aria-modal="true"') >= 3);
check('Salir lateral usa POST con CSRF', preg_match('#<form[^>]+method="POST"[^>]+action="[^"]*action=logout"#i', $html) === 1);
check('no queda un enlace GET de logout', preg_match('#<a[^>]+href="[^"]*action=logout"#i', $html) === 0);
check('el listado muestra estado económico y acceso lógico', strpos($html, 'Economía / acceso') !== false && strpos($html, 'Acceso:') !== false);
check('listado paginado conserva el ámbito validado por servidor', strpos($html, 'MEMBER_NOT_FOUND_OR_OUT_OF_SCOPE') === false);
check('dirección/admin puede abrir el detalle económico y de acceso', strpos($html, 'detalle=') !== false && strpos($html, '>Economía / acceso</a>') !== false);
finishTests();
