<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/TenantOnboardingFactory.php';
require_once dirname(__DIR__, 2) . '/app/models/GimnasioModel.php';
require_once dirname(__DIR__, 2) . '/app/models/UserModel.php';
require_once dirname(__DIR__, 2) . '/app/models/MembresiaModel.php';
require_once dirname(__DIR__, 2) . '/app/models/ProductoModel.php';

$db = Database::getInstance()->getConnection();
$input = TenantOnboardingFactory::input('Atlas Exact', [
    'company_name' => 'TEST F22 Gimnasio Atlas Test SL',
    'commercial_name' => 'Gimnasio Atlas Test',
    'site_name' => 'Atlas Centro',
    'owner_name' => 'Alicia',
    'owner_surname' => 'Atlas Dirección',
    'categories' => ['Bebidas Atlas', 'Material Atlas'],
    'membership_types' => [[
        'name'=>'Atlas Mensual', 'description'=>'Sintética', 'price'=>'42.50',
        'duration_months'=>1, 'vat'=>'21.00',
    ]],
]);
$service = new TenantProvisioningService($db, 6);
$atlas = $service->provision($input);
$service->activate((int) $atlas['company_id']);
$company = (int) $atlas['company_id'];
$center = (int) $atlas['site_id'];

$gyms = new GimnasioModel($company);
$north = $gyms->crear([
    'nombre'=>'Atlas Norte', 'razon_social'=>'Gimnasio Atlas Test SL', 'cif'=>'B22000001',
    'direccion'=>'Calle Sintética 22', 'telefono'=>'600220022', 'email'=>'atlas.norte@test.invalid',
]);
check('Atlas crea segunda sede con modelo productivo', is_int($north) && $north > 0);

$users = new UserModel($center, $company);
$admin = $users->crearEmpleado('Adrián','Atlas Admin','F22ATLASADM','atlas.admin@test.invalid','600220023','atlas.admin','Synthetic-Admin-22!','admin',$center);
$reception = $users->crearEmpleado('Raquel','Atlas Recepción','F22ATLASREC','atlas.recepcion@test.invalid','600220024','atlas.recepcion','Synthetic-Reception-22!','recepcion',$center);
$memberCreated = $users->crear('Sara','Atlas Socia','F22ATLASSOC','600220025','atlas.socia@test.invalid','atlas.socia','Synthetic-Member-22!');
$member = (int) $db->query("SELECT id_usuario FROM usuario WHERE id_empresa={$company} AND nombre_usuario='atlas.socia'")->fetchColumn();
check('Atlas crea admin recepción y socio con modelos productivos', $admin && $reception && $memberCreated && $member > 0);

$membership = new MembresiaModel($center, $company);
$type = (int) $db->query("SELECT id_tipo_membresia FROM tipo_membresia WHERE id_empresa={$company} AND nombre='Atlas Mensual'")->fetchColumn();
$error = '';
$contract = $membership->contratar($member, $type, 'efectivo', $error, null, 'mostrador', 'f22-atlas-contract-00000001', (int) $reception);
check('Atlas contrata su tarifa propia', is_int($contract) && $contract > 0);

$products = new ProductoModel($center, $company);
$category = (int) $db->query("SELECT id_categoria FROM categoria_producto WHERE id_empresa={$company} AND nombre_categoria='Bebidas Atlas'")->fetchColumn();
check('Atlas crea producto en su catálogo independiente', $products->crear('Agua Atlas', 'Sintético', '1.50', 10, 2, 'activo', $category, 10.0));

$gymAuth = (new GimnasioModel())->autenticar($input['site_access_email'], $atlas['site_temporary_password']);
$owner = (new UserModel(null, $company))->buscarPorUsuario($input['owner_username']);
check('credencial técnica Atlas inicia el primer nivel', (int) ($gymAuth['id_empresa'] ?? 0) === $company);
check('dirección Atlas inicia segundo nivel con su clave temporal', is_array($owner) && password_verify($atlas['owner_temporary_password'], $owner['contrasena']));
check('Atlas tiene exactamente sus dos sedes', count($gyms->listar()) === 2);
check('Atlas no contiene nombres Cleto ni Villaviciosa', (int) $db->query(
    "SELECT COUNT(*) FROM empresa e JOIN gimnasio g ON g.id_empresa=e.id_empresa WHERE e.id_empresa={$company} AND (CONCAT_WS(' ',e.nombre,e.nombre_comercial,g.nombre) LIKE '%Cleto%' OR CONCAT_WS(' ',e.nombre,e.nombre_comercial,g.nombre) LIKE '%Villaviciosa%')"
)->fetchColumn() === 0);
check('Atlas queda activo sin DORLET ni email funcional', (string) $db->query(
    "SELECT CONCAT(onboarding_state,':',JSON_UNQUOTE(JSON_EXTRACT(configuracion,'$.access_control.mode')),':',JSON_UNQUOTE(JSON_EXTRACT(configuracion,'$.email.enabled'))) FROM empresa WHERE id_empresa={$company}"
)->fetchColumn() === 'ACTIVE:disabled:false');

finishTests();
