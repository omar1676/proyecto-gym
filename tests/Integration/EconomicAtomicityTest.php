<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/services/SocioRegistrationService.php';
require_once dirname(__DIR__, 2) . '/app/models/MembresiaModel.php';

$db = Database::getInstance()->getConnection();
$prefix = 'f20_atomic_';
$emailFail = $prefix . 'fail@example.invalid';
$emailOk = $prefix . 'ok@example.invalid';
$cleanupUsers = static function () use ($db, $prefix): void {
    $ids = $db->query("SELECT id_usuario FROM usuario WHERE nombre_usuario LIKE '" . $prefix . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) pruebasLimpiarMembresias($db, 'sm.id_socio=' . (int) $id);
    $db->exec("DELETE FROM log_actividad WHERE detalle LIKE 'F20 Atomic%' OR accion IN ('Alta socio/membresía rechazada','Alta de membresía inicial')");
    $db->exec("DELETE FROM usuario WHERE nombre_usuario LIKE '" . $prefix . "%'");
};
$cleanupUsers();

$service = new SocioRegistrationService(1, 1);
$base = [
    'nombre' => 'F20', 'apellidos' => 'Atomic', 'dni' => '70000001A',
    'telefono' => null, 'email' => $emailFail, 'usuario' => $prefix . 'fail',
    'contrasena' => 'Synthetic-F20-Only!', 'iban' => null,
];
$error = '';
$fallo = $service->registrar($base, 999999999, 'efectivo', null, 1, 'f20atomicfail0000000000000000001', $error);
check('fallo de membresía hace fallar el alta completa', $fallo === null);
check('fallo de membresía no deja socio huérfano', (int) $db->query("SELECT COUNT(*) FROM usuario WHERE email='" . $emailFail . "'")->fetchColumn() === 0);
check('fallo atómico no deja membresía, obligación ni cobro',
    (int) $db->query("SELECT COUNT(*) FROM socio_membresia sm JOIN usuario u ON u.id_usuario=sm.id_socio WHERE u.email='" . $emailFail . "'")->fetchColumn() === 0);
check('fallo se audita como fallo, nunca como éxito',
    (int) $db->query("SELECT COUNT(*) FROM log_actividad WHERE accion='Alta socio/membresía rechazada' AND resultado='fallo'")->fetchColumn() >= 1
    && (int) $db->query("SELECT COUNT(*) FROM log_actividad WHERE accion='Alta de socio' AND detalle LIKE 'F20 Atomic%' AND resultado='exito'")->fetchColumn() === 0);

$tipo = (int) $db->query("SELECT id_tipo_membresia FROM tipo_membresia WHERE id_empresa=1 AND estado='activo' ORDER BY id_tipo_membresia LIMIT 1")->fetchColumn();
$base['dni'] = '70000002B'; $base['email'] = $emailOk; $base['usuario'] = $prefix . 'ok';
$error = '';
$alta = $service->registrar($base, $tipo, 'efectivo', null, 1, 'f20atomicok000000000000000000001', $error);
check('alta válida confirma socio y membresía', !empty($alta['id_socio']) && !empty($alta['id_membresia']));
$idM = (int) ($alta['id_membresia'] ?? 0);
check('alta válida confirma obligación y cobro juntos',
    (int) $db->query('SELECT COUNT(*) FROM obligacion_pago WHERE id_socio_membresia=' . $idM)->fetchColumn() === 1
    && (int) $db->query('SELECT COUNT(*) FROM cobro WHERE id_socio_membresia=' . $idM)->fetchColumn() === 1);
check('alta válida tiene auditoría de éxito con tenant y sede',
    (int) $db->query("SELECT COUNT(*) FROM log_actividad WHERE id_usuario_afectado=" . (int) $alta['id_socio'] . " AND accion='Alta de socio' AND resultado='exito' AND id_empresa=1 AND id_gimnasio=1")->fetchColumn() === 1);
check('membresía inicial audita su entidad y resultado real',
    (int) $db->query("SELECT COUNT(*) FROM log_actividad WHERE accion='Alta de membresía inicial' AND entidad='socio_membresia' AND id_entidad=" . $idM . " AND resultado='exito' AND id_empresa=1 AND id_gimnasio=1")->fetchColumn() === 1);

// Inyecta un fallo después del UPDATE del IBAN y antes del INSERT de membresía.
$socio = 3;
$originalIban = $db->query('SELECT iban FROM usuario WHERE id_usuario=' . $socio)->fetchColumn();
$oldIban = 'ES9121000418450200051332';
$newIban = 'DE89370400440532013000';
$db->prepare('UPDATE usuario SET iban=:iban WHERE id_usuario=:id')->execute([':iban'=>$oldIban, ':id'=>$socio]);
$db->exec('DROP TRIGGER IF EXISTS f20_fail_membership_insert');
$db->exec("CREATE TRIGGER f20_fail_membership_insert BEFORE INSERT ON socio_membresia FOR EACH ROW BEGIN IF NEW.idempotency_key LIKE 'f20-iban-fail%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='F20 synthetic failure'; END IF; END");
try {
    $model = new MembresiaModel(1, 1);
    $error = '';
    $falloIban = $model->contratar($socio, $tipo, 'transferencia', $error, null, 'mostrador', 'f20-iban-fail-0000000000000000001', 1, $newIban);
    check('fallo posterior al cambio de IBAN rechaza contratación', $falloIban === null);
    check('rollback restaura el IBAN anterior', (string) $db->query('SELECT iban FROM usuario WHERE id_usuario=' . $socio)->fetchColumn() === $oldIban);
    check('rollback no deja membresía ni obligación parcial',
        (int) $db->query("SELECT COUNT(*) FROM socio_membresia WHERE idempotency_key='f20-iban-fail-0000000000000000001'")->fetchColumn() === 0);
} finally {
    $db->exec('DROP TRIGGER IF EXISTS f20_fail_membership_insert');
    $db->prepare('UPDATE usuario SET iban=:iban WHERE id_usuario=:id')->execute([':iban'=>$originalIban !== false ? $originalIban : null, ':id'=>$socio]);
}

$cleanupUsers();
finishTests();
