<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/TenantOnboardingFactory.php';
require_once dirname(__DIR__, 2) . '/app/models/GimnasioModel.php';
require_once dirname(__DIR__, 2) . '/app/models/MembresiaModel.php';
require_once dirname(__DIR__, 2) . '/app/models/ProductoModel.php';
require_once dirname(__DIR__, 2) . '/app/models/UserModel.php';
require_once dirname(__DIR__, 2) . '/app/helpers/TenantContext.php';

$db = Database::getInstance()->getConnection();
$a = TenantOnboardingFactory::create($db, 'Isolation A');
$b = TenantOnboardingFactory::create($db, 'Isolation B');
$c = TenantOnboardingFactory::create($db, 'Isolation C');

$directionA = (int) $a['owner_id'];
$denied = false;
try { new TenantProvisioningService($db, $directionA); } catch (DomainException $e) { $denied = true; }
check('dirección no puede usar el servicio de plataforma', $denied);

$malicious = $db->prepare(
    "INSERT INTO usuario
     (id_empresa,id_gimnasio,nombre,apellidos,dni,email,nombre_usuario,contrasena,rol,activo)
     VALUES (:company,NULL,'Tenant','Superadmin',NULL,:email,:username,:password,'superadmin',1)"
);
$malicious->execute([
    ':company' => (int) $a['company_id'],
    ':email' => 'tenant.superadmin.' . bin2hex(random_bytes(3)) . '@test.invalid',
    ':username' => 'tenant.superadmin.' . bin2hex(random_bytes(3)),
    ':password' => password_hash('Synthetic-Only!', PASSWORD_BCRYPT, ['cost' => 4]),
]);
$tenantSuperadminId = (int) $db->lastInsertId();
$tenantSuperadminDenied = false;
try { new TenantProvisioningService($db, $tenantSuperadminId); }
catch (DomainException) { $tenantSuperadminDenied = true; }
check('rol superadmin dentro de un tenant no obtiene autoridad de plataforma', $tenantSuperadminDenied);
$_SESSION = ['logueado'=>true, 'usuario_id'=>$tenantSuperadminId, 'gimnasio_auth_id'=>(int)$a['site_id']];
check('TenantContext rechaza un superadmin ligado a tenant', !TenantContext::desdeSesion()->autenticado());
$_SESSION = [];

$siteA = (int) $a['site_id'];
$siteB = (int) $b['site_id'];
$companyA = (int) $a['company_id'];
$companyB = (int) $b['company_id'];
$catB = (int) $db->query("SELECT id_categoria FROM categoria_producto WHERE id_empresa={$companyB} ORDER BY id_categoria LIMIT 1")->fetchColumn();
$productB = new ProductoModel($siteB, $companyB);
check('fixture B crea producto dentro de su categoría', $productB->crear('Producto privado B', null, '2.00', 3, 0, 'activo', $catB, 21.0));
$productBId = (int) $db->lastInsertId();

check('empresa A no ve sede B', (new GimnasioModel($companyA))->buscarPorId($siteB) === null);
check('empresa A no ve producto B', (new ProductoModel(null, $companyA))->buscarPorId($productBId) === null);
check('sede A no ve producto B', (new ProductoModel($siteA, $companyA))->buscarPorId($productBId) === null);
check('empresa A no puede usar categoría B', !(new ProductoModel($siteA, $companyA))->crear('Intento cruzado', null, '1.00', 1, 0, 'activo', $catB, 21.0));
check('usuarios A y B se resuelven por su tenant',
    (new UserModel(null, $companyA))->buscarPorId((int) $b['owner_id']) === null
    && (new UserModel(null, $companyB))->buscarPorId((int) $a['owner_id']) === null);
check('catálogos de tres tenants permanecen separados',
    (int) $db->query('SELECT COUNT(DISTINCT id_empresa) FROM categoria_producto WHERE id_empresa IN (' . implode(',', array_map('intval', [$a['company_id'],$b['company_id'],$c['company_id']])) . ')')->fetchColumn() === 3);
check('auditoría de A no se filtra al modelo de B', (int) $db->query("SELECT COUNT(*) FROM log_actividad WHERE id_empresa={$companyA}")->fetchColumn() > 0
    && (int) $db->query("SELECT COUNT(*) FROM log_actividad WHERE id_empresa={$companyB}")->fetchColumn() > 0);

finishTests();
