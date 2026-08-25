<?php

require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/Support/AccessControlTestFactory.php';
require_once dirname(__DIR__,2).'/app/services/AccessPolicyService.php';

function f26Race(array $jobs,string $barrier): array {
    $worker=dirname(__DIR__).'/Support/access_policy_concurrency_worker.php';
    $running=[];
    foreach($jobs as $job){
        $command=array_merge([PHP_BINARY,$worker,$barrier],array_map('strval',$job));
        $spec=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $process=proc_open($command,$spec,$pipes,dirname(__DIR__,2),null,['bypass_shell'=>true]);
        if(is_resource($process)){fclose($pipes[0]);$running[]=[$process,$pipes];}
    }
    touch($barrier);$results=[];
    foreach($running as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$results[]=['exit'=>proc_close($process),'data'=>json_decode($out,true),'stderr'=>$err];}
    return $results;
}

$db=Database::getInstance()->getConnection();
AccessControlTestFactory::cleanup($db);
$tenant=AccessControlTestFactory::createTenant($db,'f26race');
$admin=AccessControlTestFactory::createActor($db,$tenant['empresa'],$tenant['sede'],'admin','f26raceadmin');
$reception=AccessControlTestFactory::createActor($db,$tenant['empresa'],$tenant['sede'],'recepcion','f26racerecep');
$now=new DateTimeImmutable('2026-08-25 10:00:00',new DateTimeZone('UTC'));
$service=new AccessPolicyService($db,$tenant['empresa'],$tenant['sede'],$tenant['actor'],'direccion',static fn()=>$now,static fn(int $id)=>['estado'=>'BLOQUEADO','reason_code'=>'NO_ACTIVE_MEMBERSHIP']);
$barriers=[];
try {
    $initial=$service->grantTemporary($tenant['member'],$now,$now->modify('+1 day'),'TEMPORARY_VISIT',null,str_repeat('b',64),0);
    $v=(int)$initial['policy']['version'];
    $barriers[]=$barrier=sys_get_temp_dir().'/gimnera-f26-extend-'.bin2hex(random_bytes(8));
    $race=f26Race([
        ['extend',$tenant['empresa'],$tenant['sede'],$tenant['member'],$admin,'admin',$v,str_repeat('c',64)],
        ['extend',$tenant['empresa'],$tenant['sede'],$tenant['member'],$admin,'admin',$v,str_repeat('d',64)],
    ],$barrier);
    check('dos procesos de ampliación arrancan',count($race)===2);
    check('optimistic lock deja un solo ganador',count(array_filter($race,fn($r)=>$r['exit']===0&&($r['data']['ok']??false)))===1);
    check('versión aumenta exactamente una vez',(int)$service->current($tenant['member'])['version']===$v+1);

    $v=(int)$service->current($tenant['member'])['version'];
    $barriers[]=$barrier=sys_get_temp_dir().'/gimnera-f26-block-'.bin2hex(random_bytes(8));
    $race=f26Race([
        ['temporary',$tenant['empresa'],$tenant['sede'],$tenant['member'],$reception,'recepcion',$v,str_repeat('e',64)],
        ['permanent',$tenant['empresa'],$tenant['sede'],$tenant['member'],$tenant['actor'],'direccion',$v,str_repeat('f',64)],
    ],$barrier);
    check('concesión y bloqueo permanente terminan explícitamente',count($race)===2&&count(array_filter($race,fn($r)=>in_array($r['exit'],[0,1],true)))===2);
    check('bloqueo permanente gana la carrera',$service->current($tenant['member'])['state']==='PERMANENT_BLOCK');

    $blocked=$service->current($tenant['member']);
    $restored=$service->restore($tenant['member'],'MANUAL_RESTORE',null,str_repeat('1',64),(int)$blocked['version']);
    $temp=$service->grantTemporary($tenant['member'],$now,$now->modify('+1 hour'),'TEMPORARY_VISIT',null,str_repeat('2',64),(int)$restored['policy']['version']);
    $db->prepare("UPDATE access_policy SET starts_at_utc='2026-08-25 09:00:00',expires_at_utc='2026-08-25 09:59:59' WHERE id_access_policy=:id")->execute([':id'=>$temp['policy']['id_access_policy']]);
    $v=(int)$temp['policy']['version'];
    $barriers[]=$barrier=sys_get_temp_dir().'/gimnera-f26-expire-'.bin2hex(random_bytes(8));
    $race=f26Race([
        ['extend',$tenant['empresa'],$tenant['sede'],$tenant['member'],$admin,'admin',$v,str_repeat('3',64)],
        ['expire',$tenant['empresa'],$tenant['sede'],$tenant['member'],'','system',$v,str_repeat('4',64)],
    ],$barrier);
    $extensionWon=count(array_filter($race,fn($r)=>$r['exit']===0&&($r['data']['ok']??false)&&isset($r['data']['result']['policy'])))===1;
    $final=$service->current($tenant['member']);
    check('expire y ampliación no dejan estado imposible',in_array($final['state'],['TEMPORARY','DENIED'],true));
    check('si ampliación confirma, conserva caducidad futura',!$extensionWon||$final['state']==='TEMPORARY'&&$final['expires_at_utc']==='2026-08-27 10:00:00');
} finally {
    foreach($barriers as $barrier) @unlink($barrier);
    AccessControlTestFactory::cleanup($db);
}
finishTests();
