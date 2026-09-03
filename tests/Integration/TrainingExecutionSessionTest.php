<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/services/TrainingService.php';

$db=Database::getInstance()->getConnection();$demo=null;
try{
    $demo=DemoGymFactory::create($db);$company=(int)$demo['empresa'];$site=(int)$demo['sedes'][0];
    $actor=(int)$demo['direccion'];$member=(int)$demo['socios'][0];
    $service=new TrainingService($db,$company,$site,'direccion',$actor);
    $types=['REPS','TIME','ROUNDS','DISTANCE','TECHNIQUE','CIRCUIT'];$ids=[];
    foreach($types as $type)$ids[$type]=$service->createExercise([
        'name'=>'Ejecución '.$type.' sintética','discipline'=>$type==='TECHNIQUE'?'MMA':'GENERAL',
        'difficulty'=>'INTERMEDIO','execution_type'=>$type,
    ]);
    $items=[
        ['exercise_id'=>$ids['REPS'],'execution_type'=>'REPS','item_order'=>1,'sets_count'=>4,'reps_count'=>8,'load_kg'=>'70','rest_seconds'=>120],
        ['exercise_id'=>$ids['TIME'],'execution_type'=>'TIME','item_order'=>2,'sets_count'=>3,'duration_seconds'=>60,'rest_seconds'=>30],
        ['exercise_id'=>$ids['ROUNDS'],'execution_type'=>'ROUNDS','item_order'=>3,'rounds_count'=>5,'round_duration_seconds'=>180,'rest_seconds'=>60],
        ['exercise_id'=>$ids['DISTANCE'],'execution_type'=>'DISTANCE','item_order'=>4,'sets_count'=>2,'distance_value'=>'1500','distance_unit'=>'M','rest_seconds'=>90],
        ['exercise_id'=>$ids['TECHNIQUE'],'execution_type'=>'TECHNIQUE','item_order'=>5,'rounds_count'=>4,'round_duration_seconds'=>120,'rest_seconds'=>45],
    ];
    $template=$service->createTemplate([
        'name'=>'Todos los tipos ejecutables','objective'=>'GENERAL','level'=>'TODOS','days_per_week'=>1,
        'status'=>'ACTIVE','disciplines'=>['GENERAL','MMA'],
    ],[['name'=>'Mixto','day_order'=>1,'blocks'=>[
        ['name'=>'Trabajo principal','block_type'=>'GENERAL','block_order'=>1,'exercises'=>$items],
        ['name'=>'Circuito','block_type'=>'CIRCUIT','block_order'=>2,'circuit_rounds'=>4,'round_rest_seconds'=>120,'exercises'=>[
            ['exercise_id'=>$ids['CIRCUIT'],'item_order'=>1,'work_seconds'=>45,'transition_seconds'=>15],
        ]],
    ]]]);
    $plan=$service->createPlanFromTemplate($template,$member,['start_date'=>'2026-08-24']);
    $row=$service->plan($plan);$persisted=[];
    foreach($row['days'][0]['blocks'] as $block)foreach($block['exercises'] as $item)$persisted[$item['execution_type']]=$item;
    check('los seis tipos persisten en un plan mixto',count($persisted)===6);
    check('DISTANCE conserva valor y unidad controlada',(string)$persisted['DISTANCE']['distance_value']==='1500.00'&&$persisted['DISTANCE']['distance_unit']==='M');
    check('CIRCUIT conserva trabajo y transición',(int)$persisted['CIRCUIT']['work_seconds']===45&&(int)$persisted['CIRCUIT']['transition_seconds']===15);
    $service->assignPlan($plan,'post-f25a-all-types-assign-01');
    $day=(int)$row['days'][0]['id_training_plan_day'];
    $session=$service->createSession($plan,$day,'2026-08-29','post-f25a-all-types-session-01');
    $results=[];
    foreach($persisted as $type=>$item){
        $result=['plan_exercise_id'=>(int)$item['id_training_plan_exercise'],'completed'=>1];
        if($type==='REPS')$result+=['actual_reps'=>8,'actual_load_kg'=>'70'];
        elseif($type==='TIME'||$type==='DISTANCE')$result+=['actual_duration_seconds'=>60];
        else $result+=['actual_rounds'=>4,'actual_duration_seconds'=>120];
        $results[]=$result;
    }
    $service->finishSession($session,1,'COMPLETED',$results,'Resultados exclusivamente sintéticos.');
    check('sesión multidisciplina registra los seis resultados',(int)$db->query(
        "SELECT COUNT(*) FROM training_session_exercise WHERE id_training_session={$session}"
    )->fetchColumn()===6);
}catch(Throwable $error){check('ejecución multidisciplina completa',false);fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");}
finally{if($demo!==null)DemoGymFactory::cleanup($db);}
finishTests();
