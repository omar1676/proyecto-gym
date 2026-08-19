<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__,2).'/app/services/MigrationService.php';
require_once dirname(__DIR__).'/Support/MigrationTestFactory.php';

$db=Database::getInstance()->getConnection();
$tenant=MigrationTestFactory::tenant($db,'dups'.bin2hex(random_bytes(2)));
try {
    $service=MigrationTestFactory::service($db,$tenant);
    $before=(int)$db->query('SELECT COUNT(*) FROM usuario')->fetchColumn();
    $batch=$service->createFromPath('socios','duplicates','socios_duplicados.csv',MigrationTestFactory::fixture('socios_duplicados.csv'));
    $report=$service->dryRun($batch['uuid'],$batch['mapping']);
    $codes=array_column($report['issues'],'problem_code');
    check('detecta external_id repetido dentro del archivo', in_array('duplicate_external_id',$codes,true));
    check('detecta DNI/email repetido dentro del archivo', in_array('duplicate_identifier',$codes,true));
    check('teléfono repetido queda para revisión manual', in_array('duplicate_phone_in_file',$codes,true));
    check('el dry-run con duplicados no crea socios', (int)$db->query('SELECT COUNT(*) FROM usuario')->fetchColumn()===$before);
    $blocked=false;
    try { $service->confirm($batch['uuid']); } catch (MigrationException $e) { $blocked=$e->safeCode()==='batch_not_confirmable'; }
    check('un batch con conflictos no puede confirmarse', $blocked);
} finally { MigrationTestFactory::cleanup($db,$tenant); }
finishTests();
