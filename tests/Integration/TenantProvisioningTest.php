<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/TenantOnboardingFactory.php';

$db = Database::getInstance()->getConnection();
$actor = 6;
$service = new TenantProvisioningService($db, $actor);
$input = TenantOnboardingFactory::input('Atlas Provisioning');
$result = $service->provision($input);

check('onboarding crea la empresa mediante el servicio oficial', $result['created'] === true && $result['company_id'] > 0);
check('onboarding crea primera sede y dirección nominal', $result['site_id'] > 0 && $result['owner_id'] > 0);
check('tenant nace preparado pero todavía inactivo', $result['state'] === 'READY_FOR_REVIEW');
check('credencial técnica temporal es fuerte y aleatoria', strlen($result['site_temporary_password']) >= 24);
check('credencial humana temporal es fuerte y diferente', strlen($result['owner_temporary_password']) >= 24
    && $result['owner_temporary_password'] !== $result['site_temporary_password']);

$companyId = (int) $result['company_id'];
$site = $db->query('SELECT * FROM gimnasio WHERE id_gimnasio=' . (int) $result['site_id'])->fetch();
$owner = $db->query('SELECT * FROM usuario WHERE id_usuario=' . (int) $result['owner_id'])->fetch();
$company = $db->query('SELECT * FROM empresa WHERE id_empresa=' . $companyId)->fetch();
check('empresa queda inactiva hasta revisión', $company['estado'] === 'inactiva' && $company['onboarding_state'] === 'READY_FOR_REVIEW');
check('owner es dirección humana sin sede', $owner['rol'] === 'direccion' && $owner['id_gimnasio'] === null && $owner['dni'] === null);
check('hash técnico verifica la clave sin guardar texto claro', password_verify($result['site_temporary_password'], $site['contrasena_acceso'])
    && $site['contrasena_acceso'] !== $result['site_temporary_password']);
check('hash humano verifica la clave sin guardar texto claro', password_verify($result['owner_temporary_password'], $owner['contrasena'])
    && $owner['contrasena'] !== $result['owner_temporary_password']);

$again = $service->provision($input);
check('reentrada devuelve el tenant existente', $again['created'] === false && $again['company_id'] === $companyId);
check('reentrada no vuelve a exponer credenciales', ($again['credentials_available'] ?? true) === false
    && !isset($again['owner_temporary_password']));
check('doble submit no duplica empresa sede ni owner',
    (int) $db->query("SELECT COUNT(*) FROM empresa WHERE onboarding_key=" . $db->quote($input['idempotency_key']))->fetchColumn() === 1
    && (int) $db->query("SELECT COUNT(*) FROM gimnasio WHERE id_empresa={$companyId}")->fetchColumn() === 1
    && (int) $db->query("SELECT COUNT(*) FROM usuario WHERE id_empresa={$companyId} AND rol='direccion'")->fetchColumn() === 1);

$activated = $service->activate($companyId);
check('activación confirma estado previo y activa una vez', $activated['activated'] === true);
$secondActivation = $service->activate($companyId);
check('activación repetida es idempotente', $secondActivation['already_active'] === true);
check('empresa activa solo después del gate', (string) $db->query("SELECT CONCAT(estado,':',onboarding_state) FROM empresa WHERE id_empresa={$companyId}")->fetchColumn() === 'activa:ACTIVE');

$actions = $db->query("SELECT accion,resultado FROM log_actividad WHERE id_empresa={$companyId}")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (['ONBOARDING_STARTED','TENANT_CREATED','SEDE_CREATED','OWNER_CREATED','ONBOARDING_COMPLETED','ONBOARDING_ACTIVATED'] as $action) {
    check("auditoría registra {$action} como éxito", ($actions[$action] ?? null) === 'exito');
}
$auditPayload = json_encode($db->query(
    "SELECT detalle,valor_anterior,valor_nuevo,metadata_json FROM log_actividad WHERE id_empresa={$companyId}"
)->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
check('auditoría no conserva credenciales temporales',
    !str_contains($auditPayload, $result['site_temporary_password'])
    && !str_contains($auditPayload, $result['owner_temporary_password']));

$faultInput = TenantOnboardingFactory::input('Atomic Failure');
$failed = false;
try {
    (new TenantProvisioningService($db, $actor, static function (string $step): void {
        if ($step === 'site') throw new RuntimeException('synthetic F22 failure');
    }))->provision($faultInput);
} catch (RuntimeException $e) {
    $failed = str_contains($e->getMessage(), 'no se guardó ningún alta parcial');
}
check('fault injection interrumpe el alta completa', $failed);
check('fallo después de sede hace rollback de empresa', (int) $db->query(
    'SELECT COUNT(*) FROM empresa WHERE nombre=' . $db->quote($faultInput['company_name'])
)->fetchColumn() === 0);
check('fallo no deja sede ni owner huérfanos',
    (int) $db->query('SELECT COUNT(*) FROM gimnasio WHERE email_acceso=' . $db->quote($faultInput['site_access_email']))->fetchColumn() === 0
    && (int) $db->query('SELECT COUNT(*) FROM usuario WHERE email=' . $db->quote($faultInput['owner_email']))->fetchColumn() === 0);
check('fallo no produce auditoría de éxito del tenant inexistente', (int) $db->query(
    "SELECT COUNT(*) FROM log_actividad WHERE detalle='Provisioning SaaS' AND accion='ONBOARDING_COMPLETED' AND id_empresa IS NULL"
)->fetchColumn() === 0);

finishTests();
