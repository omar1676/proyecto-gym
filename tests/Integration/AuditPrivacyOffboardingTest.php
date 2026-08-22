<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/models/LogModel.php';
require_once dirname(__DIR__, 2) . '/app/helpers/RequestContext.php';
require_once dirname(__DIR__, 2) . '/app/helpers/Sesion.php';
require_once dirname(__DIR__, 2) . '/app/helpers/TenantContext.php';
require_once dirname(__DIR__, 2) . '/app/controllers/MediaController.php';
require_once dirname(__DIR__, 2) . '/app/controllers/AuthController.php';

$db = Database::getInstance()->getConnection();
$demo = null;
try {
    $demo = DemoGymFactory::create($db);
    $empresa = $demo['empresa']; $sede = $demo['sedes'][0]; $otraSede = $demo['sedes'][1];
    $recepcion = $demo['recepcion'];
    $users = new UserModel($sede, $empresa);
    $sessionStarted = time();
    check('empleado activo puede continuar su sesión', Sesion::usuarioPuedeContinuar($db, $recepcion, $sessionStarted));
    check('desactivación cambia estado', $users->toggleActivo($recepcion));
    check('sesión ya abierta se invalida tras offboarding', !Sesion::usuarioPuedeContinuar($db, $recepcion, $sessionStarted));
    check('empleado desactivado no aparece como login activo', (int) $db->query("SELECT activo FROM usuario WHERE id_usuario={$recepcion}")->fetchColumn() === 0);
    check('reactivación controlada funciona', $users->toggleActivo($recepcion));
    check('sesión nueva tras reactivación puede continuar inmediatamente', Sesion::usuarioPuedeContinuar($db, $recepcion, time()));
    $beforeRoleChange = time();
    check('cambio de rol del empleado se confirma', $users->actualizarEmpleado(
        $recepcion, 'Recepción', 'Sintética', 'reception.role@example.invalid', null, 'admin', $sede
    ));
    check('cambio de rol invalida la sesión que conservaba permisos anteriores',
        !Sesion::usuarioPuedeContinuar($db, $recepcion, $beforeRoleChange));
    check('restauración del rol sintético se confirma', $users->actualizarEmpleado(
        $recepcion, 'Recepción', 'Sintética', 'reception.role@example.invalid', null, 'recepcion', $sede
    ));

    $_SESSION = [
        'logueado' => true, 'usuario_id' => $recepcion, 'usuario_rol' => 'recepcion',
        'empresa_id' => $empresa, 'gimnasio_id' => $sede, 'gimnasio_auth_id' => $sede,
    ];
    $context = TenantContext::desdeSesion();
    $socio = $users->buscarPorId($demo['socios'][0]);
    $direccion = (new UserModel(null, $empresa))->buscarPorId($demo['direccion']);
    check('recepción puede ver foto de socio de su sede', $socio !== null && MediaController::canView($context, $socio));
    check('recepción no puede ver foto de dirección sin permiso', $direccion !== null && !MediaController::canView($context, $direccion));
    $otroEmpleado = (new UserModel($otraSede, $empresa))->crearEmpleado(
        'Empleado', 'Otra Sede', 'F21OTHER001', 'other.site@example.invalid', '600999991',
        'f21_other_site', 'synthetic-only', 'recepcion', $otraSede
    );
    check('modelo de recepción no resuelve foto/usuario de otra sede', $otroEmpleado && $users->buscarPorId((int) $otroEmpleado) === null);
    $foreign = (int) $db->query("SELECT id_usuario FROM usuario WHERE id_empresa <> {$empresa} ORDER BY id_usuario LIMIT 1")->fetchColumn();
    check('modelo de recepción no resuelve foto/usuario de otro tenant', $foreign > 0 && $users->buscarPorId($foreign) === null);

    RequestContext::resetForTests('11111111-2222-4333-8444-555555555555', 'WEB');
    (new LogModel($empresa))->registrarCambio(
        $recepcion, 'F21_TEST_EVENT', 'Evento sintético', $demo['socios'][0], 'socio',
        $demo['socios'][0], null, null, $sede, 'fallo', 'SYNTHETIC_FAILURE',
        ['safe' => 'value', 'password' => 'must-not-persist']
    );
    $audit = $db->query("SELECT * FROM log_actividad WHERE id_empresa={$empresa} AND accion='F21_TEST_EVENT' ORDER BY id DESC LIMIT 1")->fetch();
    $metadata = json_decode((string) ($audit['metadata_json'] ?? ''), true);
    check('auditoría tiene event_id único', preg_match('/^[a-f0-9-]{36}$/', (string) ($audit['event_id'] ?? '')) === 1);
    check('auditoría propaga correlation ID', ($audit['correlation_id'] ?? '') === '11111111-2222-4333-8444-555555555555');
    check('auditoría conserva actor empresa sede origen acción y resultado',
        (int) $audit['id_usuario'] === $recepcion && (int) $audit['id_empresa'] === $empresa
        && (int) $audit['id_gimnasio'] === $sede && $audit['origin'] === 'WEB'
        && $audit['resultado'] === 'fallo' && $audit['reason_code'] === 'SYNTHETIC_FAILURE');
    check('auditoría elimina secretos de metadata', ($metadata['safe'] ?? '') === 'value' && !array_key_exists('password', $metadata));

    // auditAuth no usa estado del constructor. Evitar arrancar otra sesión
    // después de que el runner ya haya escrito salida mantiene el test limpio.
    $authController = (new ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();
    $auditAuth = new ReflectionMethod(AuthController::class, 'auditAuth');
    $auditAuth->setAccessible(true);
    $target = $users->buscarPorId($recepcion);
    $gym = $db->query("SELECT * FROM gimnasio WHERE id_gimnasio={$sede}")->fetch(PDO::FETCH_ASSOC);
    $auditAuth->invoke($authController, 'LOGIN', 'fallo', $target, $gym, 'INVALID_CREDENTIALS');
    $failedLogin = $db->query("SELECT * FROM log_actividad WHERE id_empresa={$empresa} AND accion='LOGIN' ORDER BY id DESC LIMIT 1")->fetch();
    check('login fallido conserva actor anónimo aunque el username exista',
        $failedLogin && $failedLogin['id_usuario'] === null && $failedLogin['actor_type'] === 'anonymous'
        && (int) $failedLogin['id_usuario_afectado'] === $recepcion);
    $auditAuth->invoke($authController, 'LOGIN', 'exito', $target, $gym, 'AUTHENTICATED');
    $successfulLogin = $db->query("SELECT * FROM log_actividad WHERE id_empresa={$empresa} AND accion='LOGIN' ORDER BY id DESC LIMIT 1")->fetch();
    check('login exitoso identifica actor, tenant, sede, origen y resultado',
        $successfulLogin && (int) $successfulLogin['id_usuario'] === $recepcion
        && (int) $successfulLogin['id_empresa'] === $empresa && (int) $successfulLogin['id_gimnasio'] === $sede
        && $successfulLogin['actor_type'] === 'usuario' && $successfulLogin['origin'] === 'WEB'
        && $successfulLogin['resultado'] === 'exito');
} catch (Throwable $e) {
    check('escenario F21 completo', false);
    fwrite(STDERR, $e->getMessage() . "\n");
} finally {
    $_SESSION = [];
    if ($demo !== null) DemoGymFactory::cleanup($db);
}

finishTests();
