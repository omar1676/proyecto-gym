<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/services/RetentionService.php';

$db = Database::getInstance()->getConnection();
$companyIds = [];
try {
    $hash = password_hash('synthetic-only-f24', PASSWORD_BCRYPT, ['cost'=>4]);
    $companyStmt = $db->prepare(
        "INSERT INTO empresa (nombre,nombre_comercial,slug,estado,onboarding_state,onboarding_updated_at)
         VALUES (:name,:commercial,:slug,'activa','ACTIVE',NOW())"
    );
    $siteStmt = $db->prepare(
        'INSERT INTO gimnasio (id_empresa,nombre,slug,email_acceso,contrasena_acceso,activo)
         VALUES (:company,:name,:slug,:email,:password,1)'
    );
    $typeStmt = $db->prepare(
        "INSERT INTO tipo_membresia (id_empresa,id_gimnasio,nombre,precio,duracion_meses,estado)
         VALUES (:company,NULL,'Gimnasio F24',30,12,'activo')"
    );
    $userStmt = $db->prepare(
        "INSERT INTO usuario (id_empresa,id_gimnasio,nombre,apellidos,dni,email,nombre_usuario,contrasena,rol,activo)
         VALUES (:company,:site,'Socio','Escala F24',:dni,:email,:username,:password,'socio',1)"
    );
    $membershipStmt = $db->prepare(
        "INSERT INTO socio_membresia
         (id_socio,id_gimnasio,id_tipo_membresia,nombre_tipo,precio_pagado,metodo_pago,fecha_inicio,fecha_fin,estado_pago,idempotency_key)
         VALUES (:member,:site,:type,'Gimnasio F24',30,'efectivo','2026-01-01','2026-12-31','pagado',:key)"
    );
    for ($i=1; $i<=100; $i++) {
        $suffix = bin2hex(random_bytes(5));
        $companyStmt->execute([':name'=>'TEST F24 Tenant '.$suffix,':commercial'=>'F24 '.$i,':slug'=>'f24-'.$suffix]);
        $company = (int)$db->lastInsertId();
        $companyIds[] = $company;
        $siteStmt->execute([
            ':company'=>$company,':name'=>'Sede F24 '.$i,':slug'=>'f24-site-'.$suffix,
            ':email'=>'f24-site-'.$suffix.'@test.invalid',':password'=>$hash,
        ]);
        $site = (int)$db->lastInsertId();
        $typeStmt->execute([':company'=>$company]);
        $type = (int)$db->lastInsertId();
        $userStmt->execute([
            ':company'=>$company,':site'=>$site,':dni'=>'F24'.$suffix,':email'=>'f24-'.$suffix.'@test.invalid',
            ':username'=>'f24_'.$suffix,':password'=>$hash,
        ]);
        $member = (int)$db->lastInsertId();
        $membershipStmt->execute([':member'=>$member,':site'=>$site,':type'=>$type,':key'=>'f24-scale-'.$suffix]);
    }
    check('se crean 100 tenants sintéticos mínimos', count(array_unique($companyIds)) === 100);
    $target = $companyIds[37];
    $result = (new RetentionService($db,$target))->run('2026-08-20');
    check('tenant sin histórico devuelve insufficient sin fuga', $result['evaluated'] === 1 && $result['insufficient'] === 1);
    $ids = implode(',',array_map('intval',$companyIds));
    check('job de un tenant crea exactamente un run', (int)$db->query("SELECT COUNT(*) FROM retention_run WHERE id_empresa IN ({$ids})")->fetchColumn() === 1);
    check('ningún otro tenant recibe detecciones', (int)$db->query("SELECT COUNT(*) FROM retention_detection WHERE id_empresa IN ({$ids})")->fetchColumn() === 0);
    check('config lazy se crea solo para tenant ejecutado', (int)$db->query("SELECT COUNT(*) FROM retention_config WHERE id_empresa IN ({$ids})")->fetchColumn() === 1);
} catch (Throwable $error) {
    check('escenario de 100 tenants Retention', false);
    fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");
} finally {
    if ($companyIds !== []) {
        $ids = implode(',',array_map('intval',$companyIds));
        $db->exec("DELETE FROM retention_action WHERE id_empresa IN ({$ids})");
        $db->exec("DELETE FROM retention_detection WHERE id_empresa IN ({$ids})");
        $db->exec("DELETE FROM retention_run WHERE id_empresa IN ({$ids})");
        $db->exec("DELETE FROM attendance_event WHERE id_empresa IN ({$ids})");
        $db->exec("DELETE FROM retention_activity_mapping WHERE id_empresa IN ({$ids})");
        $db->exec("DELETE FROM retention_config WHERE id_empresa IN ({$ids})");
        $db->exec("DELETE FROM log_actividad WHERE id_empresa IN ({$ids})");
        $db->exec("DELETE sm FROM socio_membresia sm JOIN usuario u ON u.id_usuario=sm.id_socio WHERE u.id_empresa IN ({$ids})");
        $db->exec("DELETE FROM usuario WHERE id_empresa IN ({$ids})");
        $db->exec("DELETE FROM tipo_membresia WHERE id_empresa IN ({$ids})");
        $db->exec("DELETE FROM gimnasio WHERE id_empresa IN ({$ids})");
        $db->exec("DELETE FROM empresa WHERE id_empresa IN ({$ids})");
    }
}
finishTests();
