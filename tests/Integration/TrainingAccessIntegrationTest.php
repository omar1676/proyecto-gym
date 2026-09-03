<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/services/TrainingService.php';
require_once dirname(__DIR__, 2) . '/app/services/AccessPolicyService.php';

$db = Database::getInstance()->getConnection();
$demo = null;
$barrier = sys_get_temp_dir() . '/gimnera-training-access-' . bin2hex(random_bytes(8));

try {
    $demo = DemoGymFactory::create($db);
    $company = (int) $demo['empresa'];
    $site = (int) $demo['sedes'][0];
    $actor = (int) $demo['direccion'];
    $member = (int) $demo['socios'][0];

    $training = new TrainingService($db, $company, $site, 'direccion', $actor);
    $exercise = $training->createExercise([
        'name'=>'Integración Training Access', 'discipline'=>'STRENGTH',
        'difficulty'=>'INTERMEDIO', 'execution_type'=>'REPS',
    ]);
    $template = $training->createTemplate([
        'name'=>'Plantilla integración', 'objective'=>'FUERZA', 'level'=>'INTERMEDIO',
        'days_per_week'=>1, 'status'=>'ACTIVE', 'disciplines'=>['STRENGTH'],
    ], [[
        'name'=>'Día conjunto', 'day_order'=>1, 'blocks'=>[[
            'name'=>'Fuerza', 'block_type'=>'STRENGTH', 'block_order'=>1, 'exercises'=>[[
                'exercise_id'=>$exercise, 'execution_type'=>'REPS', 'item_order'=>1,
                'sets_count'=>4, 'reps_count'=>8, 'load_kg'=>'70', 'rest_seconds'=>120,
            ]],
        ]],
    ]]);
    $plan = $training->createPlanFromTemplate($template, $member, [
        'name'=>'Plan conjunto', 'start_date'=>'2026-08-25',
    ]);
    $assignment = $training->assignPlan($plan, 'integration-training-access-assignment-01');
    $planBefore = $training->plan($plan);
    $item = $planBefore['days'][0]['blocks'][0]['exercises'][0];

    $now = new DateTimeImmutable('2026-08-25 10:00:00', new DateTimeZone('UTC'));
    $clock = static fn(): DateTimeImmutable => $now;
    $eligibility = static fn(int $memberId): array => [
        'estado'=>'BLOQUEADO', 'reason_code'=>'NO_ACTIVE_MEMBERSHIP', 'motivo'=>'Sintético',
    ];
    $access = new AccessPolicyService($db, $company, $site, $actor, 'direccion', $clock, $eligibility, 3);
    $temporary = $access->grantTemporary(
        $member, $now, $now->modify('+1 day'), 'TEMPORARY_VISIT', null,
        str_repeat('a', 32), 0
    );

    check('mismo socio conserva plan y política en dominios separados',
        $assignment > 0 && $temporary['policy']['state'] === 'TEMPORARY'
        && (int) $training->memberSummary($member)['id_training_plan'] === $plan);
    check('provider disabled no sustituye el estado de política',
        $access->canAccess($member)['policy_state'] === 'TEMPORARY'
        && $access->canAccess($member)['provider_sync_state'] === 'DISABLED');

    $trainingWorker = dirname(__DIR__) . '/Support/training_concurrency_worker.php';
    $accessWorker = dirname(__DIR__) . '/Support/access_policy_concurrency_worker.php';
    $commands = [
        [PHP_BINARY, $trainingWorker, 'update', $barrier, (string)$company, (string)$site,
            (string)$actor, (string)$item['id_training_plan_exercise'], (string)$item['version'], '72.5'],
        [PHP_BINARY, $accessWorker, $barrier, 'permanent', (string)$company, (string)$site,
            (string)$member, (string)$actor, 'direccion', (string)$temporary['policy']['version'],
            str_repeat('b', 32)],
    ];
    $running = [];
    foreach ($commands as $command) {
        $spec = [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']];
        $process = proc_open($command, $spec, $pipes, dirname(__DIR__, 2), null, ['bypass_shell'=>true]);
        if (is_resource($process)) {
            fclose($pipes[0]);
            $running[] = [$process, $pipes];
        }
    }
    touch($barrier);
    $results = [];
    foreach ($running as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $results[] = ['exit'=>proc_close($process), 'stdout'=>$stdout, 'stderr'=>$stderr];
    }

    check('edición Training y bloqueo Access arrancan en procesos independientes', count($results) === 2);
    check('ambos dominios confirman sin pisarse',
        count(array_filter($results, static fn(array $result): bool => $result['exit'] === 0)) === 2);
    check('edición Training conserva optimistic locking', (int)$db->query(
        'SELECT version FROM training_plan_exercise WHERE id_training_plan_exercise=' . (int)$item['id_training_plan_exercise']
    )->fetchColumn() === 2);
    check('bloqueo Access conserva su versión y estado',
        $access->current($member)['state'] === 'PERMANENT_BLOCK'
        && (int)$access->current($member)['version'] === 2);
    check('bloqueo Access no elimina plan ni asignación Training',
        $training->plan($plan) !== null
        && (int)$db->query("SELECT COUNT(*) FROM training_assignment WHERE id_training_assignment={$assignment}")->fetchColumn() === 1);
    check('historial de ambos módulos permanece separado',
        (int)$db->query("SELECT COUNT(*) FROM log_actividad WHERE id_empresa={$company} AND accion LIKE 'TRAINING_%'")->fetchColumn() >= 4
        && (int)$db->query("SELECT COUNT(*) FROM access_policy_event WHERE id_empresa={$company} AND id_socio={$member}")->fetchColumn() === 2);
    check('workers no filtran errores internos', count(array_filter(
        $results,
        static fn(array $result): bool => preg_match('/password|SQLSTATE|Fatal error|Uncaught/i', $result['stderr']) === 1
    )) === 0);

    check('RBAC diferencial no concede diseño Training a recepción',
        Authorization::can('recepcion', 'access.temporary')
        && !Authorization::can('recepcion', 'access.deny')
        && !Authorization::can('recepcion', 'training.manage'));
} catch (Throwable $error) {
    check('integración Training y Access completa', false);
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
} finally {
    @unlink($barrier);
    if ($demo !== null) {
        DemoGymFactory::cleanup($db);
    }
}

finishTests();
