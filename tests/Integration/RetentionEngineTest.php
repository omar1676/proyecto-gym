<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/services/AttendanceEventService.php';
require_once dirname(__DIR__, 2) . '/app/services/RetentionService.php';

$db = Database::getInstance()->getConnection();
$demo = null;

/** @return int */
function f24CreateMember(PDO $db, int $company, int $site, string $suffix): int
{
    $users = new UserModel($site, $company);
    $ok = $users->crear(
        'Socio' . $suffix, 'Retention Sintético', 'F24' . $suffix,
        null, 'f24.' . strtolower($suffix) . '@example.invalid',
        'f24_' . strtolower($suffix), 'synthetic-only-f24'
    );
    if (!$ok) throw new RuntimeException('No se pudo crear socio F24 ' . $suffix);
    $stmt = $db->prepare('SELECT id_usuario FROM usuario WHERE id_empresa=:company AND nombre_usuario=:username');
    $stmt->execute([':company'=>$company, ':username'=>'f24_' . strtolower($suffix)]);
    return (int)$stmt->fetchColumn();
}

function f24Membership(PDO $db, int $member, int $type, int $site, bool $trial = false, string $start = '2026-01-01', string $end = '2026-12-31'): void
{
    $stmt = $db->prepare(
        'INSERT INTO socio_membresia
         (id_socio,id_gimnasio,id_tipo_membresia,nombre_tipo,precio_pagado,metodo_pago,fecha_inicio,fecha_fin,es_prueba,estado_pago,idempotency_key)
         VALUES (:member,:site,:type,\'Gimnasio sintético\',40.00,\'efectivo\',:start,:end,:trial,\'pagado\',:key)'
    );
    $stmt->execute([
        ':member'=>$member, ':site'=>$site, ':type'=>$type, ':start'=>$start, ':end'=>$end,
        ':trial'=>$trial ? 1 : 0, ':key'=>substr('f24-' . $member . '-' . hash('sha256', $start . $end), 0, 36),
    ]);
}

/** @return list<string> */
function f24Pattern(string $start, int $weeks, int $visitsPerWeek): array
{
    $dates = [];
    $origin = new DateTimeImmutable($start);
    for ($week=0; $week<$weeks; $week++) {
        for ($visit=0; $visit<$visitsPerWeek; $visit++) {
            $dates[] = $origin->modify('+' . ($week * 7 + $visit) . ' days')->format('Y-m-d');
        }
    }
    return $dates;
}

try {
    $demo = DemoGymFactory::create($db);
    $company = (int)$demo['empresa'];
    $site = (int)$demo['sedes'][0];
    $type = (int)$demo['tarifa'];
    $db->prepare("UPDATE tipo_membresia SET nombre='Gimnasio sintético' WHERE id_tipo_membresia=:id AND id_empresa=:company")
        ->execute([':id'=>$type, ':company'=>$company]);
    $db->prepare("INSERT INTO retention_activity_mapping (id_empresa,id_tipo_membresia,activity_family) VALUES (:company,:type,'GYM')")
        ->execute([':company'=>$company, ':type'=>$type]);

    $members = [];
    foreach (['A','B','C','D','E','F'] as $suffix) {
        $members[$suffix] = f24CreateMember($db, $company, $site, $suffix);
        f24Membership($db, $members[$suffix], $type, $site, $suffix === 'E');
    }
    $members['G'] = f24CreateMember($db, $company, $site, 'G');
    f24Membership($db, $members['G'], $type, $site, false, '2025-01-01', '2025-12-31');
    $members['H'] = f24CreateMember($db, $company, $site, 'H');
    f24Membership($db, $members['H'], $type, $site, false, '2027-01-01', '2027-12-31');
    $timezone = new DateTimeZone('Europe/Madrid');
    $events = new AttendanceEventService($db, $company, $timezone);
    $sequence = 0;
    $recordDates = function (int $member, array $dates, string $label) use ($events, $site, $timezone, &$sequence): void {
        foreach ($dates as $date) {
            $sequence++;
            $events->record($site, $member, new DateTimeImmutable($date . ' 12:00:00', $timezone), 'IMPORT', $label . '-' . $sequence);
        }
    };
    $baselineStart = '2026-06-12';
    $recentStart = '2026-08-07';
    $recordDates($members['A'], f24Pattern($baselineStart, 8, 5), 'a-base');
    $recordDates($members['A'], f24Pattern($recentStart, 2, 5), 'a-recent');
    $recordDates($members['B'], f24Pattern($baselineStart, 8, 4), 'b-base');
    $recordDates($members['B'], f24Pattern($recentStart, 2, 1), 'b-recent');
    $recordDates($members['C'], f24Pattern($baselineStart, 8, 4), 'c-base');
    $recordDates($members['D'], f24Pattern($baselineStart, 8, 1), 'd-base');
    $recordDates($members['D'], f24Pattern($recentStart, 2, 1), 'd-recent');
    $recordDates($members['F'], array_merge(f24Pattern($baselineStart, 1, 6), f24Pattern('2026-07-31', 1, 6)), 'f-irregular');

    $first = $events->record($site, $members['B'], new DateTimeImmutable('2026-08-08 13:00:00', $timezone), 'IMPORT', 'duplicate-external-f24');
    $retry = $events->record($site, $members['B'], new DateTimeImmutable('2026-08-08 13:00:00', $timezone), 'IMPORT', 'duplicate-external-f24');
    check('misma external_reference es idempotente', $first['created'] === true && $retry['created'] === false && $first['id'] === $retry['id']);
    for ($attempt=0; $attempt<10; $attempt++) {
        $events->record($site, $members['B'], new DateTimeImmutable('2026-08-08 13:00:00', $timezone), 'IMPORT', 'duplicate-external-f24');
    }
    check('repetir el mismo acceso 2/5/10 veces conserva un evento', (int)$db->query(
        "SELECT COUNT(*) FROM attendance_event WHERE id_empresa={$company} AND external_reference='duplicate-external-f24'"
    )->fetchColumn() === 1);
    $sameEventId = RequestContext::newId();
    $eventOne = $events->record($site, $members['A'], new DateTimeImmutable('2026-08-09 15:00:00', $timezone), 'MANUAL', null, $sameEventId);
    $eventRetry = $events->record($site, $members['A'], new DateTimeImmutable('2026-08-09 15:00:00', $timezone), 'MANUAL', null, $sameEventId);
    check('mismo event_id es idempotente', $eventOne['created'] && !$eventRetry['created']);
    $conflict = false;
    try {
        $events->record($site, $members['A'], new DateTimeImmutable('2026-08-08 13:00:00', $timezone), 'IMPORT', 'duplicate-external-f24');
    } catch (DomainException) { $conflict = true; }
    check('colisión idempotente con otro socio se rechaza', $conflict);

    $service = new RetentionService($db, $company);
    $result = $service->run('2026-08-20');
    check('job evalúa seis patrones sintéticos', $result['evaluated'] === 6);
    check('membresía finalizada o futura no entra en Retention', (int)$db->query(
        "SELECT COUNT(*) FROM retention_detection WHERE id_empresa={$company} AND id_socio IN ({$members['G']},{$members['H']})"
    )->fetchColumn() === 0);
    check('job clasifica dos normales', $result['normal'] === 2);
    check('job clasifica dos sin histórico suficiente', $result['insufficient'] === 2);
    check('job genera una atención y una atención alta', $result['attention'] === 1 && $result['high_attention'] === 1);
    $rerun = $service->run('2026-08-20');
    check('repetir job diario reutiliza resultado sin duplicar', $rerun['reused'] === true
        && (int)$db->query("SELECT COUNT(*) FROM retention_detection WHERE id_empresa={$company}")->fetchColumn() === 2);

    $inbox = $service->inbox();
    $inboxMembers = array_values(array_unique(array_map('intval', array_column($inbox, 'id_socio'))));
    sort($inboxMembers);
    $expectedInboxMembers = [$members['B'], $members['C']];
    sort($expectedInboxMembers);
    check('bandeja contiene únicamente B y C', count($inbox) === 2 && $inboxMembers === $expectedInboxMembers);
    check('bandeja minimiza PII', !array_key_exists('dni', $inbox[0]) && !array_key_exists('iban', $inbox[0]) && !array_key_exists('email', $inbox[0]));
    check('explicación muestra baseline, reciente y caída', str_contains($inbox[0]['explanation'], 'Frecuencia habitual')
        && str_contains($inbox[0]['explanation'], 'Caída aproximada'));
    check('preview no contiene contenido económico', !preg_match('/cuota|dinero|impag|renovaci/iu', $inbox[0]['suggested_message']));

    $attention = array_values(array_filter($inbox, fn(array $row): bool => $row['level'] === 'ATTENTION'))[0];
    $actionKey = RequestContext::newId();
    check('revisión manual se confirma', $service->act((int)$attention['id_retention_detection'], (int)$demo['direccion'], 'REVIEW', $actionKey, (int)$attention['version']));
    check('reintento de misma acción es idempotente', $service->act((int)$attention['id_retention_detection'], (int)$demo['direccion'], 'REVIEW', $actionKey, (int)$attention['version']));
    $stale = false;
    try {
        $service->act((int)$attention['id_retention_detection'], (int)$demo['direccion'], 'DISMISS', RequestContext::newId(), (int)$attention['version']);
    } catch (DomainException) { $stale = true; }
    check('dos empleados no pueden pisar una revisión', $stale);

    $events->record($site, $members['C'], new DateTimeImmutable('2026-08-21 09:00:00', $timezone), 'MANUAL', 'return-c-f24');
    $returnRun = $service->run('2026-08-21');
    check('regreso posterior se detecta una sola vez', $returnRun['returned'] === 1
        && (string)$db->query("SELECT status FROM retention_detection WHERE id_empresa={$company} AND id_socio={$members['C']}")->fetchColumn() === 'RETURNED');
    check('RETURNED guarda un día desde detección hasta regreso', (int)$db->query("SELECT days_to_return FROM retention_detection WHERE id_empresa={$company} AND id_socio={$members['C']}")->fetchColumn() === 1);
    $queueMetrics = $service->metrics();
    $allDetections = (int)$db->query("SELECT COUNT(*) FROM retention_detection WHERE id_empresa={$company}")->fetchColumn();
    check('métrica en bandeja excluye el caso ya retornado', (int)$queueMetrics['total'] === $allDetections - 1
        && (int)$queueMetrics['returned'] === 1);
    check('acciones y detecciones quedan auditadas', (int)$db->query(
        "SELECT COUNT(*) FROM log_actividad WHERE id_empresa={$company} AND accion LIKE 'RETENTION_%'"
    )->fetchColumn() >= 4);
} catch (Throwable $error) {
    check('escenario integral Retention', false);
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
} finally {
    if ($demo !== null) DemoGymFactory::cleanup($db);
}
finishTests();
