<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/services/AttendanceEventService.php';

$db=Database::getInstance()->getConnection();
$demo=null;
try{
    $demo=DemoGymFactory::create($db);
    $company=(int)$demo['empresa']; $site=(int)$demo['sedes'][0]; $member=(int)$demo['socios'][0];
    $events=new AttendanceEventService($db,$company,new DateTimeZone('Europe/Madrid'));
    $before=$events->record($site,$member,new DateTimeImmutable('2026-03-28 22:30:00Z'),'IMPORT','dst-before-midnight');
    $after=$events->record($site,$member,new DateTimeImmutable('2026-03-28 23:30:00Z'),'IMPORT','dst-after-midnight');
    $jumpOne=$events->record($site,$member,new DateTimeImmutable('2026-03-29 00:30:00Z'),'IMPORT','dst-jump-one');
    $jumpTwo=$events->record($site,$member,new DateTimeImmutable('2026-03-29 01:30:00Z'),'IMPORT','dst-jump-two');
    check('medianoche usa fecha local del tenant', $before['local_date']==='2026-03-28' && $after['local_date']==='2026-03-29');
    check('cambio horario mantiene eventos en el día local correcto', $jumpOne['local_date']==='2026-03-29' && $jumpTwo['local_date']==='2026-03-29');
    check('varias entradas del mismo día cuentan como una visita', (int)$db->query(
        "SELECT COUNT(DISTINCT local_date) FROM attendance_event WHERE id_empresa={$company} AND id_socio={$member}"
    )->fetchColumn()===2);
    $outOfOrder=$events->record($site,$member,new DateTimeImmutable('2026-03-10 12:00:00Z'),'IMPORT','out-of-order-old');
    check('evento fuera de orden conserva su fecha real', $outOfOrder['local_date']==='2026-03-10');
    check('orden de ingesta no altera orden temporal', (string)$db->query(
        "SELECT GROUP_CONCAT(local_date ORDER BY local_date SEPARATOR ',') FROM attendance_event WHERE id_empresa={$company} AND id_socio={$member}"
    )->fetchColumn()==='2026-03-10,2026-03-28,2026-03-29,2026-03-29,2026-03-29');
    $future=false;
    try{$events->record($site,$member,new DateTimeImmutable('+1 day'),'MANUAL');}catch(InvalidArgumentException){$future=true;}
    check('evento futuro queda rechazado', $future);
}catch(Throwable $error){
    check('escenario timezone asistencia',false);
    fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");
}finally{if($demo!==null)DemoGymFactory::cleanup($db);}
finishTests();
