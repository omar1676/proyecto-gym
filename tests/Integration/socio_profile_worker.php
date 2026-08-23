<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/services/SocioProfileService.php';

[$script, $barrier, $resultFile, $company, $site, $actor, $member, $version, $suffix] = $argv + array_fill(0, 9, '');
for ($i = 0; $i < 200 && !is_file($barrier); $i++) usleep(10000);
$db = new PDO(
    'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME_PRUEBAS . ';charset=' . DB_CHARSET,
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
);
$error = '';
$result = (new SocioProfileService((int) $company, (int) $site, (int) $actor, $db))->update(
    (int) $member, (int) $version,
    ['nombre'=>'Concurrente ' . $suffix,'apellidos'=>'F23','dni'=>$suffix === 'A' ? '12345678Z' : '00000000T',
     'telefono'=>null,'email'=>'concurrente.' . strtolower($suffix) . '@example.invalid','iban'=>null],
    $error
);
file_put_contents($resultFile, json_encode(['status'=>$result['status'] ?? null,'error'=>$error]), LOCK_EX);
exit(0);
