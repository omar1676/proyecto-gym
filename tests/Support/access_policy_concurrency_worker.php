<?php

require_once dirname(__DIR__,2).'/pruebas/_arranque.php';
require_once dirname(__DIR__,2).'/app/services/AccessPolicyService.php';

[$script,$barrier,$mode,$company,$site,$member,$actor,$role,$version,$key]=$argv+array_fill(0,10,'');
for($i=0;$i<200&&!is_file($barrier);$i++) usleep(10000);
$db=Database::getInstance()->getConnection();
$now=new DateTimeImmutable('2026-08-25 10:00:00',new DateTimeZone('UTC'));
$clock=static fn():DateTimeImmutable=>$now;
$blocked=static fn(int $id):array=>['estado'=>'BLOQUEADO','reason_code'=>'NO_ACTIVE_MEMBERSHIP'];
$service=new AccessPolicyService($db,(int)$company,(int)$site,$actor!==''?(int)$actor:null,$role,$clock,$blocked,3);
try {
    if($mode==='extend') $result=$service->extendTemporary((int)$member,$now->modify('+2 days'),'MANUAL_EXCEPTION',null,$key,(int)$version);
    elseif($mode==='temporary') $result=$service->grantTemporary((int)$member,$now,$now->modify('+1 day'),'TEMPORARY_VISIT',null,$key,(int)$version);
    elseif($mode==='permanent') $result=$service->blockPermanently((int)$member,'SAFETY_BLOCK',null,$key,(int)$version);
    elseif($mode==='expire') $result=$service->expireDue();
    else throw new RuntimeException('mode');
    echo json_encode(['ok'=>true,'result'=>$result],JSON_UNESCAPED_SLASHES);
    exit(0);
} catch(Throwable $error) {
    echo json_encode(['ok'=>false,'class'=>get_class($error)],JSON_UNESCAPED_SLASHES);
    exit(1);
}
