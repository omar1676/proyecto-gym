<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/TenantOnboardingFactory.php';
require_once dirname(__DIR__, 2) . '/app/models/ProductoModel.php';
require_once dirname(__DIR__, 2) . '/app/models/UserModel.php';

$db = Database::getInstance()->getConnection();
$shared = [
    'site_name' => 'Centro',
    'owner_email' => 'shared.person@test.invalid',
    'owner_username' => 'shared.person',
    'categories' => ['Bebidas'],
    'membership_types' => [[
        'name' => 'Mensual Compartida', 'description' => '', 'price' => '29.99',
        'duration_months' => 1, 'vat' => '21.00',
    ]],
];
$a = TenantOnboardingFactory::create($db, 'Config A', $shared);
$b = TenantOnboardingFactory::create($db, 'Config B', $shared);

check('mismo email humano puede existir en empresas distintas', (int) $db->query(
    "SELECT COUNT(*) FROM usuario WHERE email='shared.person@test.invalid'"
)->fetchColumn() === 2);
check('mismo username humano puede existir en empresas distintas', (int) $db->query(
    "SELECT COUNT(*) FROM usuario WHERE nombre_usuario='shared.person'"
)->fetchColumn() === 2);
check('mismo nombre de sede puede existir en empresas distintas', (int) $db->query(
    "SELECT COUNT(*) FROM gimnasio WHERE nombre='Centro' AND id_empresa IN (" . (int)$a['company_id'] . ',' . (int)$b['company_id'] . ')'
)->fetchColumn() === 2);
$usersAtSiteA = new UserModel((int) $a['site_id'], (int) $a['company_id']);
check('unicidad humana se comprueba en toda la empresa, no solo en la sede',
    $usersAtSiteA->correoExiste('shared.person@test.invalid')
    && $usersAtSiteA->usuarioExiste('shared.person'));
$usersAtSiteB = new UserModel((int) $b['site_id'], (int) $b['company_id']);
$employeeA = $usersAtSiteA->crearEmpleado(
    'Alex','Compartido','SHARED-DNI-22','shared.employee@test.invalid',null,
    'shared.employee','Synthetic-Employee-A!','admin',(int)$a['site_id']
);
$employeeB = $usersAtSiteB->crearEmpleado(
    'Alex','Compartido','SHARED-DNI-22','shared.employee@test.invalid',null,
    'shared.employee','Synthetic-Employee-B!','admin',(int)$b['site_id']
);
check('DNI email y username pueden repetirse entre empresas', $employeeA !== null && $employeeB !== null && $employeeA !== $employeeB);
check('DNI email y username no se duplican dentro de una empresa',
    $usersAtSiteA->crearEmpleado(
        'Otra','Persona','SHARED-DNI-22','shared.employee@test.invalid',null,
        'shared.employee','Synthetic-Duplicate!','admin',(int)$a['site_id']
    ) === null);
$userA = (new UserModel(null, (int) $a['company_id']))->buscarPorUsuario('shared.person');
$userB = (new UserModel(null, (int) $b['company_id']))->buscarPorUsuario('shared.person');
check('resolución de username queda acotada por empresa', (int) $userA['id_empresa'] === (int) $a['company_id']
    && (int) $userB['id_empresa'] === (int) $b['company_id'] && $userA['id_usuario'] !== $userB['id_usuario']);

$catA = (int) $db->query('SELECT id_categoria FROM categoria_producto WHERE id_empresa=' . (int) $a['company_id'] . " AND nombre_categoria='Bebidas'")->fetchColumn();
$catB = (int) $db->query('SELECT id_categoria FROM categoria_producto WHERE id_empresa=' . (int) $b['company_id'] . " AND nombre_categoria='Bebidas'")->fetchColumn();
check('Bebidas existe de forma independiente en A y B', $catA > 0 && $catB > 0 && $catA !== $catB);
$productsA = new ProductoModel((int) $a['site_id'], (int) $a['company_id']);
check('producto A no puede apuntar a categoría B', !$productsA->crear('Cruce rechazado', null, '1.00', 1, 0, 'activo', $catB, 21.0));
check('producto A sí puede usar su categoría', $productsA->crear('Producto A', null, '1.00', 1, 0, 'activo', $catA, 21.0));
check('listado de categorías A no contiene IDs de B', !in_array($catB, array_map('intval', array_column($productsA->listarCategorias(), 'id_categoria')), true));
check('duplicado de categoría dentro del tenant se rechaza', $productsA->crearCategoria('Bebidas') === null);

foreach ([$a, $b] as $tenant) {
    $config = json_decode((string) $db->query('SELECT configuracion FROM empresa WHERE id_empresa=' . (int) $tenant['company_id'])->fetchColumn(), true);
    check('config tenant fija moneda y timezone explícitas', ($config['currency'] ?? null) === 'EUR' && ($config['timezone'] ?? null) === 'Europe/Madrid');
    check('email funcional nace desactivado', ($config['email']['enabled'] ?? true) === false);
    check('DORLET/acceso nace disabled por tenant', ($config['access_control']['mode'] ?? null) === 'disabled');
    check('onboarding termina sin importación por defecto', ($config['onboarding']['import_status'] ?? null) === 'SKIPPED');
}
check('cada empresa conserva su propia tarifa homónima', (int) $db->query(
    "SELECT COUNT(*) FROM tipo_membresia WHERE nombre='Mensual Compartida' AND id_empresa IN (" . (int) $a['company_id'] . ',' . (int) $b['company_id'] . ')'
)->fetchColumn() === 2);

$duplicateAccess = TenantOnboardingFactory::input('Config Duplicate Access', [
    'site_access_email' => TenantOnboardingFactory::input('Config A')['site_access_email'],
]);
$rejected = false; $safeConflict = false;
try { (new TenantProvisioningService($db, 6))->provision($duplicateAccess); }
catch (DomainException $e) {
    $rejected = true;
    $safeConflict = !str_contains(strtolower($e->getMessage()), 'sqlstate')
        && !str_contains(strtolower($e->getMessage()), 'duplicate entry');
}
check('email técnico de sede sigue siendo globalmente único', $rejected);
check('conflicto no revela SQL ni índice interno', $safeConflict);
check('rechazo de email técnico no deja empresa parcial', (int) $db->query(
    'SELECT COUNT(*) FROM empresa WHERE nombre=' . $db->quote($duplicateAccess['company_name'])
)->fetchColumn() === 0);

$platformUsers = new UserModel();
$platformId = (int) $db->query("SELECT id_usuario FROM usuario WHERE rol='superadmin' AND id_empresa IS NULL LIMIT 1")->fetchColumn();
if ($platformId > 0) {
    $platformMail = (string) $db->query('SELECT email FROM usuario WHERE id_usuario=' . $platformId)->fetchColumn();
    check('perfil de plataforma usa el ámbito NULL explícito',
        !$platformUsers->correoExisteOtroUsuarioPlataforma($platformMail, $platformId));
    check('recuperación puede resolver explícitamente la cuenta interna de plataforma',
        (int) ($platformUsers->buscarSuperadminPorCorreo($platformMail)['id_usuario'] ?? 0) === $platformId);
    check('email de tenant no colisiona artificialmente con perfil de plataforma',
        !$platformUsers->correoExisteOtroUsuarioPlataforma('shared.person@test.invalid', $platformId));
}

finishTests();
