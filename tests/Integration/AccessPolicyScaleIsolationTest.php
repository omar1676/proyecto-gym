<?php

require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/Support/AccessControlTestFactory.php';
require_once dirname(__DIR__,2).'/app/services/AccessPolicyService.php';

$db=Database::getInstance()->getConnection();
AccessControlTestFactory::cleanup($db);
$now=new DateTimeImmutable('2026-08-25 10:00:00',new DateTimeZone('UTC'));
$clock=static fn():DateTimeImmutable=>$now;
$blocked=static fn(int $id):array=>['estado'=>'BLOQUEADO','reason_code'=>'NO_ACTIVE_MEMBERSHIP'];
$tenants=[];$started=microtime(true);
try {
    for($i=0;$i<100;$i++){
        $tenant=AccessControlTestFactory::createTenant($db,'f26scale'.$i);
        $service=new AccessPolicyService($db,$tenant['empresa'],$tenant['sede'],$tenant['actor'],'direccion',$clock,$blocked);
        $service->grantTemporary($tenant['member'],$now,$now->modify('+1 day'),'TEMPORARY_VISIT',null,hash('sha256','f26-scale-'.$i),0);
        $tenants[]=$tenant;
    }
    $seconds=microtime(true)-$started;
    $ids=implode(',',array_map(static fn(array $t):int=>$t['empresa'],$tenants));
    check('100 tenants se aprovisionan con política propia',count($tenants)===100);
    check('100 tenants conservan 100 políticas aisladas',(int)$db->query("SELECT COUNT(*) FROM access_policy WHERE id_empresa IN ({$ids})")->fetchColumn()===100);
    $first=$tenants[0];$last=$tenants[99];
    $firstService=new AccessPolicyService($db,$first['empresa'],$first['sede'],$first['actor'],'direccion',$clock,$blocked);
    check('tenant 1 no resuelve miembro del tenant 100',$firstService->canAccess($last['member'])['reason_code']==='MEMBER_NOT_FOUND_OR_OUT_OF_SCOPE');
    check('dashboard tenant 1 solo cuenta su política',$firstService->dashboard()['states']['TEMPORARY']===1);
    check('escala sintética termina sin error',is_finite($seconds));
    echo 'METRIC f26_100_tenants_seconds='.number_format($seconds,3,'.','')."\n";
} finally { AccessControlTestFactory::cleanup($db); }
finishTests();
