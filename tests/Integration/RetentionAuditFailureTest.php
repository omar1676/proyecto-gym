<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/services/RetentionService.php';

$db=Database::getInstance()->getConnection();
$demo=null;
try{
    $demo=DemoGymFactory::create($db);
    $company=(int)$demo['empresa']; $site=(int)$demo['sedes'][0]; $member=(int)$demo['socios'][0]; $type=(int)$demo['tarifa'];
    $db->prepare(
        "INSERT INTO socio_membresia
         (id_socio,id_gimnasio,id_tipo_membresia,nombre_tipo,precio_pagado,metodo_pago,fecha_inicio,fecha_fin,estado_pago,idempotency_key)
         VALUES (:member,:site,:type,'Gimnasio fallo audit',40,'efectivo','2026-01-01','2026-12-31','pagado','f24-audit-failure')"
    )->execute([':member'=>$member,':site'=>$site,':type'=>$type]);
    $insert=$db->prepare(
        "INSERT INTO attendance_event
         (event_id,id_empresa,id_gimnasio,id_socio,occurred_at_utc,local_date,source,external_reference,idempotency_key)
         VALUES (:uuid,:company,:site,:member,:occurred,:date,'IMPORT',:external,:key)"
    );
    $start=new DateTimeImmutable('2026-06-12');
    for($week=0;$week<8;$week++)for($visit=0;$visit<4;$visit++){
        $date=$start->modify('+'.($week*7+$visit).' days')->format('Y-m-d'); $ref="audit-{$week}-{$visit}";
        $insert->execute([':uuid'=>RequestContext::newId(),':company'=>$company,':site'=>$site,':member'=>$member,
            ':occurred'=>$date.' 10:00:00',':date'=>$date,':external'=>$ref,':key'=>hash('sha256',$ref)]);
    }
    $db->exec('DROP TRIGGER IF EXISTS f24_fail_retention_audit');
    $db->exec(
        "CREATE TRIGGER f24_fail_retention_audit BEFORE INSERT ON log_actividad FOR EACH ROW
         BEGIN IF NEW.accion='RETENTION_DETECTED' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'; END IF; END"
    );
    $failed=false;
    try{(new RetentionService($db,$company))->run('2026-08-20');}catch(Throwable){$failed=true;}
    check('fallo REQUIRED de auditoría hace fallar el job', $failed);
    check('fallo de auditoría revierte detección', (int)$db->query("SELECT COUNT(*) FROM retention_detection WHERE id_empresa={$company}")->fetchColumn()===0);
    check('run queda FAILED, no COMPLETED falso', (string)$db->query("SELECT status FROM retention_run WHERE id_empresa={$company}")->fetchColumn()==='FAILED');
    check('no existe auditoría de éxito parcial', (int)$db->query("SELECT COUNT(*) FROM log_actividad WHERE id_empresa={$company} AND accion='RETENTION_DETECTED'")->fetchColumn()===0);
    $db->exec('DROP TRIGGER f24_fail_retention_audit');
    $recovered=(new RetentionService($db,$company))->run('2026-08-20');
    check('reintento tras recuperar auditoría completa el mismo run', $recovered['high_attention']===1
        && (string)$db->query("SELECT status FROM retention_run WHERE id_empresa={$company}")->fetchColumn()==='COMPLETED');
}catch(Throwable $error){
    try{$db->exec('DROP TRIGGER IF EXISTS f24_fail_retention_audit');}catch(Throwable){}
    check('escenario fault injection Retention',false);
    fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");
}finally{if($demo!==null)DemoGymFactory::cleanup($db);}
finishTests();
