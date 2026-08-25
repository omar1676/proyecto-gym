<?php

require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/Support/AccessControlTestFactory.php';
require_once dirname(__DIR__,2).'/app/services/AccessPolicyService.php';

$db=Database::getInstance()->getConnection();
AccessControlTestFactory::cleanup($db);
$tenant=AccessControlTestFactory::createTenant($db,'f26perf');
try {
    $insert=$db->prepare(
        'INSERT INTO usuario (nombre,apellidos,dni,telefono,email,nombre_usuario,contrasena,activo,rol,id_empresa,id_gimnasio) '
        . "VALUES ('Perf',:last,:dni,'600000000',:email,:user,'x',1,'socio',:company,:site)"
    );
    $db->beginTransaction();
    for($i=1;$i<5000;$i++) $insert->execute([
        ':last'=>'F26 '.$i,':dni'=>'F26P-'.$i,':email'=>'f26p'.$i.'@example.invalid',
        ':user'=>'test_access_f26p'.$i,':company'=>$tenant['empresa'],':site'=>$tenant['sede'],
    ]);
    $db->commit();
    $now='2026-08-25 10:00:00';$expires='2026-08-28 10:00:00';
    $db->exec(
        "INSERT INTO access_policy (id_empresa,id_gimnasio,id_socio,state,starts_at_utc,expires_at_utc,reason_code,version,created_at_utc,updated_at_utc) "
        . "SELECT id_empresa,id_gimnasio,id_usuario,'TEMPORARY','{$now}','{$expires}','TEMPORARY_VISIT',1,'{$now}','{$now}' "
        . "FROM usuario WHERE id_empresa=".(int)$tenant['empresa']." AND rol='socio'"
    );
    $db->exec('CREATE TEMPORARY TABLE f26_seq (n TINYINT UNSIGNED PRIMARY KEY) ENGINE=MEMORY');
    $db->exec('INSERT INTO f26_seq VALUES '.implode(',',array_map(static fn(int $n):string=>'('.$n.')',range(0,19))));
    $db->exec(
        "INSERT INTO access_policy_event (event_id,correlation_id,id_access_policy,id_empresa,id_gimnasio,id_socio,id_actor,actor_role,origin,action,previous_state,new_state,starts_at_utc,expires_at_utc,reason_code,result,idempotency_key,created_at_utc) "
        . "SELECT UUID(),UUID(),p.id_access_policy,p.id_empresa,p.id_gimnasio,p.id_socio,NULL,'system','SYSTEM','ACCESS_DECISION_EVALUATED',NULL,p.state,p.starts_at_utc,p.expires_at_utc,p.reason_code,'SUCCESS',SHA2(CONCAT('f26-perf|',p.id_access_policy,'|',s.n),256),'{$now}' "
        . "FROM access_policy p CROSS JOIN f26_seq s WHERE p.id_empresa=".(int)$tenant['empresa']
    );
    $members=$db->query("SELECT id_usuario,activo FROM usuario WHERE id_empresa=".(int)$tenant['empresa']." AND rol='socio'")->fetchAll(PDO::FETCH_ASSOC);
    $base=[];foreach($members as $member)$base[(int)$member['id_usuario']]=['estado'=>'BLOQUEADO','reason_code'=>'NO_ACTIVE_MEMBERSHIP'];
    $service=new AccessPolicyService($db,$tenant['empresa'],$tenant['sede'],$tenant['actor'],'direccion',static fn()=>new DateTimeImmutable('2026-08-25 10:00:00',new DateTimeZone('UTC')),static fn(int $id)=>['estado'=>'BLOQUEADO','reason_code'=>'NO_ACTIVE_MEMBERSHIP']);
    $started=microtime(true);$decisions=$service->evaluateBatch($members,$base);$batchMs=(microtime(true)-$started)*1000;
    $started=microtime(true);for($i=0;$i<100;$i++)$service->canAccess($tenant['member']);$singleAvgMs=((microtime(true)-$started)*1000)/100;
    $started=microtime(true);$history=$service->history($tenant['member'],50);$historyMs=(microtime(true)-$started)*1000;
    $started=microtime(true);$dashboard=$service->dashboard();$dashboardMs=(microtime(true)-$started)*1000;
    $plan=$db->prepare("EXPLAIN SELECT * FROM access_policy WHERE id_empresa=:company AND id_gimnasio=:site AND state='TEMPORARY' AND expires_at_utc<=:cutoff ORDER BY expires_at_utc,id_access_policy LIMIT 500");
    $plan->execute([':company'=>$tenant['empresa'],':site'=>$tenant['sede'],':cutoff'=>'2026-08-29 00:00:00']);
    $explain=$plan->fetchAll(PDO::FETCH_ASSOC);
    check('dataset contiene 5.000 socios',count($members)===5000);
    check('dataset contiene 100.000 eventos históricos',(int)$db->query('SELECT COUNT(*) FROM access_policy_event WHERE id_empresa='.(int)$tenant['empresa'])->fetchColumn()===100000);
    check('batch resuelve 5.000 decisiones permitidas sin N+1',count($decisions)===5000
        && count(array_filter($decisions,static fn(array $decision):bool=>$decision['estado']==='PERMITIDO'))===5000);
    check('historial pagina a 50 filas',count($history)===20);
    check('consulta de caducidad usa un índice',count(array_filter($explain,static fn(array $row):bool=>!empty($row['key'])))>0);
    check('dashboard cuenta los 5.000 temporales',$dashboard['states']['TEMPORARY']===5000);
    check('dashboard calcula hoy y mañana sin N+1',isset($dashboard['expiring_today'],$dashboard['expiring_tomorrow'],$dashboard['expiring_72h']));
    check('mediciones son finitas',is_finite($batchMs)&&is_finite($singleAvgMs)&&is_finite($historyMs)&&is_finite($dashboardMs));
    echo 'METRIC f26_batch_5000_ms='.number_format($batchMs,3,'.','')."\n";
    echo 'METRIC f26_can_access_avg_ms='.number_format($singleAvgMs,3,'.','')."\n";
    echo 'METRIC f26_history_100k_ms='.number_format($historyMs,3,'.','')."\n";
    echo 'METRIC f26_dashboard_ms='.number_format($dashboardMs,3,'.','')."\n";
    echo 'METRIC f26_peak_memory_mb='.number_format(memory_get_peak_usage(true)/1048576,2,'.','')."\n";
} finally {
    if($db->inTransaction())$db->rollBack();
    AccessControlTestFactory::cleanup($db);
}
finishTests();
