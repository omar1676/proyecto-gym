<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/services/RetentionService.php';

$db = Database::getInstance()->getConnection();
$demo = null;
$barriers = [];
$processes = [];

/** @return list<array{exit:int,data:mixed,stderr:string}> */
function f24Workers(string $mode, array $arguments, string $barrier, int $count = 2): array
{
    $worker = dirname(__DIR__) . '/Support/retention_concurrency_worker.php';
    $running = [];
    for ($i=0; $i<$count; $i++) {
        $args = $arguments;
        if ($mode === 'action') $args[4] = RequestContext::newId();
        $command = array_merge([PHP_BINARY,$worker,$mode,$barrier], array_map('strval',$args));
        $spec = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $proc = proc_open($command,$spec,$pipes,dirname(__DIR__,2),null,['bypass_shell'=>true]);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $running[] = [$proc,$pipes];
        }
    }
    touch($barrier);
    $result = [];
    foreach ($running as [$proc,$pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $result[] = ['exit'=>proc_close($proc),'data'=>json_decode($stdout,true),'stderr'=>$stderr];
    }
    return $result;
}

try {
    $demo = DemoGymFactory::create($db);
    $company = (int)$demo['empresa'];
    $site = (int)$demo['sedes'][0];
    $member = (int)$demo['socios'][0];
    $type = (int)$demo['tarifa'];
    $db->prepare(
        "INSERT INTO socio_membresia
         (id_socio,id_gimnasio,id_tipo_membresia,nombre_tipo,precio_pagado,metodo_pago,fecha_inicio,fecha_fin,estado_pago,idempotency_key)
         VALUES (:member,:site,:type,'Gimnasio carrera',40,'efectivo','2026-01-01','2026-12-31','pagado','f24-race-membership')"
    )->execute([':member'=>$member,':site'=>$site,':type'=>$type]);
    $insert = $db->prepare(
        "INSERT INTO attendance_event
         (event_id,id_empresa,id_gimnasio,id_socio,occurred_at_utc,local_date,source,external_reference,idempotency_key)
         VALUES (:uuid,:company,:site,:member,:occurred,:date,'IMPORT',:external,:key)"
    );
    $start = new DateTimeImmutable('2026-06-12');
    for ($week=0; $week<8; $week++) for ($visit=0; $visit<4; $visit++) {
        $date = $start->modify('+' . ($week*7+$visit) . ' days')->format('Y-m-d');
        $ref = "race-base-{$week}-{$visit}";
        $insert->execute([
            ':uuid'=>RequestContext::newId(),':company'=>$company,':site'=>$site,':member'=>$member,
            ':occurred'=>$date.' 10:00:00',':date'=>$date,':external'=>$ref,
            ':key'=>hash('sha256',$company.'|IMPORT|external:'.$ref),
        ]);
    }

    $barrierEvent = sys_get_temp_dir() . '/gimnera-f24-event-' . bin2hex(random_bytes(8));
    $barriers[] = $barrierEvent;
    $eventResults = f24Workers('event', [$company,$site,$member,'2026-08-01 10:00:00','same-concurrent-event-f24'], $barrierEvent);
    check('dos procesos de evento arrancan', count($eventResults) === 2);
    check('reintento concurrente devuelve éxito coherente', count(array_filter($eventResults, fn($r)=>$r['exit']===0 && !empty($r['data']['success']))) === 2);
    check('solo un proceso crea el evento', count(array_filter($eventResults, fn($r)=>!empty($r['data']['created']))) === 1);
    check('DB conserva una sola external_reference', (int)$db->query(
        "SELECT COUNT(*) FROM attendance_event WHERE id_empresa={$company} AND external_reference='same-concurrent-event-f24'"
    )->fetchColumn() === 1);

    $barrierJob = sys_get_temp_dir() . '/gimnera-f24-job-' . bin2hex(random_bytes(8));
    $barriers[] = $barrierJob;
    $jobResults = f24Workers('job', [$company,'2026-08-20'], $barrierJob);
    check('dos jobs independientes terminan de forma explícita', count($jobResults) === 2
        && count(array_filter($jobResults, fn($r)=>in_array($r['exit'],[0,1],true))) === 2);
    check('solo existe una ejecución diaria', (int)$db->query(
        "SELECT COUNT(*) FROM retention_run WHERE id_empresa={$company} AND evaluation_date='2026-08-20'"
    )->fetchColumn() === 1);
    check('dos jobs no duplican detección', (int)$db->query(
        "SELECT COUNT(*) FROM retention_detection WHERE id_empresa={$company} AND id_socio={$member}"
    )->fetchColumn() === 1);

    $detection = $db->query("SELECT * FROM retention_detection WHERE id_empresa={$company} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $barrierAction = sys_get_temp_dir() . '/gimnera-f24-action-' . bin2hex(random_bytes(8));
    $barriers[] = $barrierAction;
    $actionResults = f24Workers('action', [
        $company,$site,$detection['id_retention_detection'],$demo['direccion'],'placeholder',(int)$detection['version']
    ], $barrierAction);
    check('dos revisiones concurrentes arrancan', count($actionResults) === 2);
    check('solo una revisión optimista gana', count(array_filter($actionResults, fn($r)=>$r['exit']===0 && !empty($r['data']['success']))) === 1);
    check('solo se registra una acción humana', (int)$db->query(
        "SELECT COUNT(*) FROM retention_action WHERE id_empresa={$company} AND action='REVIEW'"
    )->fetchColumn() === 1);
    check('detección incrementa versión exactamente una vez', (int)$db->query(
        "SELECT version FROM retention_detection WHERE id_retention_detection=".(int)$detection['id_retention_detection']
    )->fetchColumn() === 2);

    $service = new RetentionService($db,$company);
    $service->run('2026-08-21');
    check('cooldown evita nueva detección al día siguiente', (int)$db->query(
        "SELECT COUNT(*) FROM retention_detection WHERE id_empresa={$company} AND id_socio={$member}"
    )->fetchColumn() === 1);
    check('workers no revelan errores internos', count(array_filter(array_merge($eventResults,$jobResults,$actionResults),
        fn($r)=>preg_match('/Fatal error|Warning|Notice|Uncaught/i',$r['stderr']))) === 0);
} catch (Throwable $error) {
    check('escenario concurrente Retention', false);
    fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");
} finally {
    foreach ($barriers as $barrier) @unlink($barrier);
    if ($demo !== null) DemoGymFactory::cleanup($db);
}
finishTests();
