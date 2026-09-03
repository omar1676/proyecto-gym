<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/services/TrainingService.php';

$db = Database::getInstance()->getConnection();
$demo = null;
try {
    $demo = DemoGymFactory::create($db);
    $company = (int)$demo['empresa'];
    $site = (int)$demo['sedes'][0];
    $actor = (int)$demo['direccion'];
    $member = (int)$demo['socios'][0];
    $service = new TrainingService($db,$company,$site,'direccion',$actor);

    $private = $service->createExercise([
        'name'=>'Flexiones F25A','discipline'=>'GYM','short_description'=>'Ejercicio sintético de prueba.',
        'execution_instructions'=>'Mantener alineación estable.','muscle_group'=>'PECHO','equipment'=>'PESO_CORPORAL',
        'difficulty'=>'INICIAL','execution_type'=>'REPS',
    ]);
    check('tenant puede crear ejercicio privado', $private > 0);
    $service->addVideoReference($private,'https://media.example.test/flexiones-f25a',[
        'alt_text'=>'Demostración sintética de flexiones','source'=>'Gimnera test','license'=>'TEST-ONLY',
    ]);
    $catalog = $service->listExercises('', '', 1, 50);
    $bySlug = [];
    foreach ($catalog['items'] as $exercise) $bySlug[$exercise['slug']] = (int)$exercise['id_training_exercise'];
    check('biblioteca combina global y privado', $catalog['total'] === 10 && isset($bySlug['press-banca'],$bySlug['flexiones-f25a']));

    $template = $service->createTemplate([
        'name'=>'Preparación multidisciplina F25A','description'=>'Plantilla sintética profesional.',
        'objective'=>'PREPARACION_FISICA','level'=>'INTERMEDIO','days_per_week'=>3,
        'status'=>'ACTIVE','disciplines'=>['GYM','STRENGTH','BOXEO','MMA','BJJ','CONDITIONING'],
    ], [
        [
            'name'=>'Fuerza','day_order'=>1,'objective'=>'FUERZA','blocks'=>[[
                'name'=>'Fuerza principal','block_type'=>'STRENGTH','block_order'=>1,'exercises'=>[
                    ['exercise_id'=>$bySlug['press-banca'],'execution_type'=>'REPS','item_order'=>1,'sets_count'=>4,'reps_count'=>8,'load_kg'=>'70','rest_seconds'=>120],
                    ['exercise_id'=>$bySlug['sentadilla'],'execution_type'=>'REPS','item_order'=>2,'sets_count'=>5,'reps_count'=>5,'load_kg'=>'100','rest_seconds'=>180],
                    ['exercise_id'=>$private,'execution_type'=>'REPS','item_order'=>3,'sets_count'=>3,'reps_count'=>12,'load_kg'=>'0','rest_seconds'=>60],
                ],
            ]],
        ],
        [
            'name'=>'Técnica de combate','day_order'=>2,'objective'=>'TECNICA','blocks'=>[[
                'name'=>'Boxeo','block_type'=>'TECHNIQUE','block_order'=>1,'exercises'=>[
                    ['exercise_id'=>$bySlug['trabajo-saco'],'execution_type'=>'ROUNDS','item_order'=>1,'rounds_count'=>5,'round_duration_seconds'=>180,'rest_seconds'=>60],
                    ['exercise_id'=>$bySlug['combinacion-jab-directo-crochet-salida'],'execution_type'=>'TECHNIQUE','item_order'=>2,'rounds_count'=>4,'round_duration_seconds'=>120,'rest_seconds'=>45],
                ],
            ],[
                'name'=>'MMA y BJJ','block_type'=>'TECHNIQUE','block_order'=>2,'exercises'=>[
                    ['exercise_id'=>$bySlug['drill-tecnico-mma'],'execution_type'=>'TECHNIQUE','item_order'=>1,'rounds_count'=>4,'round_duration_seconds'=>120,'rest_seconds'=>30],
                    ['exercise_id'=>$bySlug['trabajo-posicional-bjj'],'execution_type'=>'ROUNDS','item_order'=>2,'rounds_count'=>5,'round_duration_seconds'=>240,'rest_seconds'=>60],
                ],
            ]],
        ],
        [
            'name'=>'Circuito','day_order'=>3,'objective'=>'ACONDICIONAMIENTO','blocks'=>[[
                'name'=>'Circuito combate','block_type'=>'CIRCUIT','block_order'=>1,'circuit_rounds'=>4,'round_rest_seconds'=>120,'exercises'=>[
                    ['exercise_id'=>$bySlug['trabajo-saco'],'item_order'=>1,'work_seconds'=>45,'transition_seconds'=>15],
                    ['exercise_id'=>$bySlug['sprawls'],'item_order'=>2,'work_seconds'=>45,'transition_seconds'=>15],
                    ['exercise_id'=>$bySlug['ground-and-pound'],'item_order'=>3,'work_seconds'=>60,'transition_seconds'=>30],
                    ['exercise_id'=>$bySlug['battle-ropes'],'item_order'=>4,'work_seconds'=>45,'transition_seconds'=>0],
                ],
            ]],
        ],
    ]);
    $templateRow = $service->template($template);
    check('plantilla multidisciplina conserva tres días', $templateRow !== null && count($templateRow['days']) === 3);
    check('circuito conserva vueltas y cuatro estaciones',
        (int)$templateRow['days'][2]['blocks'][0]['circuit_rounds'] === 4
        && count($templateRow['days'][2]['blocks'][0]['exercises']) === 4);

    $blankMember=(int)$demo['socios'][1];
    $blank=$service->createBlankPlan([
        'member_id'=>$blankMember,'name'=>'Plan desde cero F25A','objective'=>'GENERAL',
        'start_date'=>'2026-08-24','disciplines'=>['GYM','BOXEO'],
    ]);
    $blankDay=$service->addPlanDay($blank,1,['name'=>'Día manual','day_order'=>1,'objective'=>'GENERAL']);
    $blankBlock=$service->addPlanBlock($blank,$blankDay,2,['name'=>'Bloque manual','block_type'=>'STRENGTH','block_order'=>1]);
    $blankItemA=$service->addPlanExercise($blank,$blankBlock,3,[
        'exercise_id'=>$private,'execution_type'=>'REPS','item_order'=>1,'sets_count'=>4,'reps_count'=>8,'load_kg'=>'70','rest_seconds'=>120,
    ]);
    $blankItemB=$service->addPlanExercise($blank,$blankBlock,4,[
        'exercise_id'=>$bySlug['sentadilla'],'execution_type'=>'REPS','item_order'=>2,'sets_count'=>5,'reps_count'=>5,'load_kg'=>'100','rest_seconds'=>180,
    ]);
    $service->reorderPlanExercises($blankBlock,[$blankItemB,$blankItemA],$blank,5);
    $blankRow=$service->plan($blank);
    check('plan desde cero admite días bloques y ejercicios',count($blankRow['days'])===1&&count($blankRow['days'][0]['blocks'][0]['exercises'])===2);
    check('reorder conserva conjunto y orden determinista',(int)$blankRow['days'][0]['blocks'][0]['exercises'][0]['id_training_plan_exercise']===$blankItemB);
    check('reorder no deja valores temporales persistidos',(int)$db->query(
        "SELECT MAX(item_order) FROM training_plan_exercise WHERE id_training_plan_block={$blankBlock}"
    )->fetchColumn()===2);
    check('plan manual conserva snapshot de referencias visuales',(int)$db->query(
        "SELECT COUNT(*) FROM training_plan_exercise_media WHERE id_training_plan_exercise={$blankItemA}"
    )->fetchColumn()===1);
    $staleBuilder=false;
    try{$service->addPlanDay($blank,5,['name'=>'Día obsoleto','day_order'=>2]);}catch(DomainException){$staleBuilder=true;}
    check('builder rechaza versión obsoleta del plan',$staleBuilder);

    $plan = $service->createPlanFromTemplate($template,$member,[
        'name'=>'Plan MMA competición demo','start_date'=>'2026-08-24','notes'=>'Sin información clínica.',
    ]);
    $planRow = $service->plan($plan);
    check('clonado crea snapshot estructurado independiente', $planRow !== null && count($planRow['days'])===3
        && count($planRow['days'][0]['blocks'][0]['exercises'])===3);
    $snapshotName = (string)$planRow['days'][0]['blocks'][0]['exercises'][2]['exercise_name'];
    $service->updateExercise($private,1,[
        'name'=>'Flexiones F25A modificadas','discipline'=>'GYM','short_description'=>'Cambio de biblioteca.',
        'execution_instructions'=>'Instrucción posterior.','muscle_group'=>'PECHO','equipment'=>'PESO_CORPORAL',
        'difficulty'=>'INICIAL','execution_type'=>'REPS',
    ]);
    check('cambio de biblioteca no muta plan clonado',
        $snapshotName === 'Flexiones F25A' && $service->plan($plan)['days'][0]['blocks'][0]['exercises'][2]['exercise_name'] === 'Flexiones F25A');

    $assignmentA = $service->assignPlan($plan,'f25a-assignment-idempotent-0001');
    $assignmentRetry = $service->assignPlan($plan,'f25a-assignment-idempotent-0001');
    check('asignación es idempotente', $assignmentA === $assignmentRetry);
    $planTwo = $service->createPlanFromTemplate($template,$member,['name'=>'Segundo plan demo','start_date'=>'2026-09-01']);
    $assignmentB = $service->assignPlan($planTwo,'f25a-assignment-idempotent-0002');
    check('solo existe un plan principal por socio', $assignmentB > $assignmentA && (int)$db->query(
        "SELECT COUNT(*) FROM training_assignment WHERE id_empresa={$company} AND id_socio={$member} AND status='ACTIVE'"
    )->fetchColumn() === 1);
    check('plan principal sustituido pasa a histórico sin borrarse',(string)$db->query(
        "SELECT status FROM training_plan WHERE id_training_plan={$plan}"
    )->fetchColumn()==='ARCHIVED' && $service->plan($plan)!==null);

    $planTwoRow = $service->plan($planTwo);
    $item = $planTwoRow['days'][0]['blocks'][0]['exercises'][0];
    $service->updatePlanExercise((int)$item['id_training_plan_exercise'],(int)$item['version'],[
        'execution_type'=>'REPS','sets_count'=>4,'reps_count'=>6,'load_kg'=>'72.5','rest_seconds'=>120,'notes'=>'Progresión manual.',
    ]);
    $stale = false;
    try {
        $service->updatePlanExercise((int)$item['id_training_plan_exercise'],(int)$item['version'],[
            'execution_type'=>'REPS','sets_count'=>9,'reps_count'=>9,'load_kg'=>'99','rest_seconds'=>1,
        ]);
    } catch (DomainException) { $stale = true; }
    check('edición concurrente obsoleta se rechaza', $stale);

    $dayTwo = (int)$planTwoRow['days'][0]['id_training_plan_day'];
    $session = $service->createSession($planTwo,$dayTwo,'2026-09-02','f25a-session-idempotent-000001');
    check('creación de sesión es idempotente', $session === $service->createSession(
        $planTwo,$dayTwo,'2026-09-02','f25a-session-idempotent-000001'
    ));
    $foreignItem = (int)$service->plan($plan)['days'][0]['blocks'][0]['exercises'][0]['id_training_plan_exercise'];
    $foreignRejected = false;
    try {
        $service->finishSession($session,1,'COMPLETED',[[
            'plan_exercise_id'=>$foreignItem,'completed'=>1,'actual_reps'=>8,
        ]]);
    } catch (DomainException) { $foreignRejected = true; }
    check('resultado de otro plan queda rechazado y hace rollback', $foreignRejected && (string)$db->query(
        "SELECT status FROM training_session WHERE id_training_session={$session}"
    )->fetchColumn() === 'PENDING');
    $validItem = (int)$service->plan($planTwo)['days'][0]['blocks'][0]['exercises'][0]['id_training_plan_exercise'];
    $service->finishSession($session,1,'COMPLETED',[[
        'plan_exercise_id'=>$validItem,'completed'=>1,'actual_reps'=>6,'actual_load_kg'=>'72.500','notes'=>'Resultado sintético.',
    ]]);
    check('sesión completa conserva resultado opcional', (int)$db->query(
        "SELECT COUNT(*) FROM training_session_exercise WHERE id_training_session={$session} AND completed=1"
    )->fetchColumn() === 1);
    check('historial de sesión queda visible en el plan',count($service->plan($planTwo)['sessions'])===1);
    $doubleFinish=false;
    try { $service->finishSession($session,1,'COMPLETED'); } catch (DomainException) { $doubleFinish=true; }
    check('doble finalización queda rechazada', $doubleFinish);

    $summary=$service->memberSummary($member);
    check('perfil del socio resume su plan principal', $summary!==null && (int)$summary['id_training_plan']===$planTwo);
    check('operaciones relevantes quedan auditadas', (int)$db->query(
        "SELECT COUNT(*) FROM log_actividad WHERE id_empresa={$company} AND accion LIKE 'TRAINING_%'"
    )->fetchColumn() >= 10);

    $db->exec("UPDATE empresa SET estado='inactiva' WHERE id_empresa={$company}");
    $blocked=false;
    try { $service->createExercise(['name'=>'Bloqueado','discipline'=>'GENERAL','difficulty'=>'INICIAL','execution_type'=>'TIME']); }
    catch (DomainException) { $blocked=true; }
    check('tenant inactivo no recibe escrituras Training', $blocked);
    $db->exec("UPDATE empresa SET estado='activa' WHERE id_empresa={$company}");
} catch (Throwable $error) {
    check('foundation Training completa', false);
    fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");
} finally {
    if ($demo !== null) DemoGymFactory::cleanup($db);
}

finishTests();
