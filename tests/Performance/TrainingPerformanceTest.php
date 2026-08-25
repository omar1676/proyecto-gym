<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/services/TrainingService.php';

$db=Database::getInstance()->getConnection();$demo=null;
try{
    $demo=DemoGymFactory::create($db);$company=(int)$demo['empresa'];$site=(int)$demo['sedes'][0];$actor=(int)$demo['direccion'];
    $db->beginTransaction();$insert=$db->prepare(
        "INSERT INTO training_exercise (id_empresa,name,slug,discipline,difficulty,execution_type,created_by) VALUES (:company,:name,:slug,'GYM','INICIAL','REPS',:actor)"
    );
    for($i=1;$i<=5000;$i++)$insert->execute([':company'=>$company,':name'=>sprintf('Ejercicio carga %05d',$i),':slug'=>sprintf('ejercicio-carga-%05d',$i),':actor'=>$actor]);
    $db->commit();$service=new TrainingService($db,$company,$site,'direccion',$actor);
    $durations=[];
    for($i=0;$i<20;$i++){$start=hrtime(true);$page=$service->listExercises('Ejercicio carga 049', 'GYM',1,25);$durations[]=(hrtime(true)-$start)/1_000_000;}
    sort($durations);$p95=$durations[(int)ceil(count($durations)*0.95)-1];
    check('búsqueda en 5.000 ejercicios devuelve solo el tenant', $page['total']===100);
    check('listado grande conserva paginación',count($page['items'])===25&&$page['pages']===4);
    check('p95 sintético de búsqueda queda bajo 1.500 ms',$p95<1500);
    $explain=$db->prepare("EXPLAIN SELECT id_training_exercise FROM training_exercise WHERE (id_empresa=:company OR id_empresa IS NULL) AND active=1 AND discipline='GYM' ORDER BY catalog_scope,name LIMIT 25");
    $explain->execute([':company'=>$company]);$rows=$explain->fetchAll(PDO::FETCH_ASSOC);
    check('consulta crítica puede explicarse sin error',$rows!==[]);
    echo '  INFO p95_busqueda_ms='.number_format($p95,3,'.','').' dataset_privado=5000'.PHP_EOL;

    $member=(int)$demo['socios'][0];
    $plan=$service->createBlankPlan(['member_id'=>$member,'name'=>'Plan grande query audit','objective'=>'GENERAL','start_date'=>'2026-08-24','disciplines'=>['GYM']]);
    $exercise=(int)$db->query("SELECT MIN(id_training_exercise) FROM training_exercise WHERE id_empresa={$company}")->fetchColumn();
    $insertDay=$db->prepare('INSERT INTO training_plan_day (id_training_plan,id_empresa,id_gimnasio,id_socio,name,day_order) VALUES (?,?,?,?,?,?)');
    $insertBlock=$db->prepare("INSERT INTO training_plan_block (id_training_plan_day,id_empresa,id_gimnasio,id_socio,name,block_type,block_order) VALUES (?,?,?,?,?,'GENERAL',?)");
    $insertItem=$db->prepare("INSERT INTO training_plan_exercise (id_training_plan_block,id_empresa,id_gimnasio,id_socio,source_exercise_id,exercise_name,discipline,instructions,execution_type,item_order,sets_count,reps_count,rest_seconds) VALUES (?,?,?,?,?,'Carga sintética','GYM',NULL,'REPS',?,4,8,60)");
    $db->beginTransaction();
    for($day=1;$day<=7;$day++){
        $insertDay->execute([$plan,$company,$site,$member,'Día '.$day,$day]);$dayId=(int)$db->lastInsertId();
        for($block=1;$block<=10;$block++){
            $insertBlock->execute([$dayId,$company,$site,$member,'Bloque '.$block,$block]);$blockId=(int)$db->lastInsertId();
            for($item=1;$item<=10;$item++)$insertItem->execute([$blockId,$company,$site,$member,$exercise,$item]);
        }
    }
    $db->commit();
    $questionsBefore=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'];
    $largeStart=hrtime(true);$large=$service->plan($plan);$largeMs=(hrtime(true)-$largeStart)/1_000_000;
    $questionsAfter=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'];
    $queryDelta=$questionsAfter-$questionsBefore-1;
    $itemCount=0;foreach($large['days'] as $day)foreach($day['blocks'] as $block)$itemCount+=count($block['exercises']);
    check('plan 7x10x10 carga 700 ejercicios completos',$itemCount===700);
    check('carga de plan grande no tiene N+1',$queryDelta<=8);
    check('plan grande sintético carga bajo 1.500 ms',$largeMs<1500);
    echo '  INFO plan_grande_ms='.number_format($largeMs,3,'.','').' queries='.$queryDelta.' items='.$itemCount.PHP_EOL;
}catch(Throwable $error){if($db->inTransaction())$db->rollBack();check('performance Training',false);fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");}
finally{if($demo!==null)DemoGymFactory::cleanup($db);}
finishTests();
