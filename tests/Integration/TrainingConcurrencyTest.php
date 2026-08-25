<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/services/TrainingService.php';

$db=Database::getInstance()->getConnection();$demo=null;$barriers=[];
/** @return list<array{exit:int,data:mixed,stderr:string}> */
function f25aTrainingWorkers(string $mode,string $barrier,array $args,array $lastValues):array{
    $worker=dirname(__DIR__).'/Support/training_concurrency_worker.php';$running=[];
    foreach($lastValues as $value){
        $command=array_merge([PHP_BINARY,$worker,$mode,$barrier],array_map('strval',array_merge($args,[$value])));
        $spec=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $proc=proc_open($command,$spec,$pipes,dirname(__DIR__,2),null,['bypass_shell'=>true]);
        if(is_resource($proc)){fclose($pipes[0]);$running[]=[$proc,$pipes];}
    }
    touch($barrier);$results=[];
    foreach($running as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$results[]=['exit'=>proc_close($proc),'data'=>json_decode($out,true),'stderr'=>$err];}
    return $results;
}
try{
    $demo=DemoGymFactory::create($db);$company=(int)$demo['empresa'];$site=(int)$demo['sedes'][0];$actor=(int)$demo['direccion'];$member=(int)$demo['socios'][0];
    $service=new TrainingService($db,$company,$site,'direccion',$actor);
    $exercise=$service->createExercise(['name'=>'Press concurrente','discipline'=>'STRENGTH','difficulty'=>'INTERMEDIO','execution_type'=>'REPS']);
    $exerciseTwo=$service->createExercise(['name'=>'Remo concurrente','discipline'=>'STRENGTH','difficulty'=>'INTERMEDIO','execution_type'=>'REPS']);
    $template=$service->createTemplate(['name'=>'Plantilla carrera','objective'=>'FUERZA','level'=>'INTERMEDIO','days_per_week'=>1,'status'=>'ACTIVE','disciplines'=>['STRENGTH']],[
        ['name'=>'Día 1','day_order'=>1,'blocks'=>[['name'=>'Fuerza','block_type'=>'STRENGTH','block_order'=>1,'exercises'=>[
            ['exercise_id'=>$exercise,'execution_type'=>'REPS','item_order'=>1,'sets_count'=>4,'reps_count'=>8,'load_kg'=>'70','rest_seconds'=>120],
            ['exercise_id'=>$exerciseTwo,'execution_type'=>'REPS','item_order'=>2,'sets_count'=>4,'reps_count'=>8,'load_kg'=>'60','rest_seconds'=>120],
        ]]]],
    ]);
    $plan=$service->createPlanFromTemplate($template,$member,['start_date'=>'2026-08-24']);
    $item=$service->plan($plan)['days'][0]['blocks'][0]['exercises'][0];

    $barrierUpdate=sys_get_temp_dir().'/gimnera-f25a-update-'.bin2hex(random_bytes(8));$barriers[]=$barrierUpdate;
    $updates=f25aTrainingWorkers('update',$barrierUpdate,[$company,$site,$actor,(int)$item['id_training_plan_exercise'],(int)$item['version']],['72.5','75']);
    check('dos procesos independientes editan el mismo snapshot',count($updates)===2);
    check('optimistic lock permite un único ganador',count(array_filter($updates,fn($r)=>$r['exit']===0&&!empty($r['data']['success'])))===1);
    check('versión aumenta exactamente una vez',(int)$db->query('SELECT version FROM training_plan_exercise WHERE id_training_plan_exercise='.(int)$item['id_training_plan_exercise'])->fetchColumn()===2);

    $barrierAssign=sys_get_temp_dir().'/gimnera-f25a-assign-'.bin2hex(random_bytes(8));$barriers[]=$barrierAssign;
    $assignments=f25aTrainingWorkers('assign',$barrierAssign,[$company,$site,$actor,$plan],['f25a-concurrent-assignment-001','f25a-concurrent-assignment-001']);
    check('dos asignaciones independientes arrancan',count($assignments)===2);
    check('misma clave concurrente devuelve éxito idempotente',count(array_filter($assignments,fn($r)=>$r['exit']===0&&!empty($r['data']['success'])))===2);
    check('concurrencia conserva una sola asignación',(int)$db->query("SELECT COUNT(*) FROM training_assignment WHERE id_empresa={$company} AND id_socio={$member}")->fetchColumn()===1);
    $ids=array_unique(array_map(fn($r)=>(int)($r['data']['id']??0),$assignments));
    check('ambos reintentos observan el mismo resultado',count($ids)===1&&reset($ids)>0);
    check('workers no filtran detalles internos',count(array_filter(array_merge($updates,$assignments),fn($r)=>preg_match('/password|SQLSTATE|Fatal error|Uncaught/i',$r['stderr'])))===0);

    $current=$service->plan($plan);$block=(int)$current['days'][0]['blocks'][0]['id_training_plan_block'];
    $ordered=array_map(static fn(array $row):int=>(int)$row['id_training_plan_exercise'],$current['days'][0]['blocks'][0]['exercises']);
    $barrierReorder=sys_get_temp_dir().'/gimnera-f25a-reorder-'.bin2hex(random_bytes(8));$barriers[]=$barrierReorder;
    $reorders=f25aTrainingWorkers('reorder',$barrierReorder,[$company,$site,$actor,$block,$plan,(int)$current['version']],[implode(',',$ordered),implode(',',array_reverse($ordered))]);
    check('reorder concurrente deja un único ganador',count(array_filter($reorders,fn($r)=>$r['exit']===0&&!empty($r['data']['success'])))===1);
    $afterOrder=array_map(static fn(array $row):int=>(int)$row['id_training_plan_exercise'],$service->plan($plan)['days'][0]['blocks'][0]['exercises']);
    $a=$afterOrder;$b=$ordered;sort($a);sort($b);
    check('reorder concurrente conserva exactamente el conjunto',$a===$b);

    $after=$service->plan($plan);$day=(int)$after['days'][0]['id_training_plan_day'];$resultItem=(int)$after['days'][0]['blocks'][0]['exercises'][0]['id_training_plan_exercise'];
    $session=$service->createSession($plan,$day,'2026-08-30','post-f25a-concurrent-finish-01');
    $barrierFinish=sys_get_temp_dir().'/gimnera-f25a-finish-'.bin2hex(random_bytes(8));$barriers[]=$barrierFinish;
    $finishes=f25aTrainingWorkers('finish',$barrierFinish,[$company,$site,$actor,$session,1,$resultItem],[8,9]);
    check('doble completado concurrente deja un único ganador',count(array_filter($finishes,fn($r)=>$r['exit']===0&&!empty($r['data']['success'])))===1);
    check('doble completado no duplica resultado',(int)$db->query("SELECT COUNT(*) FROM training_session_exercise WHERE id_training_session={$session}")->fetchColumn()===1);
}catch(Throwable $error){check('concurrencia Training completa',false);fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");}
finally{foreach($barriers as $barrier)@unlink($barrier);if($demo!==null)DemoGymFactory::cleanup($db);}
finishTests();
