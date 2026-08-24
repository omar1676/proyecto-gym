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
}catch(Throwable $error){if($db->inTransaction())$db->rollBack();check('performance Training',false);fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");}
finally{if($demo!==null)DemoGymFactory::cleanup($db);}
finishTests();
