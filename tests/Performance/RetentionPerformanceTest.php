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
        if ($i === 800) break;
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
    $dashboardTimes=[];$searchTimes=[];$historyTimes=[];
    for($i=0;$i<10;$i++){ $s=microtime(true);$service->metrics();$service->inbox(12);$service->recentVisits(10);$dashboardTimes[]=(microtime(true)-$s)*1000;
        $s=microtime(true);$service->search('Socio Performance',1,20);$searchTimes[]=(microtime(true)-$s)*1000;
        $s=microtime(true);$service->attendanceHistory([],1,20);$historyTimes[]=(microtime(true)-$s)*1000; }
    $profile = $db->prepare('SELECT COUNT(DISTINCT local_date),MAX(occurred_at_utc) FROM attendance_event WHERE id_empresa=:company AND id_socio=:member');
    $profileTimes=[];
    for($i=0;$i<50;$i++){ $s=microtime(true); $profile->execute([':company'=>$company,':member'=>$memberIds[$i%800]]); $profile->fetch(); $profileTimes[]=(microtime(true)-$s)*1000; }
    printf("METRICA_F241 dataset=800/8000 job_ms=%.2f dashboard_p95_ms=%.2f search_p95_ms=%.2f history_p95_ms=%.2f inbox_p95_ms=%.2f ficha_p95_ms=%.2f\n",
        $job1k,f24Percentile($dashboardTimes,.95),f24Percentile($searchTimes,.95),f24Percentile($historyTimes,.95),f24Percentile($inboxTimes,.95),f24Percentile($profileTimes,.95));
    check('800/8k evalúa 800 socios sin errores', $result1k['evaluated']===800);

    $db->beginTransaction();
    for ($i=801;$i<=5000;$i++) {
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
        $repetitions = $memberIndex < 800 ? 1 : 2;
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
    $dashboardTimes=[];$searchTimes=[];$historyTimes=[];$casesTimes=[];
    for($i=0;$i<10;$i++){ $s=microtime(true);$service->metrics();$service->inbox(12);$service->recentVisits(10);$dashboardTimes[]=(microtime(true)-$s)*1000;
        $s=microtime(true);$service->search('Socio Performance',1,20);$searchTimes[]=(microtime(true)-$s)*1000;
        $s=microtime(true);$service->attendanceHistory([],1,20);$historyTimes[]=(microtime(true)-$s)*1000;
        $s=microtime(true);$service->cases(['state'=>'all'],1,20);$casesTimes[]=(microtime(true)-$s)*1000; }
    $profileTimes=[];
    for($i=0;$i<50;$i++){ $s=microtime(true); $profile->execute([':company'=>$company,':member'=>$memberIds[$i*97%5000]]); $profile->fetch(); $profileTimes[]=(microtime(true)-$s)*1000; }
    $rawEvents=(int)$db->query("SELECT COUNT(*) FROM attendance_event WHERE id_empresa={$company}")->fetchColumn();
    printf("METRICA_F241 dataset=5000/100000 job_ms=%.2f dashboard_p95_ms=%.2f search_p95_ms=%.2f history_p95_ms=%.2f cases_p95_ms=%.2f inbox_p95_ms=%.2f ficha_p95_ms=%.2f memory_peak_bytes=%d\n",
        $job5k,f24Percentile($dashboardTimes,.95),f24Percentile($searchTimes,.95),f24Percentile($historyTimes,.95),f24Percentile($casesTimes,.95),f24Percentile($inboxTimes,.95),f24Percentile($profileTimes,.95),memory_get_peak_usage(true));
    check('5k/100k contiene volumen exacto', count($memberIds)===5000 && $rawEvents===100000);
    check('5k/100k evalúa 5000 socios', $result5k['evaluated']===5000);
    check('job grande queda bajo umbral no regresivo de 60s', $job5k<60000);
    check('bandeja p95 queda bajo 500ms', f24Percentile($inboxTimes,.95)<500);
    check('ficha p95 queda bajo 250ms', f24Percentile($profileTimes,.95)<250);
    check('dashboard p95 queda bajo 1500ms',f24Percentile($dashboardTimes,.95)<1500);
    check('búsqueda p95 queda bajo 1000ms',f24Percentile($searchTimes,.95)<1000);
    check('historial paginado p95 queda bajo 2000ms',f24Percentile($historyTimes,.95)<2000);
    check('todos los estados p95 queda bajo 1000ms',f24Percentile($casesTimes,.95)<1000);
    $latestRunId=(int)$db->query("SELECT MAX(id_retention_run) FROM retention_run WHERE id_empresa={$company} AND status='COMPLETED'")->fetchColumn();
    $latestPlan=$db->query("EXPLAIN SELECT * FROM attendance_daily_visit WHERE id_empresa={$company} ORDER BY occurred_at_utc DESC,id_socio DESC LIMIT 20")->fetch(PDO::FETCH_ASSOC);
    $historyPlan=$db->query("EXPLAIN SELECT * FROM attendance_daily_visit WHERE id_empresa={$company} AND id_gimnasio={$site} AND local_date>='2026-06-01' ORDER BY occurred_at_utc DESC LIMIT 20")->fetch(PDO::FETCH_ASSOC);
    $casesPlan=$db->query("EXPLAIN SELECT * FROM retention_member_snapshot WHERE id_empresa={$company} AND id_gimnasio={$site} AND id_retention_run={$latestRunId} AND state IN ('ATTENTION','HIGH_ATTENTION') LIMIT 20")->fetch(PDO::FETCH_ASSOC);
    $searchPlan=$db->query("EXPLAIN SELECT id_usuario FROM usuario WHERE id_empresa={$company} AND rol='socio' AND LOCATE('performance',LOWER(CONCAT_WS(' ',nombre,apellidos,COALESCE(telefono,''))))>0 LIMIT 20")->fetch(PDO::FETCH_ASSOC);
    printf("METRICA_F241_EXPLAIN latest_key=%s latest_rows=%d history_key=%s history_rows=%d cases_key=%s cases_rows=%d search_key=%s search_rows=%d\n",
        $latestPlan['key']??'NONE',(int)($latestPlan['rows']??0),$historyPlan['key']??'NONE',(int)($historyPlan['rows']??0),
        $casesPlan['key']??'NONE',(int)($casesPlan['rows']??0),$searchPlan['key']??'NONE',(int)($searchPlan['rows']??0));
    check('EXPLAIN últimas entradas dispone de índice dirigido',str_contains((string)($latestPlan['possible_keys']??''),'idx_attendance_daily_recent'));
    check('EXPLAIN historial dispone de índice por ámbito',str_contains((string)($historyPlan['possible_keys']??''),'idx_attendance_daily_scope'));
    check('EXPLAIN filtros dispone de índice dashboard',str_contains((string)($casesPlan['possible_keys']??''),'idx_retention_snapshot_dashboard'));
    check('EXPLAIN búsqueda conserva índice de ámbito tenant',str_contains((string)($searchPlan['possible_keys']??''),'empresa'));
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    check('escenario de rendimiento Retention',false);
    fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");
} finally {
    if ($demo!==null) DemoGymFactory::cleanup($db);
}
finishTests();
