<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/models/FinancialModel.php';
require_once dirname(__DIR__, 2) . '/app/models/SepaModel.php';
require_once dirname(__DIR__, 2) . '/app/services/MigrationService.php';

$db = Database::getInstance()->getConnection();
$demo = null;

/** @param callable():mixed $operation */
function lifecycleRejected(string $name, callable $operation): void
{
    $rejected = false;
    try {
        $operation();
    } catch (DomainException|MigrationException $error) {
        $rejected = str_contains(mb_strtolower($error->getMessage()), 'empresa')
            || str_contains(mb_strtolower($error->getMessage()), 'operativa');
    }
    check("tenant no operativo rechaza {$name}", $rejected);
}

try {
    $demo = DemoGymFactory::create($db);
    $company = (int) $demo['empresa'];
    $site = (int) $demo['sedes'][0];
    $member = (int) $demo['socios'][0];
    $employee = (int) $demo['recepcion'];
    $membership = (int) $demo['tarifa'];
    $product = (int) $demo['producto'];
    $actor = (int) $db->query("SELECT id_usuario FROM usuario WHERE rol='superadmin' AND id_empresa IS NULL AND activo=1 LIMIT 1")->fetchColumn();

    $users = new UserModel($site, $company);
    $memberships = new MembresiaModel($site, $company);
    $products = new ProductoModel($site, $company);
    $cash = new CashModel($site, $company);
    $sales = new VentaModel($site, $company);
    $sepa = new SepaModel($site, $company);
    $sites = new GimnasioModel($company);
    $financial = new FinancialModel($company, $site, $db);

    $cancelled = (new TenantProvisioningService($db, $actor))->cancel($company);
    check('transición ACTIVE a CANCELLED se confirma', $cancelled['cancelled'] === true);
    check('cancelación deja empresa inactiva y lifecycle cancelado',
        $db->query("SELECT CONCAT(estado,':',onboarding_state) FROM empresa WHERE id_empresa={$company}")->fetchColumn()
            === 'inactiva:CANCELLED');

    $before = [
        'users' => (int) $db->query("SELECT COUNT(*) FROM usuario WHERE id_empresa={$company}")->fetchColumn(),
        'sites' => (int) $db->query("SELECT COUNT(*) FROM gimnasio WHERE id_empresa={$company}")->fetchColumn(),
        'types' => (int) $db->query("SELECT COUNT(*) FROM tipo_membresia WHERE id_empresa={$company}")->fetchColumn(),
        'products' => (int) $db->query("SELECT COUNT(*) FROM producto WHERE id_gimnasio={$site}")->fetchColumn(),
        'sales' => (int) $db->query("SELECT COUNT(*) FROM venta WHERE id_gimnasio={$site}")->fetchColumn(),
        'cash' => (int) $db->query("SELECT COUNT(*) FROM caja_sesion WHERE id_empresa={$company}")->fetchColumn(),
        'mandates' => (int) $db->query("SELECT COUNT(*) FROM mandato_sepa m INNER JOIN gimnasio g ON g.id_gimnasio=m.id_gimnasio WHERE g.id_empresa={$company}")->fetchColumn(),
        'remittances' => (int) $db->query("SELECT COUNT(*) FROM remesa WHERE id_gimnasio={$site}")->fetchColumn(),
        'imports' => (int) $db->query("SELECT COUNT(*) FROM migration_batch WHERE id_empresa={$company}")->fetchColumn(),
    ];

    lifecycleRejected('alta de socio', fn() => $users->crear(
        'Bloqueado', 'Cancelado', 'F221BLOCK1', null, 'blocked.member@test.invalid',
        'blocked.member.f221', 'Synthetic-only-F221!'
    ));
    lifecycleRejected('edición de socio', fn() => $users->actualizarDatosSocio(
        $member, 'No', 'Cambiar', null, 'blocked.edit@test.invalid', null
    ));
    lifecycleRejected('alta de empleado', fn() => $users->crearEmpleado(
        'Bloqueado', 'Empleado', 'F221BLOCK2', 'blocked.employee@test.invalid', null,
        'blocked.employee.f221', 'Synthetic-only-F221!', 'recepcion', $site
    ));
    lifecycleRejected('edición de empleado', fn() => $users->actualizarEmpleado(
        $employee, 'No', 'Cambiar', 'blocked.employee.edit@test.invalid', null, 'recepcion', $site
    ));
    lifecycleRejected('cambio de contraseña', fn() => $users->cambiarContrasena($member, 'Synthetic-change-F221!'));
    lifecycleRejected('alta de membresía', fn() => $memberships->crearTipo(
        'Bloqueada F221', null, '10.00', 1, 'activo'
    ));
    lifecycleRejected('renovación de membresía', function () use ($memberships, $member, $membership): void {
        $error = '';
        $memberships->contratar($member, $membership, 'efectivo', $error, null, 'mostrador', 'f221-cancelled-membership');
    });
    lifecycleRejected('obligación/cobro', fn() => $financial->registrarMembresia(1, null));
    lifecycleRejected('apertura de caja', function () use ($cash, $employee): void {
        $error = '';
        $cash->abrir('1.00', $employee, $error);
    });
    lifecycleRejected('venta', function () use ($sales, $product, $member, $employee): void {
        $error = '';
        $sales->registrar([['id_producto'=>$product,'cantidad'=>1]], $member, 'efectivo', $employee, $error, 'f221-cancelled-sale');
    });
    lifecycleRejected('alta de producto', fn() => $products->crear(
        'Bloqueado F221', null, '1.00', 1, 0, 'activo', null
    ));
    lifecycleRejected('stock', fn() => $products->actualizarStock($product, 99));
    lifecycleRejected('mandato', function () use ($sepa, $member): void {
        $error = '';
        $sepa->crearMandato($member, 'ES9121000418450200051332', date('Y-m-d'), $error, 'recurrente', 'f221-cancelled-mandate');
    });
    lifecycleRejected('remesa', function () use ($sepa): void {
        $error = '';
        $sepa->crearRemesa([1], 'Bloqueada F221', date('Y-m-d'), null, $error, 'f221-cancelled-remittance');
    });
    lifecycleRejected('configuración bancaria', fn() => $sepa->guardarAcreedor($site, [
        'razon_social'=>'Sintética','cif'=>'B00000000','iban'=>'ES9121000418450200051332',
        'bic'=>'CAIXESBBXXX','identificador_acreedor'=>'ES00ZZZ0000000000',
    ]));
    lifecycleRejected('configuración/branding', fn() => $sites->actualizarMarca($site, null, '#111111', '#ffffff'));
    lifecycleRejected('alta de sede', fn() => $sites->crear([
        'nombre'=>'Bloqueada F221','razon_social'=>'','cif'=>'','direccion'=>'','telefono'=>'','email'=>'',
    ]));
    lifecycleRejected('importación', fn() => new MigrationService($company, $site, $demo['direccion'], $db));

    $after = [
        'users' => (int) $db->query("SELECT COUNT(*) FROM usuario WHERE id_empresa={$company}")->fetchColumn(),
        'sites' => (int) $db->query("SELECT COUNT(*) FROM gimnasio WHERE id_empresa={$company}")->fetchColumn(),
        'types' => (int) $db->query("SELECT COUNT(*) FROM tipo_membresia WHERE id_empresa={$company}")->fetchColumn(),
        'products' => (int) $db->query("SELECT COUNT(*) FROM producto WHERE id_gimnasio={$site}")->fetchColumn(),
        'sales' => (int) $db->query("SELECT COUNT(*) FROM venta WHERE id_gimnasio={$site}")->fetchColumn(),
        'cash' => (int) $db->query("SELECT COUNT(*) FROM caja_sesion WHERE id_empresa={$company}")->fetchColumn(),
        'mandates' => (int) $db->query("SELECT COUNT(*) FROM mandato_sepa m INNER JOIN gimnasio g ON g.id_gimnasio=m.id_gimnasio WHERE g.id_empresa={$company}")->fetchColumn(),
        'remittances' => (int) $db->query("SELECT COUNT(*) FROM remesa WHERE id_gimnasio={$site}")->fetchColumn(),
        'imports' => (int) $db->query("SELECT COUNT(*) FROM migration_batch WHERE id_empresa={$company}")->fetchColumn(),
    ];
    check('rechazos no dejan ningún efecto parcial', $after === $before);

    check('lectura de soporte conserva ficha existente', $users->buscarPorId($member) !== null);
    check('lectura de soporte conserva inventario existente', $products->buscarPorId($product) !== null);
    check('login técnico queda rechazado', (new GimnasioModel($company))->autenticar(
        (string) $db->query("SELECT email_acceso FROM gimnasio WHERE id_gimnasio={$site}")->fetchColumn(),
        'incorrecta-a-proposito'
    ) === null);

    $db->prepare("UPDATE empresa SET estado='inactiva',onboarding_state='ACTIVE' WHERE id_empresa=:id")
        ->execute([':id'=>$company]);
    lifecycleRejected('negocio con flag activa=0', fn() => $products->actualizarStock($product, 88));
    check('empresa inactiva con lifecycle ACTIVE tampoco cambia stock',
        (int) $db->query("SELECT stock FROM producto WHERE id_producto={$product}")->fetchColumn() !== 88);
} catch (Throwable $error) {
    check('escenario completo de lifecycle', false);
    fwrite(STDERR, get_class($error) . "\n");
} finally {
    if ($demo !== null) DemoGymFactory::cleanup($db);
}

finishTests();
