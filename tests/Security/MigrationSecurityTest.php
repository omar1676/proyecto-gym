<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/helpers/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/services/MigrationService.php';
require_once dirname(__DIR__) . '/Support/MigrationTestFactory.php';

check('dirección puede gestionar migraciones', Authorization::can('direccion','migrations.manage'));
check('admin de sede no gestiona migraciones masivas', !Authorization::can('admin','migrations.manage'));
check('recepción no gestiona migraciones masivas', !Authorization::can('recepcion','migrations.manage'));
check('socio no gestiona migraciones masivas', !Authorization::can('socio','migrations.manage'));

$db=Database::getInstance()->getConnection();
$a=MigrationTestFactory::tenant($db,'seca'.bin2hex(random_bytes(2)));
$b=MigrationTestFactory::tenant($db,'secb'.bin2hex(random_bytes(2)));
try {
    $serviceA=MigrationTestFactory::service($db,$a);
    $serviceB=MigrationTestFactory::service($db,$b);
    $batch=$serviceA->createFromPath('socios','security','socios_100.csv',MigrationTestFactory::fixture('socios_100.csv'));
    $denied=false;
    try { $serviceB->getBatch($batch['uuid']); } catch (MigrationException $e) { $denied=$e->safeCode()==='batch_not_found'; }
    check('otra empresa no puede consultar el batch', $denied);
    $before=(int)$db->query('SELECT COUNT(*) FROM usuario')->fetchColumn();
    $report=$serviceA->dryRun($batch['uuid'],$batch['mapping']);
    check('empresa_id del CSV se ignora y el dry-run no escribe negocio',
        (int)$report['batch']['id_empresa']===$a['company'] && (int)$db->query('SELECT COUNT(*) FROM usuario')->fetchColumn()===$before);
    check('el informe registra la columna de tenant como no autorizativa', in_array('tenant_columns_ignored',array_column($report['issues'],'problem_code'),true));
    $withoutBackup=new MigrationService($a['company'],$a['site'],$a['user'],$db,null,static fn()=>null);
    $backupBlocked=false;
    try { $withoutBackup->confirm($batch['uuid']); } catch (MigrationException $e) { $backupBlocked=$e->safeCode()==='backup_required'; }
    check('la importación confirmada exige backup verificado', $backupBlocked && (int)$db->query('SELECT COUNT(*) FROM usuario')->fetchColumn()===$before);

    $tmp=tempnam(sys_get_temp_dir(),'migsec_'); file_put_contents($tmp,"external_id,nombre\n1,<?php echo 1;\n");
    $rejected=false;
    try { $serviceA->createFromPath('socios','security','ataque.csv',$tmp,true); } catch (MigrationException $e) { $rejected=$e->safeCode()==='executable_content'; }
    check('archivo PHP camuflado como CSV es rechazado', $rejected); @unlink($tmp);

    $tmp=tempnam(sys_get_temp_dir(),'migbig_'); $fh=fopen($tmp,'wb'); ftruncate($fh,IMPORT_MAX_BYTES+1); fclose($fh);
    $rejected=false;
    try { $serviceA->createFromPath('socios','security','grande.csv',$tmp,true); } catch (MigrationException $e) { $rejected=$e->safeCode()==='file_size'; }
    check('archivo excesivo es rechazado antes de parsear', $rejected); @unlink($tmp);

    $safe=$serviceA->createFromPath('productos','security','../../productos_500.csv',MigrationTestFactory::fixture('productos_500.csv'));
    check('el nombre lógico no permite path traversal', $safe['original_name']==='productos_500.csv' && preg_match('/^[a-f0-9]{32}\.csv$/',$safe['storage_key'])===1);
} finally {
    MigrationTestFactory::cleanup($db,$a);
    MigrationTestFactory::cleanup($db,$b);
}
finishTests();
