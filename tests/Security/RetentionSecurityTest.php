<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/helpers/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/services/AttendanceEventService.php';
require_once dirname(__DIR__, 2) . '/app/services/RetentionService.php';

$db = Database::getInstance()->getConnection();
$demo = null;
try {
    $demo = DemoGymFactory::create($db);
    $companyA = (int)$demo['empresa'];
    $siteA1 = (int)$demo['sedes'][0];
    $siteA2 = (int)$demo['sedes'][1];
    $memberA = (int)$demo['socios'][0];
    $type = (int)$demo['tarifa'];
    $companyB = (int)$db->query("SELECT MIN(id_empresa) FROM empresa WHERE id_empresa <> {$companyA}")->fetchColumn();
    $siteB = (int)$db->query("SELECT MIN(id_gimnasio) FROM gimnasio WHERE id_empresa={$companyB}")->fetchColumn();
    $memberB = (int)$db->query("SELECT MIN(id_usuario) FROM usuario WHERE id_empresa={$companyB} AND rol='socio'")->fetchColumn();

    $db->prepare(
        "INSERT INTO socio_membresia
         (id_socio,id_gimnasio,id_tipo_membresia,nombre_tipo,precio_pagado,metodo_pago,fecha_inicio,fecha_fin,estado_pago,idempotency_key)
         VALUES (:member,:site,:type,'Gimnasio seguridad',40,'efectivo','2026-01-01','2026-12-31','pagado',:key)"
    )->execute([':member'=>$memberA, ':site'=>$siteA1, ':type'=>$type, ':key'=>'f24-security-membership-' . $memberA]);
    $eventInsert = $db->prepare(
        "INSERT INTO attendance_event
         (event_id,id_empresa,id_gimnasio,id_socio,occurred_at_utc,local_date,source,external_reference,idempotency_key)
         VALUES (:uuid,:company,:site,:member,:occurred,:date,'IMPORT',:external,:key)"
    );
    $start = new DateTimeImmutable('2026-06-12');
    for ($week=0; $week<8; $week++) {
        for ($visit=0; $visit<4; $visit++) {
            $date = $start->modify('+' . ($week*7+$visit) . ' days')->format('Y-m-d');
            $ref = 'security-' . $week . '-' . $visit;
            $eventInsert->execute([
                ':uuid'=>RequestContext::newId(), ':company'=>$companyA, ':site'=>$siteA1, ':member'=>$memberA,
                ':occurred'=>$date . ' 10:00:00', ':date'=>$date, ':external'=>$ref,
                ':key'=>hash('sha256', $companyA . '|IMPORT|external:' . $ref),
            ]);
        }
    }
    $serviceA = new RetentionService($db, $companyA);
    $serviceA->run('2026-08-20');
    $detection = $serviceA->inbox()[0] ?? null;
    check('fixture hostil produce detección', $detection !== null);

    $crossTenant = false;
    try {
        (new RetentionService($db, $companyB))->act(
            (int)$detection['id_retention_detection'], (int)$demo['direccion'], 'REVIEW', RequestContext::newId(), (int)$detection['version']
        );
    } catch (DomainException) { $crossTenant = true; }
    check('IDOR de otra empresa queda rechazado', $crossTenant);

    $crossSite = false;
    try {
        (new RetentionService($db, $companyA, $siteA2))->act(
            (int)$detection['id_retention_detection'], (int)$demo['direccion'], 'REVIEW', RequestContext::newId(), (int)$detection['version']
        );
    } catch (DomainException) { $crossSite = true; }
    check('IDOR de otra sede queda rechazado', $crossSite);
    check('bandeja de otra sede queda vacía', (new RetentionService($db, $companyA, $siteA2))->inbox() === []);

    $foreignActor = false;
    try {
        $serviceA->act(
            (int)$detection['id_retention_detection'], $memberB, 'REVIEW', RequestContext::newId(), (int)$detection['version']
        );
    } catch (DomainException) { $foreignActor = true; }
    check('actor de otra empresa no puede atribuirse una revisión', $foreignActor);
    $receptionActor = false;
    try {
        $serviceA->act(
            (int)$detection['id_retention_detection'], (int)$demo['recepcion'], 'REVIEW', RequestContext::newId(), (int)$detection['version']
        );
    } catch (DomainException) { $receptionActor = true; }
    check('recepción tampoco puede saltarse Authorization invocando el servicio', $receptionActor);

    $eventsA = new AttendanceEventService($db, $companyA, new DateTimeZone('Europe/Madrid'));
    $wrongTenant = false;
    try {
        $eventsA->record($siteA1, $memberB, new DateTimeImmutable('2026-08-20 10:00:00Z'), 'MANUAL');
    } catch (DomainException) { $wrongTenant = true; }
    check('evento con socio de otra empresa queda rechazado', $wrongTenant);
    $wrongSite = false;
    try {
        $eventsA->record($siteA2, $memberA, new DateTimeImmutable('2026-08-20 10:00:00Z'), 'MANUAL');
    } catch (DomainException) { $wrongSite = true; }
    check('evento con sede manipulada queda rechazado', $wrongSite);

    $fkRejected = false;
    try {
        $eventInsert->execute([
            ':uuid'=>RequestContext::newId(), ':company'=>$companyA, ':site'=>$siteA1, ':member'=>$memberB,
            ':occurred'=>'2026-08-20 10:00:00', ':date'=>'2026-08-20', ':external'=>'cross-tenant-direct',
            ':key'=>hash('sha256','cross-tenant-direct'),
        ]);
    } catch (PDOException $error) { $fkRejected = (string)$error->getCode() === '23000'; }
    check('DB también rechaza evento cross-tenant', $fkRejected);

    $sqliReference = "f24-sqli-' OR 1=1 --";
    $sqliEvent = $eventsA->record(
        $siteA1, $memberA, new DateTimeImmutable('2026-08-20 10:01:00Z'), 'IMPORT', $sqliReference
    );
    $sqliLookup = $db->prepare('SELECT COUNT(*) FROM attendance_event WHERE id_empresa=:company AND external_reference=:reference');
    $sqliLookup->execute([':company'=>$companyA, ':reference'=>$sqliReference]);
    check('referencia SQLi sintética se trata como dato y crea solo su evento', $sqliEvent['created'] && (int)$sqliLookup->fetchColumn() === 1);

    check('dirección puede ver y revisar Retention', Authorization::can('direccion','retention.view') && Authorization::can('direccion','retention.review'));
    check('admin puede ver solo dentro de su sede', Authorization::can('admin','retention.view'));
    check('recepción no recibe métricas Retention', !Authorization::can('recepcion','retention.view') && !Authorization::can('recepcion','retention.review'));
    check('socio no recibe Retention', !Authorization::can('socio','retention.view'));

    $controller = (string)file_get_contents(dirname(__DIR__,2) . '/app/controllers/RetentionController.php');
    $router = (string)file_get_contents(dirname(__DIR__,2) . '/public/index.php');
    check('acción web exige POST y CSRF', str_contains($controller, "REQUEST_METHOD'] !== 'POST'") && str_contains($controller, 'Csrf::validarPost'));
    check('no existe endpoint web de ingesta manipulable', !str_contains($router, 'attendance_event') && !str_contains($router, 'attendance_record'));

    $db->exec("UPDATE empresa SET estado='inactiva' WHERE id_empresa={$companyA}");
    $inactiveRejected = false;
    try { $serviceA->run('2026-08-22'); } catch (DomainException) { $inactiveRejected = true; }
    check('tenant inactivo no ejecuta Retention', $inactiveRejected);
    $db->exec("UPDATE empresa SET estado='activa',onboarding_state='CONFIGURING' WHERE id_empresa={$companyA}");
    $configuringRejected = false;
    try {
        $eventsA->record($siteA1, $memberA, new DateTimeImmutable('2026-08-20 10:02:00Z'), 'MANUAL');
    } catch (DomainException) { $configuringRejected = true; }
    check('tenant CONFIGURING no admite negocio Retention', $configuringRejected);
    $db->exec("UPDATE empresa SET onboarding_state='ACTIVE' WHERE id_empresa={$companyA}");

    $actor = (int)$db->query("SELECT id_usuario FROM usuario WHERE rol='superadmin' AND id_empresa IS NULL AND activo=1 LIMIT 1")->fetchColumn();
    (new TenantProvisioningService($db, $actor))->cancel($companyA);
    $cancelledEvent = false;
    try {
        $eventsA->record($siteA1, $memberA, new DateTimeImmutable('2026-08-20 11:00:00Z'), 'MANUAL');
    } catch (DomainException) { $cancelledEvent = true; }
    check('tenant CANCELLED no admite eventos', $cancelledEvent);
    $cancelledRun = false;
    try { $serviceA->run('2026-08-22'); } catch (DomainException) { $cancelledRun = true; }
    check('tenant CANCELLED no ejecuta Retention', $cancelledRun);
} catch (Throwable $error) {
    check('escenario de seguridad Retention', false);
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
} finally {
    if ($demo !== null) DemoGymFactory::cleanup($db);
}
finishTests();
