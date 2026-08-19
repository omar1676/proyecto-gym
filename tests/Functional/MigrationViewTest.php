<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/helpers/Menu.php';

$sesionesTmp=dirname(__DIR__,2).'/pruebas/sesiones_tmp';
if(!is_dir($sesionesTmp)) mkdir($sesionesTmp,0700,true);
session_save_path($sesionesTmp); session_start();
$db=Database::getInstance()->getConnection();
$super=(int)$db->query("SELECT id_usuario FROM usuario WHERE rol='superadmin' ORDER BY id_usuario LIMIT 1")->fetchColumn();
$_SESSION=['logueado'=>true,'usuario_id'=>$super,'usuario_rol'=>'superadmin','gimnasio_auth_id'=>1,
    'gimnasio_activo'=>1,'usuario_nombre'=>'empresa','usuario_nombre_real'=>'Dirección'];
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REMOTE_ADDR']='127.0.0.1'; $_GET=['action'=>'admin_importaciones'];
require_once dirname(__DIR__,2).'/app/controllers/AdminController.php';
$ctrl=new AdminController(); ob_start(); $ctrl->mostrarImportaciones(); $html=ob_get_clean();
check('la pantalla ofrece seleccionar un archivo CSV', preg_match('#<input[^>]+type="file"[^>]+name="archivo"#i',$html)===1);
check('la pantalla explica el paso de simulación', stripos($html,'simular')!==false);
check('la subida usa POST multipart y CSRF', preg_match('#<form[^>]+method="POST"[^>]+enctype="multipart/form-data"#i',$html)===1 && str_contains($html,'name="_csrf"'));
check('dirección ve Importaciones en el menú', str_contains(Menu::render('direccion',''),'Importaciones'));
check('administrador de sede no ve Importaciones', !str_contains(Menu::render('admin',''),'Importaciones'));
check('recepción no ve Importaciones', !str_contains(Menu::render('recepcion',''),'Importaciones'));
finishTests();
