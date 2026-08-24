<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/services/RetentionService.php';

$db = Database::getInstance()->getConnection();
$demo = null;

/** @return float */
function f24Percentile(array $values, float $percentile): float
{
    sort($values,SORT_NUMERIC);
    return (float)$values[(int)floor((count($values)-1)*$percentile)];
}

try {
    $demo = DemoGymFactory::create($db);
    $company = (int)$demo['empresa'];
    $site = (int)$demo['sedes'][0];
    $type = (int)$demo['tarifa'];
    $hash = password_hash('synthetic-only-f24-performance',PASSWORD_BCRYPT,['cost'=>4]);
    $user = $db->prepare(
        "INSERT INTO usuario (id_empresa,id_gimnasio,nombre,apellidos,dni,email,nombre_usuario,contrasena,rol,activo)
         VALUES (:company,:site,'Socio','Performance',:dni,:email,:username,:password,'socio',1)"
    );
    $membership = $db->prepare(
        "INSERT INTO socio_membresia
         (id_socio,id_gimnasio,id_tipo_membresia,nombre_tipo,precio_pagado,metodo_pago,fecha_inicio,fecha_fin,estado_pago,idempotency_key)
         VALUES (:member,:site,:type,'Gimnasio Performance',30,'efectivo','2026-01-01','2026-12-31','pagado',:key)"
    );
    $event = $db->prepare(
        "INSERT INTO attendance_event
         (event_id,id_empresa,id_gimnasio,id_socio,occurred_at_utc,local_date,source,external_reference,idempotency_key)
         VALUES (:uuid,:company,:site,:member,:occurred,:date,'IMPORT',:external,:key)"
    );
    $memberIds = [];
    $db->beginTransaction();
    for ($i=1;$i<=5000;$i++) {
        $suffix = sprintf('%05d',$i);
        $user->execute([
            ':company'=>$company,':site'=>$site,':dni'=>'F24PERF'.$suffix,
            ':email'=>'f24perf'.$suffix.'@test.invalid',':username'=>'f24perf'.$suffix,':password'=>$hash,
        ]);
        $member = (int)$db->lastInsertId();
        $memberIds[] = $member;
        $membership->execute([':member'=>$member,':site'=>$site,':type'=>$type,':key'=>'f24p-'.$suffix]);
        if ($i === 1000) break;
    }
    $weeks = [];
    $origin = new DateTimeImmutable('2026-06-12');
    for ($week=0;$week<10;$week++) $weeks[] = $origin->modify('+'.($week*7).' days')->format('Y-m-d');
    foreach ($memberIds as $member) foreach ($weeks as $index=>$date) {
        $external = 'f24p-'.$member.'-'.$index.'-a';
        $hashValue = hash('sha256',$external);
        $uuid = substr($hashValue,0,8).'-'.substr($hashValue,8,4).'-4'.substr($hashValue,13,3).'-8'.substr($hashValue,17,3).'-'.substr($hashValue,20,12);
        $event->execute([
            ':uuid'=>$uuid,':company'=>$company,':site'=>$site,':member'=>$member,
            ':occurred'=>$date.' 10:00:00',':date'=>$date,':external'=>$external,
            ':key'=>hash('sha256',$company.'|IMPORT|external:'.$external),
        ]);
    }
    $db->commit();
    $service = new RetentionService($db,$company);
    $start = microtime(true);
    $result1k = $service->run('2026-08-20');
    $job1k = (microtime(true)-$start)*1000;
    $inboxTimes=[];
    for($i=0;$i<20;$i++){ $s=microtime(true); $service->inbox(100); $inboxTimes[]=(microtime(true)-$s)*1000; }
    $profile = $db->prepare('SELECT COUNT(DISTINCT local_date),MAX(occurred_at_utc) FROM attendance_event WHERE id_empresa=:company AND id_socio=:member');
    $profileTimes=[];
    for($i=0;$i<50;$i++){ $s=microtime(true); $profile->execute([':company'=>$company,':member'=>$memberIds[$i%1000]]); $profile->fetch(); $profileTimes[]=(microtime(true)-$s)*1000; }
    printf("METRICA_F24 dataset=1000/10000 job_ms=%.2f inbox_p50_ms=%.2f inbox_p95_ms=%.2f ficha_p50_ms=%.2f ficha_p95_ms=%.2f\n",
        $job1k,f24Percentile($inboxTimes,.50),f24Percentile($inboxTimes,.95),f24Percentile($profileTimes,.50),f24Percentile($profileTimes,.95));
    check('1k/10k evalúa 1000 socios sin errores', $result1k['evaluated']===1000);

    $db->beginTransaction();
    for ($i=1001;$i<=5000;$i++) {
        $suffix = sprintf('%05d',$i);
        $user->execute([
            ':company'=>$company,':site'=>$site,':dni'=>'F24PERF'.$suffix,
            ':email'=>'f24perf'.$suffix.'@test.invalid',':username'=>'f24perf'.$suffix,':password'=>$hash,
        ]);
        $member = (int)$db->lastInsertId();
        $memberIds[]=$member;
        $membership->execute([':member'=>$member,':site'=>$site,':type'=>$type,':key'=>'f24p-'.$suffix]);
    }
    foreach ($memberIds as $memberIndex=>$member) {
        $repetitions = $memberIndex < 1000 ? 1 : 2;
        for ($repeat=0;$repeat<$repetitions;$repeat++) foreach ($weeks as $index=>$date) {
            $suffixEvent = $repeat === 0 ? 'b' : 'c';
            $external='f24p-'.$member.'-'.$index.'-'.$suffixEvent;
            $hashValue=hash('sha256',$external);
            $uuid=substr($hashValue,0,8).'-'.substr($hashValue,8,4).'-4'.substr($hashValue,13,3).'-8'.substr($hashValue,17,3).'-'.substr($hashValue,20,12);
            $event->execute([
                ':uuid'=>$uuid,':company'=>$company,':site'=>$site,':member'=>$member,
                ':occurred'=>$date.($repeat===0?' 18:00:00':' 20:00:00'),':date'=>$date,':external'=>$external,
                ':key'=>hash('sha256',$company.'|IMPORT|external:'.$external),
            ]);
        }
    }
    $db->commit();
    $start=microtime(true);
    $result5k=$service->run('2026-08-21');
    $job5k=(microtime(true)-$start)*1000;
    $inboxTimes=[];
    for($i=0;$i<20;$i++){ $s=microtime(true); $service->inbox(100); $inboxTimes[]=(microtime(true)-$s)*1000; }
    $profileTimes=[];
    for($i=0;$i<50;$i++){ $s=microtime(true); $profile->execute([':company'=>$company,':member'=>$memberIds[$i*97%5000]]); $profile->fetch(); $profileTimes[]=(microtime(true)-$s)*1000; }
    $rawEvents=(int)$db->query("SELECT COUNT(*) FROM attendance_event WHERE id_empresa={$company}")->fetchColumn();
    printf("METRICA_F24 dataset=5000/100000 job_ms=%.2f inbox_p50_ms=%.2f inbox_p95_ms=%.2f ficha_p50_ms=%.2f ficha_p95_ms=%.2f memory_peak_bytes=%d\n",
        $job5k,f24Percentile($inboxTimes,.50),f24Percentile($inboxTimes,.95),f24Percentile($profileTimes,.50),f24Percentile($profileTimes,.95),memory_get_peak_usage(true));
    check('5k/100k contiene volumen exacto', count($memberIds)===5000 && $rawEvents===100000);
    check('5k/100k evalúa 5000 socios', $result5k['evaluated']===5000);
    check('job grande queda bajo umbral no regresivo de 60s', $job5k<60000);
    check('bandeja p95 queda bajo 500ms', f24Percentile($inboxTimes,.95)<500);
    check('ficha p95 queda bajo 250ms', f24Percentile($profileTimes,.95)<250);
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    check('escenario de rendimiento Retention',false);
    fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");
} finally {
    if ($demo!==null) DemoGymFactory::cleanup($db);
}
finishTests();
