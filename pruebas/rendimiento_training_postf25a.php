<?php

require_once __DIR__ . '/../tests/bootstrap.php';
require_once __DIR__ . '/../tests/Support/DemoGymFactory.php';
require_once __DIR__ . '/../app/services/TrainingService.php';

$db=Database::getInstance()->getConnection();$demo=null;
/** @param list<float> $values */
function postF25aP95(array $values):float{sort($values);return $values[max(0,(int)ceil(count($values)*0.95)-1)];}
try{
    $demo=DemoGymFactory::create($db);$company=(int)$demo['empresa'];$site=(int)$demo['sedes'][0];
    $actor=(int)$demo['direccion'];$member=(int)$demo['socios'][0];$service=new TrainingService($db,$company,$site,'direccion',$actor);
    $exercise=$service->createExercise(['name'=>'Escala base','discipline'=>'GYM','difficulty'=>'INICIAL','execution_type'=>'REPS']);
    $items=[];for($i=1;$i<=100;$i++)$items[]=['exercise_id'=>$exercise,'execution_type'=>'REPS','item_order'=>$i,'sets_count'=>4,'reps_count'=>8,'load_kg'=>'40','rest_seconds'=>60];
    $template=$service->createTemplate(['name'=>'Escala plantilla ejecutable','objective'=>'GENERAL','level'=>'TODOS','days_per_week'=>1,'status'=>'ACTIVE','disciplines'=>['GYM']],[
        ['name'=>'Día escala','day_order'=>1,'blocks'=>[['name'=>'Bloque escala','block_type'=>'GENERAL','block_order'=>1,'exercises'=>$items]]],
    ]);
    $plan=$service->createPlanFromTemplate($template,$member,['start_date'=>'2026-08-24']);$service->assignPlan($plan,'post-f25a-scale-assign-0001');
    $planItems=[];foreach($service->plan($plan)['days'][0]['blocks'][0]['exercises'] as $item)$planItems[]=(int)$item['id_training_plan_exercise'];

    $exerciseInsert=$db->prepare("INSERT INTO training_exercise (id_empresa,name,slug,discipline,difficulty,execution_type,created_by) VALUES (:company,:name,:slug,'GYM','INICIAL','REPS',:actor)");
    $templateInsert=$db->prepare("INSERT INTO training_template (id_empresa,name,slug,objective,level,days_per_week,status,created_by) VALUES (:company,:name,:slug,'GENERAL','TODOS',1,'DRAFT',:actor)");
    $planInsert=$db->prepare("INSERT INTO training_plan (id_empresa,id_gimnasio,id_socio,created_by,name,objective,start_date,status) VALUES (:company,:site,:member,:actor,:name,'GENERAL','2026-08-24','DRAFT')");
    $db->beginTransaction();
    for($i=1;$i<=5000;$i++)$exerciseInsert->execute([':company'=>$company,':name'=>sprintf('Scale exercise %05d',$i),':slug'=>sprintf('scale-exercise-%05d',$i),':actor'=>$actor]);
    for($i=1;$i<=1000;$i++)$templateInsert->execute([':company'=>$company,':name'=>sprintf('Scale template %04d',$i),':slug'=>sprintf('scale-template-%04d',$i),':actor'=>$actor]);
    for($i=1;$i<=5000;$i++)$planInsert->execute([':company'=>$company,':site'=>$site,':member'=>$member,':actor'=>$actor,':name'=>sprintf('Scale plan %05d',$i)]);
    $sessionInsert=$db->prepare("INSERT INTO training_session (id_training_plan,id_training_plan_day,id_empresa,id_gimnasio,id_socio,session_date,status,idempotency_key,completed_at_utc) VALUES (:plan,:day,:company,:site,:member,:date,'COMPLETED',:key,UTC_TIMESTAMP())");
    $resultInsert=$db->prepare('INSERT INTO training_session_exercise (id_training_session,id_empresa,id_gimnasio,id_socio,id_training_plan_exercise,completed,actual_reps,actual_load_kg) VALUES (?,?,?,?,?,1,8,40.000)');
    $day=(int)$service->plan($plan)['days'][0]['id_training_plan_day'];
    for($s=1;$s<=1000;$s++){
        $date=(new DateTimeImmutable('2023-01-01'))->modify('+'.$s.' days')->format('Y-m-d');
        $sessionInsert->execute([':plan'=>$plan,':day'=>$day,':company'=>$company,':site'=>$site,':member'=>$member,':date'=>$date,':key'=>hash('sha256','post-f25a-scale-session-'.$s)]);
        $session=(int)$db->lastInsertId();foreach($planItems as $item)$resultInsert->execute([$session,$company,$site,$member,$item]);
    }
    $db->commit();

    $library=[];$templates=[];$plans=[];$history=[];
    for($i=0;$i<10;$i++){
        $start=hrtime(true);$service->listExercises('Scale exercise 049','GYM',1,25);$library[]=(hrtime(true)-$start)/1_000_000;
        $start=hrtime(true);$service->listTemplates();$templates[]=(hrtime(true)-$start)/1_000_000;
        $start=hrtime(true);$service->listPlans();$plans[]=(hrtime(true)-$start)/1_000_000;
        $start=hrtime(true);$loaded=$service->plan($plan);$history[]=(hrtime(true)-$start)/1_000_000;
    }
    $cloneStart=hrtime(true);$clone=$service->createPlanFromTemplate($template,$member,['name'=>'Scale clone','start_date'=>'2026-09-01']);$cloneMs=(hrtime(true)-$cloneStart)/1_000_000;
    $assignStart=hrtime(true);$service->assignPlan($clone,'post-f25a-scale-assign-0002');$assignMs=(hrtime(true)-$assignStart)/1_000_000;
    $size=$db->prepare('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE \'training\\_%\'');$size->execute();
    $bytes=(int)$size->fetchColumn();
    check('dataset pesado contiene 5.000 ejercicios privados',(int)$db->query("SELECT COUNT(*) FROM training_exercise WHERE id_empresa={$company}")->fetchColumn()>=5001);
    check('dataset pesado contiene 1.000 plantillas adicionales',(int)$db->query("SELECT COUNT(*) FROM training_template WHERE id_empresa={$company}")->fetchColumn()>=1001);
    check('dataset pesado contiene 5.000 planes adicionales',(int)$db->query("SELECT COUNT(*) FROM training_plan WHERE id_empresa={$company}")->fetchColumn()>=5001);
    check('dataset pesado contiene 100.000 resultados de sesión',(int)$db->query("SELECT COUNT(*) FROM training_session_exercise WHERE id_empresa={$company}")->fetchColumn()===100000);
    check('historial se limita a 200 sesiones',count($loaded['sessions'])===200);
    check('operaciones medidas completan sin error',$clone>0&&postF25aP95($library)<5000&&postF25aP95($templates)<5000&&postF25aP95($plans)<5000&&postF25aP95($history)<5000&&$cloneMs<5000&&$assignMs<5000);
    echo 'METRICA_POST_F25A exercises=5000 templates=1000 plans=5000 session_exercise=100000 '
        .'library_p95_ms='.number_format(postF25aP95($library),2,'.','').' templates_p95_ms='.number_format(postF25aP95($templates),2,'.','')
        .' plans_p95_ms='.number_format(postF25aP95($plans),2,'.','').' history_p95_ms='.number_format(postF25aP95($history),2,'.','')
        .' clone_ms='.number_format($cloneMs,2,'.','').' assign_ms='.number_format($assignMs,2,'.','').' training_bytes='.$bytes.PHP_EOL;
}catch(Throwable $error){if($db->inTransaction())$db->rollBack();check('escala Training post-F25A',false);fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");}
finally{if($demo!==null)DemoGymFactory::cleanup($db);}
finishTests();
