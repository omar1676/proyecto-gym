<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/services/TrainingService.php';

$mode=(string)($argv[1]??'');$barrier=(string)($argv[2]??'');
if($mode===''||$barrier==='')exit(2);
for($i=0;$i<300&&!is_file($barrier);$i++)usleep(10000);
if(!is_file($barrier))exit(3);
try{
    $db=Database::getInstance()->getConnection();
    $service=new TrainingService($db,(int)$argv[3],(int)$argv[4],'direccion',(int)$argv[5]);
    if($mode==='assign'){
        $id=$service->assignPlan((int)$argv[6],(string)$argv[7]);
        echo json_encode(['success'=>true,'id'=>$id],JSON_THROW_ON_ERROR);exit(0);
    }
    if($mode==='update'){
        $service->updatePlanExercise((int)$argv[6],(int)$argv[7],[
            'execution_type'=>'REPS','sets_count'=>4,'reps_count'=>8,
            'load_kg'=>(string)$argv[8],'rest_seconds'=>120,'notes'=>'Edición concurrente sintética',
        ]);
        echo json_encode(['success'=>true],JSON_THROW_ON_ERROR);exit(0);
    }
    exit(2);
}catch(Throwable $error){
    echo json_encode(['success'=>false,'error'=>get_class($error)],JSON_THROW_ON_ERROR);exit(1);
}
