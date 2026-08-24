<?php

require_once dirname(__DIR__,2).'/app/config/config.php';
require_once dirname(__DIR__,2).'/app/helpers/Csrf.php';
require_once dirname(__DIR__,2).'/app/helpers/RequestContext.php';

$sessionDir=getenv('SESSION_DIR')?:sys_get_temp_dir();
session_save_path($sessionDir);
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
$_SESSION=[
    'logueado'=>true,'usuario_id'=>1,'usuario_rol'=>'admin','usuario_nombre'=>'admin_f241',
    'usuario_nombre_real'=>'Admin Sintético','gimnasio_auth_id'=>1,'gimnasio_nombre'=>'Gimnasio Sintético',
];
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['REMOTE_ADDR']='127.0.0.1';
$_GET=['action'=>'retention'];

$pageTitle='Retención';$paginaActiva='retention';$selectedSite=1;$sites=[];
$metrics=['total'=>2,'reviewed'=>0,'dismissed'=>0,'contacted'=>1,'returned'=>1,'evaluated'=>5,
    'insufficient'=>1,'normal'=>1,'attention'=>1,'high_attention'=>1];
$base=['activity_family'=>'GYM','activity_label'=>'Gym','workflow_label'=>'Pendiente','workflow_status'=>'OPEN',
    'sede_nombre'=>'Sede Centro','contacted_at_utc'=>null,'baseline_weekly_rate'=>4.0,'recent_weekly_rate'=>0.0,
    'last_attendance_label'=>'Hace 18 días','version'=>1];
$detections=[
    $base+['id_retention_detection'=>1,'nombre'=>'Lucía','apellidos'=>'Demo','display_state'=>'HIGH_ATTENTION',
        'drop_pct'=>100,
        'state_label'=>'Hace tiempo que no viene','explanation'=>'Su frecuencia habitual era de unas 4 visitas por semana y no ha registrado visitas durante los últimos 14 días.',
        'suggested_message'=>'Hola, Lucía. Cuando quieras volver a entrenar, aquí te esperamos.'],
    $base+['id_retention_detection'=>2,'nombre'=>'Mario','apellidos'=>'Demo','display_state'=>'ATTENTION',
        'drop_pct'=>75,
        'state_label'=>'Rutina a medias','recent_weekly_rate'=>1.0,'last_attendance_label'=>'Hace 4 días',
        'explanation'=>'Solía venir unas 4 veces por semana y recientemente viene alrededor de 1.',
        'suggested_message'=>'Hola, Mario. Te echamos de menos por el gimnasio.'],
];
$returned=[['nombre'=>'Sara','apellidos'=>'Demo','returned_label'=>'21/08/2026','days_to_return'=>3,
    'activity_label'=>'Boxeo','sede_nombre'=>'Sede Centro']];
$recentVisits=[];foreach([
    ['Hoy, 10:24','Pedro','García','Boxeo'],['Hoy, 09:42','Elena','Demo','Gym'],['Ayer, 20:10','Nora','Demo','Tatami'],
] as $visit)$recentVisits[]=['relative_datetime'=>$visit[0],'nombre'=>$visit[1],'apellidos'=>$visit[2],
    'activity_label'=>$visit[3],'sede_nombre'=>'Sede Centro'];
$search=['query'=>'','pagination'=>['total'=>0,'page'=>1,'pages'=>1,'per_page'=>12],'items'=>[]];
require dirname(__DIR__,2).'/app/views/admin/retention.php';
