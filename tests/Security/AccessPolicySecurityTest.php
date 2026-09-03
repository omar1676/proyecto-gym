<?php

require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/Support/AccessControlTestFactory.php';
require_once dirname(__DIR__,2).'/app/services/AccessPolicyService.php';
require_once dirname(__DIR__,2).'/app/helpers/Authorization.php';

function f26Denied(callable $operation): bool { try{$operation();return false;}catch(DomainException|InvalidArgumentException|PDOException){return true;} }

$db=Database::getInstance()->getConnection();
AccessControlTestFactory::cleanup($db);
$a=AccessControlTestFactory::createTenant($db,'f26seca');
$b=AccessControlTestFactory::createTenant($db,'f26secb');
$a2=AccessControlTestFactory::createSite($db,$a['empresa'],'f26seca2');
$memberA2=AccessControlTestFactory::createMember($db,$a['empresa'],$a2,'f26seca2member');
$now=new DateTimeImmutable('2026-08-25 10:00:00',new DateTimeZone('UTC'));
$clock=static fn():DateTimeImmutable=>$now;
$blocked=static fn(int $id):array=>['estado'=>'BLOQUEADO','reason_code'=>'NO_ACTIVE_MEMBERSHIP'];

try {
    $serviceA=new AccessPolicyService($db,$a['empresa'],$a['sede'],$a['actor'],'direccion',$clock,$blocked);
    check('IDOR empresa A no lee socio B',$serviceA->canAccess($b['member'])['reason_code']==='MEMBER_NOT_FOUND_OR_OUT_OF_SCOPE');
    $memberB=$db->query('SELECT id_usuario,id_empresa,id_gimnasio,activo,rol FROM usuario WHERE id_usuario='.(int)$b['member'])->fetch(PDO::FETCH_ASSOC);
    $batchHostile=$serviceA->evaluateBatch([$memberB],[$b['member']=>['estado'=>'PERMITIDO','reason_code'=>'HOSTILE_BASE']]);
    check('batch directo no confía en una fila ni elegibilidad de otro tenant',($batchHostile[$b['member']]['reason_code']??'')==='MEMBER_NOT_FOUND_OR_OUT_OF_SCOPE');
    check('A no escribe política para socio B',f26Denied(fn()=> $serviceA->grantTemporary($b['member'],$now,$now->modify('+1 day'),'TEMPORARY_VISIT',null,str_repeat('4',32),0)));
    check('sede A1 no escribe socio A2',f26Denied(fn()=> $serviceA->grantTemporary($memberA2,$now,$now->modify('+1 day'),'TEMPORARY_VISIT',null,str_repeat('5',32),0)));

    $reception=AccessControlTestFactory::createActor($db,$a['empresa'],$a['sede'],'recepcion','f26secrecep');
    $receptionService=new AccessPolicyService($db,$a['empresa'],$a['sede'],$reception,'recepcion',$clock,$blocked,3);
    check('recepción tiene permiso temporal limitado',Authorization::can('recepcion','access.temporary')&&!Authorization::can('recepcion','access.permanent'));
    check('recepción no suspende',f26Denied(fn()=> $receptionService->suspend($a['member'],null,'POLICY_REVIEW',null,str_repeat('6',32),0)));
    check('recepción no deniega',f26Denied(fn()=> $receptionService->deny($a['member'],'POLICY_DENIED',null,str_repeat('7',32),0)));
    check('recepción no bloquea permanentemente',f26Denied(fn()=> $receptionService->blockPermanently($a['member'],'SAFETY_BLOCK',null,str_repeat('8',32),0)));

    check('reason_code SQLi se rechaza',f26Denied(fn()=> $serviceA->grantTemporary($a['member'],$now,$now->modify('+1 day'),"TEMPORARY_VISIT' OR 1=1",null,str_repeat('9',32),0)));
    check('idempotency hostil se rechaza',f26Denied(fn()=> $serviceA->grantTemporary($a['member'],$now,$now->modify('+1 day'),'TEMPORARY_VISIT',null,"x\nSQL",0)));
    $created=$serviceA->grantTemporary($a['member'],$now,$now->modify('+1 day'),'TEMPORARY_VISIT','<script>alert(1)</script>',str_repeat('a',64),0);
    $stored=$db->query('SELECT reason_note FROM access_policy WHERE id_access_policy='.(int)$created['policy']['id_access_policy'])->fetchColumn();
    check('nota se persiste como dato sin transformación destructiva',$stored==='<script>alert(1)</script>');
    $view=file_get_contents(dirname(__DIR__,2).'/app/views/admin/socios.php');
    check('vista escapa etiquetas y datos de acceso',
        str_contains($view,'htmlspecialchars(AccessPolicyPresentation::state')
        &&str_contains($view,'htmlspecialchars(AccessPolicyPresentation::reason')
        &&str_contains($view,'htmlspecialchars(AccessPolicyPresentation::action'));
    $router=file_get_contents(dirname(__DIR__,2).'/public/index.php');
    $controller=file_get_contents(dirname(__DIR__,2).'/app/controllers/AccessPolicyController.php');
    check('mutación usa POST y CSRF',str_contains($router,"'admin_access_change'")&&str_contains($controller,"REQUEST_METHOD'] !== 'POST'")&&str_contains($controller,'Csrf::validarPost()'));
    check('request no acepta empresa ni sede del navegador',!str_contains($controller,"POST['id_empresa'")&&!str_contains($controller,"POST['id_gimnasio'"));
    $biometric=(int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name LIKE 'access_policy%' AND LOWER(column_name) REGEXP 'huella|biometr|finger|template|minutiae'")->fetchColumn();
    check('política no almacena biometría',$biometric===0);
    $db->prepare("UPDATE empresa SET estado='inactiva' WHERE id_empresa=:company")->execute([':company'=>$a['empresa']]);
    check('tenant inactivo se deniega en tiempo real',$serviceA->canAccess($a['member'])['reason_code']==='TENANT_NOT_OPERATIONAL');
    check('tenant inactivo no recibe escrituras',f26Denied(fn()=> $serviceA->deny($a['member'],'POLICY_DENIED',null,str_repeat('b',64),(int)$created['policy']['version'])));
} finally { AccessControlTestFactory::cleanup($db); }
finishTests();
