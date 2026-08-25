<?php

require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/Support/AccessControlTestFactory.php';
require_once dirname(__DIR__,2).'/app/services/AccessPolicyService.php';

function f26Rejected(callable $operation): bool { try{$operation();return false;}catch(DomainException|InvalidArgumentException){return true;} }

$db=Database::getInstance()->getConnection();
AccessControlTestFactory::cleanup($db);
$tenant=AccessControlTestFactory::createTenant($db,'f26policy');
$now=new DateTimeImmutable('2026-08-25 10:00:00',new DateTimeZone('UTC'));
$allowedMembers=[];
$clock=static function()use(&$now):DateTimeImmutable{return $now;};
$eligibility=static function(int $member)use(&$allowedMembers):array{return in_array($member,$allowedMembers,true)
    ?['estado'=>'PERMITIDO','reason_code'=>'MEMBERSHIP_ACTIVE','motivo'=>'Membresía vigente']
    :['estado'=>'BLOQUEADO','reason_code'=>'NO_ACTIVE_MEMBERSHIP','motivo'=>'Sin membresía'];};
$service=new AccessPolicyService($db,$tenant['empresa'],$tenant['sede'],$tenant['actor'],'direccion',$clock,$eligibility,3);

try {
    $start=$now;
    $end=$now->modify('+3 days');
    $created=$service->grantTemporary($tenant['member'],$start,$end,'TEMPORARY_VISIT','Visita sintética',str_repeat('a',32),0);
    check('temporal tres días se crea con versión 1',!$created['duplicate']&&(int)$created['policy']['version']===1);
    $initialDecision=$service->canAccess($tenant['member']);
    check('temporal concede antes del límite',$initialDecision['estado']==='PERMITIDO');
    check('política y provider se informan por separado',$initialDecision['policy_state']==='TEMPORARY'
        && $initialDecision['provider_mode']==='disabled' && $initialDecision['provider_sync_state']==='DISABLED');
    $now=$end->modify('-1 second');
    check('temporal sigue válido un segundo antes',$service->canAccess($tenant['member'])['estado']==='PERMITIDO');
    $now=$end;
    $atBoundary=$service->canAccess($tenant['member']);
    check('temporal caduca exactamente en el instante límite',$atBoundary['estado']==='BLOQUEADO'&&$atBoundary['reason_code']==='TEMPORARY_EXPIRED');

    $forgotten=AccessControlTestFactory::createMember($db,$tenant['empresa'],$tenant['sede'],'f26forgotten');
    $now=$start;
    $service->grantTemporary($forgotten,$start,$start->modify('+3 days'),'TEMPORARY_VISIT',null,str_repeat('0',32),0);
    $now=$start->modify('+90 days');
    check('prueba olvidada tres meses nunca queda abierta',$service->canAccess($forgotten)['estado']==='BLOQUEADO'&&$service->canAccess($forgotten)['reason_code']==='TEMPORARY_EXPIRED');
    $now=$end;

    $allowedMembers[]=$tenant['member'];
    check('conversión a membresía evita corte tras caducidad',$service->canAccess($tenant['member'])['estado']==='PERMITIDO'&&$service->canAccess($tenant['member'])['reason_code']==='MEMBERSHIP_CONVERTED');
    $job=$service->expireDue();
    check('job convierte temporal caducado con membresía',($job['converted']??0)===1&&$service->current($tenant['member'])['state']==='ALLOWED');
    check('repetir job no duplica efecto',$service->expireDue()['converted']===0);

    $version=(int)$service->current($tenant['member'])['version'];
    $blocked=$service->blockPermanently($tenant['member'],'SAFETY_BLOCK','Caso sintético',str_repeat('b',32),$version);
    check('bloqueo permanente domina',($blocked['policy']['state']??'')==='PERMANENT_BLOCK'&&$service->canAccess($tenant['member'])['estado']==='BLOQUEADO');
    $providerReason=(string)$db->query('SELECT reason_code FROM access_control_audit WHERE id_empresa='.(int)$tenant['empresa'].' AND id_socio='.(int)$tenant['member'].' ORDER BY id_audit DESC LIMIT 1')->fetchColumn();
    check('frontera física no recibe motivo interno sensible',$providerReason==='ACCESS_POLICY_DENIED');
    $reception=AccessControlTestFactory::createActor($db,$tenant['empresa'],$tenant['sede'],'recepcion','f26recep');
    $receptionService=new AccessPolicyService($db,$tenant['empresa'],$tenant['sede'],$reception,'recepcion',$clock,$eligibility,3);
    check('recepción no sobreescribe bloqueo permanente',f26Rejected(fn()=> $receptionService->grantTemporary($tenant['member'],$now,$now->modify('+1 day'),'TEMPORARY_VISIT',null,str_repeat('c',32),(int)$blocked['policy']['version'])));
    check('recepción no puede conceder más de tres días',f26Rejected(fn()=> $receptionService->grantTemporary($tenant['member'],$now,$now->modify('+4 days'),'TEMPORARY_VISIT',null,str_repeat('d',32),(int)$blocked['policy']['version'])));

    $restored=$service->restore($tenant['member'],'MANUAL_RESTORE',null,str_repeat('e',32),(int)$blocked['policy']['version']);
    check('dirección restaura explícitamente bloqueo permanente',$restored['policy']['state']==='ALLOWED');
    $suspended=$service->suspend($tenant['member'],$now->modify('+1 hour'),'INCIDENT_REVIEW',null,str_repeat('f',32),(int)$restored['policy']['version']);
    check('nueva concesión no atraviesa suspensión',f26Rejected(fn()=> $service->grantTemporary($tenant['member'],$now,$now->modify('+1 day'),'MANUAL_EXCEPTION',null,str_repeat('6',64),(int)$suspended['policy']['version'])));
    $now=$now->modify('+2 hours');
    check('fin temporal de suspensión no restaura en silencio',$service->canAccess($tenant['member'])['reason_code']==='SUSPENSION_REVIEW_REQUIRED');

    $restored2=$service->restore($tenant['member'],'MANUAL_RESTORE',null,str_repeat('1',32),(int)$suspended['policy']['version']);
    $duplicate=$service->restore($tenant['member'],'MANUAL_RESTORE',null,str_repeat('1',32),(int)$suspended['policy']['version']);
    check('idempotency key repite resultado sin nueva versión',$duplicate['duplicate']&&(int)$service->current($tenant['member'])['version']===(int)$restored2['policy']['version']);

    $admin=AccessControlTestFactory::createActor($db,$tenant['empresa'],$tenant['sede'],'admin','f26admin');
    $adminService=new AccessPolicyService($db,$tenant['empresa'],$tenant['sede'],$admin,'admin',$clock,$eligibility,3);
    $v=(int)$adminService->current($tenant['member'])['version'];
    $ninety=$adminService->grantTemporary($tenant['member'],$now,$now->modify('+90 days'),'MANUAL_EXCEPTION',null,str_repeat('2',32),$v);
    check('admin puede conceder exactamente noventa días',$ninety['policy']['expires_at_utc']===$now->modify('+90 days')->format('Y-m-d H:i:s'));
    check('admin no puede superar noventa días',f26Rejected(fn()=> $adminService->grantTemporary($tenant['member'],$now,$now->modify('+90 days +1 second'),'MANUAL_EXCEPTION',null,str_repeat('3',32),(int)$ninety['policy']['version'])));
    $dashboard=$service->dashboard();
    check('dashboard separa caducidad de hoy y mañana',array_key_exists('expiring_today',$dashboard)&&array_key_exists('expiring_tomorrow',$dashboard));

    $madrid=new DateTimeImmutable('2026-10-25 03:30:00',new DateTimeZone('Europe/Madrid'));
    check('instante Madrid se normaliza inequívocamente a UTC',$madrid->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')==='2026-10-25 02:30:00');
    $history=$service->history($tenant['member']);
    check('historial conserva actor, acción, motivo y UTC',count($history)>=6&&!empty($history[0]['actor_role'])&&!empty($history[0]['created_at_utc']));
    check('modo físico permanece disabled',ACCESS_CONTROL_MODE==='disabled');
} finally {
    AccessControlTestFactory::cleanup($db);
}
finishTests();
