<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/services/SocioProfileService.php';

$db = Database::getInstance()->getConnection();
$demo = null;
$renamedLog = false;
try {
    $demo = DemoGymFactory::create($db);
    $company = (int) $demo['empresa'];
    $site = (int) $demo['sedes'][0];
    $actor = (int) $demo['recepcion'];
    [$memberA, $memberB] = array_map('intval', $demo['socios']);
    $service = new SocioProfileService($company, $site, $actor, $db);

    $beforeA = $db->query("SELECT * FROM usuario WHERE id_usuario={$memberA}")->fetch();
    $error = '';
    $updated = $service->update($memberA, (int) $beforeA['profile_version'], [
        'nombre'=>'Ana','apellidos'=>'F23','dni'=>'12345678Z','telefono'=>'+34600123123',
        'email'=>'ana.f23@example.invalid','iban'=>'ES9121000418450200051332',
    ], $error);
    $afterA = $db->query("SELECT * FROM usuario WHERE id_usuario={$memberA}")->fetch();
    check('edición atómica actualiza todos los campos', ($updated['status'] ?? '') === 'updated'
        && $afterA['dni'] === '12345678Z' && $afterA['iban'] === 'ES9121000418450200051332');
    check('edición incrementa profile_version una vez', (int) $afterA['profile_version'] === (int) $beforeA['profile_version'] + 1);
    $audit = $db->query("SELECT accion,detalle,valor_anterior,valor_nuevo,resultado FROM log_actividad WHERE id_entidad={$memberA} AND accion IN ('Edición de socio','Cambio de DNI/NIE','Cambio de IBAN') ORDER BY id")->fetchAll();
    $auditBlob = json_encode($audit, JSON_UNESCAPED_UNICODE);
    check('auditoría registra actor/campos/resultados', count($audit) === 3 && !str_contains($auditBlob, '12345678Z'));
    check('auditoría no persiste IBAN completo', !str_contains($auditBlob, 'ES9121000418450200051332'));

    $beforeDuplicate = $db->query("SELECT dni,email,profile_version FROM usuario WHERE id_usuario={$memberA}")->fetch();
    $dniB = (string) $db->query("SELECT dni FROM usuario WHERE id_usuario={$memberB}")->fetchColumn();
    $error = '';
    $duplicate = $service->update($memberA, (int) $beforeDuplicate['profile_version'], [
        'nombre'=>'No','apellidos'=>'Duplicar','dni'=>$dniB,'telefono'=>null,
        'email'=>'duplicate.f23@example.invalid','iban'=>null,
    ], $error);
    $afterDuplicate = $db->query("SELECT dni,email,profile_version FROM usuario WHERE id_usuario={$memberA}")->fetch();
    check('DNI duplicado se rechaza', $duplicate === null && str_contains($error, 'DNI/NIE'));
    check('duplicado conserva íntegro el perfil anterior', $afterDuplicate === $beforeDuplicate);

    // Usamos una empresa ajena del fixture base sin alterar el tenant F23.
    $foreignCompany = (int) $db->query("SELECT id_empresa FROM empresa WHERE id_empresa<>{$company} ORDER BY id_empresa LIMIT 1")->fetchColumn();
    $foreignMember = (int) $db->query("SELECT id_usuario FROM usuario WHERE id_empresa={$foreignCompany} AND rol='socio' LIMIT 1")->fetchColumn();
    if ($foreignMember > 0) {
        $foreignBefore = $db->query("SELECT nombre,profile_version FROM usuario WHERE id_usuario={$foreignMember}")->fetch();
        $error = '';
        $cross = $service->update($foreignMember, (int) $foreignBefore['profile_version'], [
            'nombre'=>'Cruce','apellidos'=>'Bloqueado','dni'=>'00000000T','telefono'=>null,
            'email'=>'cross.f23@example.invalid','iban'=>null,
        ], $error);
        check('servicio rechaza ID de otro tenant', $cross === null);
        check('otro tenant permanece intacto', $db->query("SELECT nombre FROM usuario WHERE id_usuario={$foreignMember}")->fetchColumn() === $foreignBefore['nombre']);
    } else {
        check('fixture contiene socio de otro tenant para aislamiento', false);
        check('otro tenant permanece intacto', false);
    }

    $current = $db->query("SELECT * FROM usuario WHERE id_usuario={$memberA}")->fetch();
    $service = new SocioProfileService($company, $site, $actor, $db);
    $db->exec('RENAME TABLE log_actividad TO log_actividad_f23_fault');
    $renamedLog = true;
    $error = '';
    $failedAudit = $service->update($memberA, (int) $current['profile_version'], [
        'nombre'=>'Rollback','apellidos'=>'Auditoría','dni'=>'00000000T','telefono'=>null,
        'email'=>'rollback.audit.f23@example.invalid','iban'=>null,
    ], $error);
    $db->exec('RENAME TABLE log_actividad_f23_fault TO log_actividad');
    $renamedLog = false;
    $afterFault = $db->query("SELECT nombre,email,profile_version FROM usuario WHERE id_usuario={$memberA}")->fetch();
    check('fallo de auditoría REQUIRED rechaza la edición', $failedAudit === null);
    check('fallo intermedio revierte todos los campos', $afterFault['nombre'] === $current['nombre']
        && $afterFault['email'] === $current['email'] && (int) $afterFault['profile_version'] === (int) $current['profile_version']);
} catch (Throwable $exception) {
    check('escenario integral F23', false);
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
} finally {
    if ($renamedLog) {
        try { $db->exec('RENAME TABLE log_actividad_f23_fault TO log_actividad'); } catch (Throwable) {}
    }
    if ($demo !== null) DemoGymFactory::cleanup($db);
}

finishTests();
