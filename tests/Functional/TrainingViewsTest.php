<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/helpers/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/helpers/Csrf.php';
require_once dirname(__DIR__, 2) . '/app/helpers/Menu.php';
require_once dirname(__DIR__, 2) . '/app/helpers/RequestContext.php';
require_once dirname(__DIR__, 2) . '/app/helpers/TrainingPolicy.php';
require_once dirname(__DIR__, 2) . '/app/helpers/TrainingPresentation.php';

$root=dirname(__DIR__,2);$sessionDir=sys_get_temp_dir().'/gimnera_f25a_view_'.bin2hex(random_bytes(5));mkdir($sessionDir,0700,true);session_save_path($sessionDir);session_start();
$_SESSION=['usuario_rol'=>'admin','usuario_id'=>1,'usuario_nombre'=>'admin','usuario_nombre_real'=>'Admin Sintético','gimnasio_nombre'=>'Tenant Sintético'];
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REMOTE_ADDR']='127.0.0.1';RequestContext::resetForTests('f25a0000-0000-4000-8000-000000000001','WEB');

$sources=[];foreach(['training_library.php','training_templates.php','training_plans.php'] as $name)$sources[$name]=(string)file_get_contents($root.'/app/views/admin/'.$name);
$all=implode("\n",$sources);
check('navegación ofrece Biblioteca Plantillas Planes',str_contains($all,'Biblioteca')&&str_contains($all,'Plantillas')&&str_contains($all,'Planes'));
check('formularios mutables son POST y llevan CSRF',substr_count($all,'method="POST"')>=8&&substr_count($all,'Csrf::field()')>=8);
check('controles táctiles conservan altura mínima',substr_count($all,'min-h-11')>=25);
check('layout principal responde desde móvil a escritorio',substr_count($all,'sm:')>=15&&substr_count($all,'lg:')>=3);
check('vista de planes explica snapshot independiente',str_contains($sources['training_plans.php'],'copia independiente'));
check('UI advierte no usar notas clínicas',preg_match('/diagnósticos.*lesiones.*medicación/iu',$all)===1);
check('vista escapa datos de usuario',substr_count($all,'htmlspecialchars')>=3);
check('pantallas incluyen estados vacíos comprensibles',str_contains($all,'Todavía no hay')&&str_contains($all,'Sin ejercicios'));
check('circuito muestra vueltas y descansos',str_contains($all,'Vueltas si es circuito')&&str_contains($all,'Descanso entre vueltas'));
check('no introduce IA ni generación automática',!preg_match('/OpenAI|Anthropic|Gemini|generar automáticamente/iu',$all));
check('recepción no recibe menú Training',!str_contains(Menu::render('recepcion',''),'Entrenamientos'));
check('dirección y admin reciben menú Training',str_contains(Menu::render('direccion','training'),'Entrenamientos')&&str_contains(Menu::render('admin','training'),'Entrenamientos'));
check('superadmin global no recibe menú tenant implícito',!str_contains(Menu::render('superadmin',''),'Entrenamientos'));
check('RBAC no crea rol entrenador improvisado',!Authorization::can('recepcion','training.manage')&&!Authorization::can('socio','training.manage'));

$controller=(string)file_get_contents($root.'/app/controllers/TrainingController.php');$router=(string)file_get_contents($root.'/public/index.php');
check('router publica solo acciones Training explícitas',str_contains($router,"'training_library'")&&str_contains($router,"'training_plan_assign'"));
check('snapshot visual tiene endpoint privado propio',str_contains($router,"'training_plan_media'")
    && str_contains($sources['training_plans.php'],'action=training_plan_media'));
check('vista de plan permite revisar historial ya persistido',str_contains($sources['training_plans.php'],'Historial de sesiones'));
check('controlador no acepta empresa desde navegador',!preg_match('/\$_(?:POST|GET)\[[\'\"](?:empresa_id|id_empresa)[\'\"]\]/',$controller));
check('errores técnicos no se muestran al usuario',str_contains($controller,'training_plan_create_failed')&&str_contains($controller,'No se pudo crear el plan.'));

session_write_close();foreach(glob($sessionDir.'/*')?:[] as $file)if(is_file($file))unlink($file);rmdir($sessionDir);
finishTests();
