<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/helpers/TrainingMediaStorage.php';
require_once dirname(__DIR__, 2) . '/app/services/TrainingService.php';

$db = Database::getInstance()->getConnection();
$demo = null;
$storedKey = null;
try {
    $demo = DemoGymFactory::create($db);
    $company = (int) $demo['empresa'];
    $site = (int) $demo['sedes'][0];
    $actor = (int) $demo['direccion'];
    $member = (int) $demo['socios'][0];
    $service = new TrainingService($db, $company, $site, 'direccion', $actor);

    $emptyTemplate = $service->createTemplate([
        'name' => 'Plantilla vacía hostil', 'objective' => 'GENERAL', 'level' => 'TODOS',
        'days_per_week' => 1, 'status' => 'ACTIVE', 'disciplines' => ['GENERAL'],
    ]);
    $emptyCloneRejected = false;
    try {
        $service->createPlanFromTemplate($emptyTemplate, $member, ['start_date' => '2026-08-24']);
    } catch (DomainException) {
        $emptyCloneRejected = true;
    }
    check('plantilla vacía no puede clonarse como plan ejecutable', $emptyCloneRejected);

    $blankPlan = $service->createBlankPlan([
        'member_id' => $member, 'name' => 'Plan vacío hostil', 'objective' => 'GENERAL',
        'start_date' => '2026-08-24', 'disciplines' => ['GENERAL'],
    ]);
    $blankAssignmentRejected = false;
    try {
        $service->assignPlan($blankPlan, 'post-f25a-empty-assignment-0001');
    } catch (DomainException) {
        $blankAssignmentRejected = true;
    }
    check('plan vacío no puede convertirse en principal', $blankAssignmentRejected);
    check('rechazo de plan vacío no deja asignación parcial', (int) $db->query(
        "SELECT COUNT(*) FROM training_assignment WHERE id_training_plan={$blankPlan}"
    )->fetchColumn() === 0);

    $exercise = $service->createExercise([
        'name' => 'Press hostil', 'discipline' => 'STRENGTH', 'difficulty' => 'INTERMEDIO',
        'execution_type' => 'REPS', 'execution_instructions' => 'Contenido sintético.',
    ]);
    $template = $service->createTemplate([
        'name' => 'Plantilla válida hostil', 'objective' => 'FUERZA', 'level' => 'INTERMEDIO',
        'days_per_week' => 1, 'status' => 'ACTIVE', 'disciplines' => ['STRENGTH'],
    ], [[
        'name' => 'Día 1', 'day_order' => 1, 'blocks' => [[
            'name' => 'Fuerza', 'block_type' => 'STRENGTH', 'block_order' => 1, 'exercises' => [[
                'exercise_id' => $exercise, 'execution_type' => 'REPS', 'item_order' => 1,
                'sets_count' => 4, 'reps_count' => 8, 'load_kg' => '70', 'rest_seconds' => 120,
            ]],
        ]],
    ]]);
    $plan = $service->createPlanFromTemplate($template, $member, ['start_date' => '2026-08-24']);
    $day = (int) $service->plan($plan)['days'][0]['id_training_plan_day'];
    $sessionBeforeAssignmentRejected = false;
    try {
        $service->createSession($plan, $day, '2026-08-25', 'post-f25a-unassigned-session-01');
    } catch (DomainException) {
        $sessionBeforeAssignmentRejected = true;
    }
    check('plan no asignado no puede generar sesión', $sessionBeforeAssignmentRejected);

    $service->assignPlan($plan, 'post-f25a-valid-assignment-0001');
    $secondPlan = $service->createPlanFromTemplate($template, $member, ['name'=>'Otro plan idempotencia','start_date'=>'2026-08-24']);
    $assignmentConflict=false;
    try { $service->assignPlan($secondPlan, 'post-f25a-valid-assignment-0001'); }
    catch (DomainException) { $assignmentConflict=true; }
    check('clave de asignación no puede reutilizarse con otro plan', $assignmentConflict);
    $session = $service->createSession($plan, $day, '2026-08-25', 'post-f25a-assigned-session-0001');
    $sessionConflict=false;
    try { $service->createSession($plan, $day, '2026-08-27', 'post-f25a-assigned-session-0001'); }
    catch (DomainException) { $sessionConflict=true; }
    check('clave de sesión no puede reutilizarse con otra fecha', $sessionConflict);
    $item = (int) $service->plan($plan)['days'][0]['blocks'][0]['exercises'][0]['id_training_plan_exercise'];

    $invalidMetricRejected = false;
    try {
        $service->finishSession($session, 1, 'COMPLETED', [[
            'plan_exercise_id' => $item, 'completed' => 1, 'actual_duration_seconds' => 90,
        ]]);
    } catch (InvalidArgumentException|DomainException) {
        $invalidMetricRejected = true;
    }
    check('REPS rechaza duración real incompatible', $invalidMetricRejected);
    check('métrica incompatible hace rollback de sesión', (string) $db->query(
        "SELECT status FROM training_session WHERE id_training_session={$session}"
    )->fetchColumn() === 'PENDING');

    $booleanSession = $service->createSession($plan, $day, '2026-08-26', 'post-f25a-boolean-session-0001');
    $invalidBooleanRejected = false;
    try {
        $service->finishSession($booleanSession, 1, 'COMPLETED', [[
            'plan_exercise_id' => $item, 'completed' => 'yes', 'actual_reps' => 8,
        ]]);
    } catch (InvalidArgumentException|DomainException) {
        $invalidBooleanRejected = true;
    }
    check('completed exige booleano controlado', $invalidBooleanRejected);

    $png = tempnam(sys_get_temp_dir(), 'post_f25a_media_');
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    $mediaId = $service->addImageMedia($exercise, [
        'error' => UPLOAD_ERR_OK, 'tmp_name' => $png, 'name' => 'hostile.png', 'size' => filesize($png),
    ], ['alt_text' => 'Imagen sintética hostil', 'source' => 'Gimnera test', 'license' => 'TEST-ONLY']);
    @unlink($png);
    $media = $service->media($mediaId);
    $storedKey = (string) ($media['storage_key'] ?? '');
    check('almacenamiento expone borrado protegido por referencias', method_exists(TrainingMediaStorage::class, 'deleteIfUnreferenced'));
    if (method_exists(TrainingMediaStorage::class, 'deleteIfUnreferenced')) {
        check('medio referenciado por biblioteca no puede borrarse físicamente',
            TrainingMediaStorage::deleteIfUnreferenced($db, $storedKey) === false
            && TrainingMediaStorage::resolve($storedKey) !== null);
        $planWithMedia=$service->createPlanFromTemplate($template,$member,['name'=>'Snapshot de medio','start_date'=>'2026-08-28']);
        $planMediaCount=(int)$db->query(
            "SELECT COUNT(*) FROM training_plan_exercise_media pm JOIN training_plan_exercise pe "
            ."ON pe.id_training_plan_exercise=pm.id_training_plan_exercise JOIN training_plan_block pb "
            ."ON pb.id_training_plan_block=pe.id_training_plan_block JOIN training_plan_day pd "
            ."ON pd.id_training_plan_day=pb.id_training_plan_day WHERE pd.id_training_plan={$planWithMedia}"
        )->fetchColumn();
        check('plan posterior conserva snapshot de medio', $planMediaCount === 1);
        $snapshotMediaId=(int)$db->query(
            "SELECT MIN(pm.id_training_plan_exercise_media) FROM training_plan_exercise_media pm JOIN training_plan_exercise pe "
            ."ON pe.id_training_plan_exercise=pm.id_training_plan_exercise JOIN training_plan_block pb "
            ."ON pb.id_training_plan_block=pe.id_training_plan_block JOIN training_plan_day pd "
            ."ON pd.id_training_plan_day=pb.id_training_plan_day WHERE pd.id_training_plan={$planWithMedia}"
        )->fetchColumn();
        $db->exec("DELETE FROM training_exercise_media WHERE id_training_exercise_media={$mediaId}");
        check('baja lógica de referencia fuente no rompe fichero histórico',
            TrainingMediaStorage::deleteIfUnreferenced($db,$storedKey)===false
            && TrainingMediaStorage::resolve($storedKey)!==null);
        check('snapshot histórico sigue sirviendo metadata autorizada',$service->planMedia($snapshotMediaId)!==null);
        $otherMemberService=new TrainingService($db,$company,$site,'socio',(int)$demo['socios'][1]);
        check('otro socio no puede leer medio del snapshot',$otherMemberService->planMedia($snapshotMediaId)===null);
        $ownerService=new TrainingService($db,$company,$site,'socio',$member);
        check('socio propietario puede leer medio de su snapshot',$ownerService->planMedia($snapshotMediaId)!==null);
        $wrongSiteAdmin=new TrainingService($db,$company,(int)$demo['sedes'][1],'admin',$actor);
        check('admin de otra sede no puede leer medio del snapshot',$wrongSiteAdmin->planMedia($snapshotMediaId)===null);
    }
} catch (Throwable $error) {
    check('integridad hostil Training completa', false);
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
} finally {
    if ($demo !== null) DemoGymFactory::cleanup($db);
    if ($storedKey !== null && method_exists(TrainingMediaStorage::class, 'deleteIfUnreferenced')) {
        TrainingMediaStorage::deleteIfUnreferenced($db, $storedKey);
    }
}

finishTests();
