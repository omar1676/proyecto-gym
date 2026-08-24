<?php

require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/Support/DemoGymFactory.php';
require_once dirname(__DIR__,2).'/app/services/AttendanceEventService.php';
require_once dirname(__DIR__,2).'/app/services/RetentionService.php';

$db=Database::getInstance()->getConnection();$demo=null;$demoB=null;
function f241Member(PDO $db,int $company,int $site,int $type,string $key,string $name):int{
    $users=new UserModel($site,$company);
    if(!$users->crear($name,'Demo UX','F241'.$key,'60077'.str_pad((string)strlen($key),4,'0',STR_PAD_LEFT),
        'f241.'.strtolower($key).'@example.invalid','f241_'.strtolower($key),'synthetic-f241')) throw new RuntimeException('alta socio');
    $stmt=$db->prepare('SELECT id_usuario FROM usuario WHERE id_empresa=:company AND nombre_usuario=:username');
    $stmt->execute([':company'=>$company,':username'=>'f241_'.strtolower($key)]);$id=(int)$stmt->fetchColumn();
    $membership=$db->prepare("INSERT INTO socio_membresia
      (id_socio,id_gimnasio,id_tipo_membresia,nombre_tipo,precio_pagado,metodo_pago,fecha_inicio,fecha_fin,estado_pago,idempotency_key)
      VALUES (:member,:site,:type,'Gym UX',30,'efectivo','2026-01-01','2026-12-31','pagado',:key)");
    $membership->execute([':member'=>$id,':site'=>$site,':type'=>$type,':key'=>'f241-'.$key]);return $id;
}
function f241Dates(string $start,int $weeks,int $perWeek):array{$dates=[];$origin=new DateTimeImmutable($start);for($w=0;$w<$weeks;$w++)for($v=0;$v<$perWeek;$v++)$dates[]=$origin->modify('+'.($w*7+$v).' days')->format('Y-m-d');return $dates;}
try{
    $demo=DemoGymFactory::create($db);$company=(int)$demo['empresa'];$site1=(int)$demo['sedes'][0];$site2=(int)$demo['sedes'][1];$type=(int)$demo['tarifa'];
    $db->prepare("UPDATE tipo_membresia SET nombre='Gimnasio UX' WHERE id_tipo_membresia=:type AND id_empresa=:company")->execute([':type'=>$type,':company'=>$company]);
    $db->prepare("INSERT INTO retention_activity_mapping(id_empresa,id_tipo_membresia,activity_family) VALUES(:company,:type,'GYM')")->execute([':company'=>$company,':type'=>$type]);
    $normal=f241Member($db,$company,$site1,$type,'NORMAL','Elena');
    $partial=f241Member($db,$company,$site1,$type,'PARTIAL','Mario');
    $high=f241Member($db,$company,$site1,$type,'HIGH','Lucía');
    $insufficient=f241Member($db,$company,$site2,$type,'NEW','Nora');
    $insert=$db->prepare("INSERT INTO attendance_event(event_id,id_empresa,id_gimnasio,id_socio,occurred_at_utc,local_date,source,external_reference,idempotency_key,activity_family)
      VALUES(:uuid,:company,:site,:member,:occurred,:date,'IMPORT',:external,:key,'GYM')");
    $sequence=0;$record=function(int $member,int $site,array $dates,string $prefix,string $time='10:00:00')use($insert,$company,&$sequence):void{foreach($dates as $date){$sequence++;$ref=$prefix.'-'.$sequence;$insert->execute([':uuid'=>RequestContext::newId(),':company'=>$company,':site'=>$site,':member'=>$member,':occurred'=>$date.' '.$time,':date'=>$date,':external'=>$ref,':key'=>hash('sha256',$company.'|IMPORT|external:'.$ref)]);}};
    $record($normal,$site1,array_merge(f241Dates('2026-06-12',8,4),f241Dates('2026-08-07',2,4)),'normal');
    $record($partial,$site1,array_merge(f241Dates('2026-06-12',8,4),f241Dates('2026-08-07',2,1)),'partial');
    $record($high,$site1,f241Dates('2026-06-12',8,4),'high');
    $record($insufficient,$site2,['2026-08-19'],'new');
    $record($normal,$site1,['2026-08-20'],'same-day-a','18:00:00');$record($normal,$site1,['2026-08-20'],'same-day-b','19:00:00');
    $mixed=$db->prepare("INSERT INTO attendance_event(event_id,id_empresa,id_gimnasio,id_socio,occurred_at_utc,local_date,source,external_reference,idempotency_key,activity_family)
      VALUES(:uuid,:company,:site,:member,'2026-08-20 20:00:00','2026-08-20','IMPORT','same-day-mixed',:key,'BOXEO')");
    $mixed->execute([':uuid'=>RequestContext::newId(),':company'=>$company,':site'=>$site1,':member'=>$normal,':key'=>hash('sha256','f241-same-day-mixed')]);
    $service=new RetentionService($db,$company);$run=$service->run('2026-08-20');
    check('snapshot conserva los cuatro estados humanos',$run['normal']===1&&$run['attention']===1&&$run['high_attention']===1&&$run['insufficient']===1
        &&(int)$db->query("SELECT COUNT(*) FROM retention_member_snapshot WHERE id_empresa={$company}")->fetchColumn()===4);
    $metrics=$service->metrics();
    check('contadores dashboard provienen del último snapshot',$metrics['normal']===1&&$metrics['insufficient']===1&&$metrics['total']===2);
    $queue=$service->cases(['state'=>'attention'],1,20);
    check('prioridad ordena alta antes que parcial',count($queue['items'])===2&&$queue['items'][0]['id_socio']===$high&&$queue['items'][1]['id_socio']===$partial);
    check('filtros humanos separan normal e insuficiente',$service->cases(['state'=>'normal'],1,20)['items'][0]['id_socio']===$normal
        &&$service->cases(['state'=>'insufficient'],1,20)['items'][0]['id_socio']===$insufficient);
    $found=$service->search('Elena Demo',1,20);
    check('búsqueda encuentra socio normal y minimiza PII',$found['pagination']['total']===1&&$found['items'][0]['display_state']==='NORMAL'
        &&!array_key_exists('telefono',$found['items'][0])&&!array_key_exists('email',$found['items'][0]));
    check('comodines se tratan como texto literal',$service->search('%_',1,20)['pagination']['total']===0);
    $site1Service=new RetentionService($db,$company,$site1);
    check('ámbito sede no incluye contadores ni búsquedas de sede 2',$site1Service->metrics()['evaluated']===3&&$site1Service->search('Nora',1,20)['pagination']['total']===0);
    $history=$service->attendanceHistory(['from'=>'2026-08-20','to'=>'2026-08-20'],1,20);
    check('historial deduplica eventos del mismo socio y día',$history['pagination']['total']===1&&$history['items'][0]['id_socio']===$normal
        &&$history['items'][0]['activity_family']==='GENERAL'&&(int)$history['items'][0]['event_count']===3);
    $site2History=$site1Service->attendanceHistory([],1,50);
    check('historial de sede 1 no contiene socio de sede 2',!in_array($insufficient,array_column($site2History['items'],'id_socio'),true));
    $invalidDate=false;try{$service->attendanceHistory(['from'=>'2026-99-99'],1,20);}catch(InvalidArgumentException){$invalidDate=true;}
    check('fecha hostil se rechaza de forma controlada',$invalidDate);
    check('últimas entradas no cargan más del límite',count($service->recentVisits(10))<=10);
    check('paginación hostil se normaliza',$service->cases(['state'=>'all'],-9,500)['pagination']['page']===1
        &&$service->cases(['state'=>'all'],-9,500)['pagination']['per_page']===50);
    $events=new AttendanceEventService($db,$company,new DateTimeZone('Europe/Madrid'));
    $events->record($site1,$high,new DateTimeImmutable('2026-08-21 09:00:00',new DateTimeZone('Europe/Madrid')),'MANUAL','f241-return-high',null,'GYM');
    check('evento nuevo aparece tras refresh normal',$service->recentVisits(1)[0]['id_socio']===$high);
    $service->run('2026-08-21');
    check('evento nuevo actualiza RETURNED sin alterar la regla',$service->cases(['state'=>'returned'],1,20)['items'][0]['id_socio']===$high);
    $latestRun=(int)$db->query("SELECT MAX(id_retention_run) FROM retention_run WHERE id_empresa={$company}")->fetchColumn();
    $stale=$db->prepare("INSERT INTO retention_detection
      (detection_id,id_empresa,id_gimnasio,id_socio,id_retention_run,evaluation_date,level,status,activity_family,
       baseline_visits,recent_visits,baseline_weekly_rate,recent_weekly_rate,drop_pct,detected_at_utc,cooldown_until)
      VALUES(:uuid,:company,:site,:member,:run,'2026-08-19','HIGH_ATTENTION','OPEN','GYM',20,0,2.5,0,100,'2026-08-19 08:00:00','2026-09-02')");
    $stale->execute([':uuid'=>RequestContext::newId(),':company'=>$company,':site'=>$site1,':member'=>$normal,':run'=>$latestRun]);
    check('contador no incluye detección histórica si el estado actual es normal',$service->metrics()['total']===1);
    $demoB=DemoGymFactory::create($db);$companyB=(int)$demoB['empresa'];$siteB=(int)$demoB['sedes'][0];$foreign=(int)$demoB['socios'][0];
    $db->prepare("UPDATE usuario SET nombre='F241ForeignOnly' WHERE id_usuario=:member AND id_empresa=:company")
        ->execute([':member'=>$foreign,':company'=>$companyB]);
    (new AttendanceEventService($db,$companyB,new DateTimeZone('Europe/Madrid')))
        ->record($siteB,$foreign,new DateTimeImmutable('2026-08-22 09:00:00',new DateTimeZone('Europe/Madrid')),'MANUAL','f241-foreign');
    check('búsqueda no cruza empresa',$service->search('F241ForeignOnly',1,20)['pagination']['total']===0);
    check('historial no cruza empresa',!in_array($foreign,array_column($service->attendanceHistory([],1,50)['items'],'id_socio'),true));
    $foreignSiteRejected=false;try{new RetentionService($db,$company,$siteB);}catch(DomainException){$foreignSiteRejected=true;}
    check('sede manipulada de otra empresa se rechaza',$foreignSiteRejected);
}catch(Throwable $error){check('dashboard F24.1 integral',false);fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");}
finally{if($demoB!==null)DemoGymFactory::cleanup($db);if($demo!==null)DemoGymFactory::cleanup($db);}
finishTests();
