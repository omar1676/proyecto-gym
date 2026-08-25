<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';
require_once dirname(__DIR__, 2) . '/app/services/TrainingService.php';

$db=Database::getInstance()->getConnection();
$demo=null;
$storedKeys=[];
try {
    $demo=DemoGymFactory::create($db);
    $companyA=(int)$demo['empresa'];$siteA1=(int)$demo['sedes'][0];$siteA2=(int)$demo['sedes'][1];
    $actorA=(int)$demo['direccion'];$memberA=(int)$demo['socios'][0];$receptionA=(int)$demo['recepcion'];
    $serviceA=new TrainingService($db,$companyA,$siteA1,'direccion',$actorA);
    $privateA=$serviceA->createExercise([
        'name'=>'Ejercicio privado tenant A','discipline'=>'GENERAL','short_description'=>'<script>alert(1)</script>',
        'difficulty'=>'INICIAL','execution_type'=>'TIME',
    ]);
    $templateA=$serviceA->createTemplate([
        'name'=>'Plantilla privada A','objective'=>'GENERAL','level'=>'TODOS','days_per_week'=>1,
        'status'=>'ACTIVE','disciplines'=>['GENERAL'],
    ],[[
        'name'=>'Día A','day_order'=>1,'blocks'=>[['name'=>'Bloque A','block_type'=>'GENERAL','block_order'=>1,'exercises'=>[[
            'exercise_id'=>$privateA,'execution_type'=>'TIME','item_order'=>1,'sets_count'=>1,'duration_seconds'=>60,'rest_seconds'=>0,
        ]]]],
    ]]);
    $planA=$serviceA->createPlanFromTemplate($templateA,$memberA,['start_date'=>'2026-08-24']);

    $companyB=(int)$db->query("SELECT MIN(id_empresa) FROM empresa WHERE id_empresa <> {$companyA} AND estado='activa'")->fetchColumn();
    $siteB=(int)$db->query("SELECT MIN(id_gimnasio) FROM gimnasio WHERE id_empresa={$companyB}")->fetchColumn();
    $actorB=(int)$db->query("SELECT MIN(id_usuario) FROM usuario WHERE id_empresa={$companyB} AND rol IN ('direccion','admin') AND activo=1")->fetchColumn();
    check('fixture dispone de segundo tenant sintético', $companyB>0 && $siteB>0 && $actorB>0);
    $roleB=(string)$db->query("SELECT rol FROM usuario WHERE id_usuario={$actorB}")->fetchColumn();
    $serviceB=new TrainingService($db,$companyB,$siteB,$roleB,$actorB);

    check('otra empresa no ve ejercicio privado', $serviceB->exercise($privateA)===null);
    check('otra empresa no ve plantilla privada', $serviceB->template($templateA)===null);
    check('otra empresa no ve plan', $serviceB->plan($planA)===null);
    $crossUpdate=false;
    try {$serviceB->updateExercise($privateA,1,['name'=>'Ataque','discipline'=>'GENERAL','difficulty'=>'INICIAL','execution_type'=>'TIME']);}
    catch(DomainException){$crossUpdate=true;}
    check('IDOR de actualización cross-tenant queda rechazado', $crossUpdate);
    $crossClone=false;
    try {$serviceB->cloneExercise($privateA,'Clon indebido');}catch(DomainException){$crossClone=true;}
    check('tenant no clona ejercicio privado ajeno', $crossClone);

    $global=(int)$db->query("SELECT MIN(id_training_exercise) FROM training_exercise WHERE id_empresa IS NULL")->fetchColumn();
    $globalWrite=false;
    try {$serviceA->updateExercise($global,1,['name'=>'Global alterado','discipline'=>'GYM','difficulty'=>'INICIAL','execution_type'=>'REPS']);}
    catch(DomainException){$globalWrite=true;}
    check('catálogo global es read-only para tenant', $globalWrite);

    $adminA2=new TrainingService($db,$companyA,$siteA2,'admin',$actorA);
    check('admin de otra sede no ve plan A1', $adminA2->plan($planA)===null && $adminA2->listPlans()===[]);
    $crossSiteMember=false;
    try {$adminA2->createBlankPlan([
        'member_id'=>$memberA,'name'=>'Plan indebido','objective'=>'GENERAL','start_date'=>'2026-08-24','disciplines'=>['GENERAL'],
    ]);}catch(DomainException){$crossSiteMember=true;}
    check('sede manipulada no permite crear plan para socio A1', $crossSiteMember);

    $reception=new TrainingService($db,$companyA,$siteA1,'recepcion',$receptionA);
    $receptionDenied=false;
    try {$reception->createExercise(['name'=>'Recepción indebida','discipline'=>'GENERAL','difficulty'=>'INICIAL','execution_type'=>'TIME']);}
    catch(DomainException){$receptionDenied=true;}
    check('recepción no diseña entrenamientos', $receptionDenied);
    $memberService=new TrainingService($db,$companyA,$siteA1,'socio',$memberA);
    check('socio puede ver exclusivamente su plan conocido', $memberService->plan($planA)!==null);
    $otherMember=(int)$demo['socios'][1];
    $otherPlan=$serviceA->createPlanFromTemplate($templateA,$otherMember,['start_date'=>'2026-08-24']);
    check('socio no ve plan de otro socio', $memberService->plan($otherPlan)===null);

    $png=tempnam(sys_get_temp_dir(),'f25a_png_');
    file_put_contents($png,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    $mediaId=$serviceA->addImageMedia($privateA,[
        'error'=>UPLOAD_ERR_OK,'tmp_name'=>$png,'name'=>'ejercicio.png','size'=>filesize($png),
    ],['alt_text'=>'Imagen sintética','source'=>'Gimnera','license'=>'Contenido propio']);
    $media=$serviceA->media($mediaId);$storedKeys[]=(string)$media['storage_key'];
    check('imagen privada autorizada se resuelve por metadata', $media!==null && TrainingMediaStorage::resolve($media['storage_key'])!==null);
    check('otra empresa no obtiene metadata del medio privado', $serviceB->media($mediaId)===null);
    check('path traversal no se resuelve', TrainingMediaStorage::resolve('../'.$media['storage_key'])===null
        && TrainingMediaStorage::resolve('..%2f'.$media['storage_key'])===null);

    $badFiles=[
        ['name'=>'payload.php','data'=>file_get_contents($png)],
        ['name'=>'payload.php.png','data'=>'<?php echo 1;'],
        ['name'=>'vector.svg','data'=>'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        ['name'=>'corrupt.png','data'=>'not-an-image'],
    ];
    foreach($badFiles as $index=>$spec){
        $tmp=tempnam(sys_get_temp_dir(),'f25a_bad_');file_put_contents($tmp,$spec['data']);$rejected=false;
        try{TrainingMediaStorage::storeUploadedImage(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$tmp,'name'=>$spec['name']]);}
        catch(InvalidArgumentException){$rejected=true;}finally{@unlink($tmp);}
        check('upload hostil queda rechazado #'.($index+1),$rejected);
    }
    $huge=tempnam(sys_get_temp_dir(),'f25a_huge_');$handle=fopen($huge,'wb');ftruncate($handle,TRAINING_MEDIA_MAX_BYTES+1);fclose($handle);$hugeRejected=false;
    try{TrainingMediaStorage::storeUploadedImage(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$huge,'name'=>'enorme.png']);}catch(InvalidArgumentException){$hugeRejected=true;}finally{@unlink($huge);}
    check('upload superior al máximo queda rechazado',$hugeRejected);
    $unicode=tempnam(sys_get_temp_dir(),'f25a_unicode_');copy($png,$unicode);
    $unicodeStored=TrainingMediaStorage::storeUploadedImage(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$unicode,'name'=>'técnica-ñ.png']);$storedKeys[]=$unicodeStored['storage_key'];@unlink($unicode);
    check('nombre Unicode nunca llega al nombre físico',preg_match('/^training_[a-f0-9]{48}\.png$/',$unicodeStored['storage_key'])===1);
    $nullName=false;try{TrainingMediaStorage::storeUploadedImage(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$png,'name'=>"foto.png\0.php"]);}catch(InvalidArgumentException){$nullName=true;}
    check('nombre con byte nulo queda rechazado',$nullName);
    $storageSource=(string)file_get_contents(dirname(__DIR__,2).'/app/helpers/TrainingMediaStorage.php');
    check('runtime real exige subida HTTP auténtica',str_contains($storageSource,"is_uploaded_file(\$tmp) && APP_ENV !== 'test'"));
    @unlink($png);
    $badVideo=false;try{TrainingMediaStorage::validateVideoReference('http://example.invalid/video');}catch(InvalidArgumentException){$badVideo=true;}
    check('referencia de vídeo exige HTTPS', $badVideo);

    $view=(string)file_get_contents(dirname(__DIR__,2).'/app/views/admin/training_library.php');
    $controller=(string)file_get_contents(dirname(__DIR__,2).'/app/controllers/TrainingController.php');
    check('salida de biblioteca se escapa',str_contains($view,'htmlspecialchars'));
    check('rutas mutables exigen POST y CSRF central',str_contains($controller,'requirePost')&&str_contains($controller,'Csrf::validarPost'));
    check('media usa headers privados y nosniff',str_contains($controller,'private, no-store')&&str_contains($controller,'nosniff'));
}catch(Throwable $error){
    check('seguridad Training completa',false);fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");
}finally{
    if($demo!==null)DemoGymFactory::cleanup($db);
    foreach($storedKeys as $key)TrainingMediaStorage::deleteIfUnreferenced($db,$key);
}

finishTests();
