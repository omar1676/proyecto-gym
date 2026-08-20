<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/services/MigrationService.php';
require_once dirname(__DIR__) . '/Support/MigrationTestFactory.php';

$db = Database::getInstance()->getConnection();
$tenant = MigrationTestFactory::tenant($db, 'core' . bin2hex(random_bytes(2)));
try {
    $service = MigrationTestFactory::service($db, $tenant);
    $before = (int)$db->query('SELECT COUNT(*) FROM usuario')->fetchColumn();
    $batch = $service->createFromPath('socios','generic','socios_100.csv',MigrationTestFactory::fixture('socios_100.csv'));
    check('el batch queda ligado a empresa y sede del proceso', (int)$batch['id_empresa'] === $tenant['company'] && (int)$batch['id_gimnasio'] === $tenant['site']);
    check('la columna empresa_id no se mapea', ($batch['mapping']['empresa_id'] ?? null) === null);
    $report = $service->dryRun($batch['uuid'],$batch['mapping'],['date_format'=>'Y-m-d']);
    check('dry-run analiza 100 filas válidas', (int)$report['batch']['row_count'] === 100 && (int)$report['batch']['valid_count'] === 100);
    check('dry-run no modifica la tabla usuario', (int)$db->query('SELECT COUNT(*) FROM usuario')->fetchColumn() === $before);
    check('dry-run avisa que ignora IDs de tenant externos', in_array('tenant_columns_ignored',array_column($report['issues'],'problem_code'),true));
    $done = $service->confirm($batch['uuid'],25);
    check('importa los 100 socios en lotes', (int)$done['batch']['imported_count'] === 100);
    $storedPath=(new MigrationStorage())->path($batch['storage_key']);
    check('el archivo temporal se elimina tras completar', $done['batch']['storage_key']===null && !is_file($storedPath));
    $stmt=$db->prepare("SELECT COUNT(*) FROM usuario WHERE id_empresa=:e AND id_gimnasio=:s AND rol='socio'");
    $stmt->execute([':e'=>$tenant['company'],':s'=>$tenant['site']]);
    check('los socios solo aparecen en el tenant objetivo', (int)$stmt->fetchColumn() === 100);
    $stmt=$db->prepare("SELECT COUNT(*) FROM migration_entity_map WHERE id_empresa=:e AND entity_type='socios'");
    $stmt->execute([':e'=>$tenant['company']]);
    check('crea 100 mapas de IDs externos', (int)$stmt->fetchColumn() === 100);
    $same = $service->createFromPath('socios','generic','socios_100.csv',MigrationTestFactory::fixture('socios_100.csv'));
    check('el mismo hash devuelve el batch existente', !empty($same['already_processed']) && $same['uuid'] === $batch['uuid']);
    $service->confirm($batch['uuid'],25);
    $stmt->execute([':e'=>$tenant['company']]);
    check('confirmar dos veces no duplica mapas ni socios', (int)$stmt->fetchColumn() === 100);

    $productBatch = $service->createFromPath('productos','generic','productos_500.csv',MigrationTestFactory::fixture('productos_500.csv'));
    $productReport = $service->dryRun($productBatch['uuid'],$productBatch['mapping']);
    check('dry-run de productos valida 500 filas', (int)$productReport['batch']['row_count'] === 500 && (int)$productReport['batch']['error_count'] === 0);
    $productDone = $service->confirm($productBatch['uuid'],100);
    check('importa 500 productos con DECIMAL y stock no negativo', (int)$productDone['batch']['imported_count'] === 500);
    $stmt=$db->prepare('SELECT COUNT(*) FROM producto WHERE id_gimnasio=:s AND stock<0'); $stmt->execute([':s'=>$tenant['site']]);
    check('ningún producto importado tiene stock negativo', (int)$stmt->fetchColumn() === 0);

    $stmt=$db->prepare("INSERT INTO tipo_membresia (id_empresa,id_gimnasio,nombre,precio,duracion_meses,estado) VALUES (:e,:s,'Tarifa migración',40.00,1,'activo')");
    $stmt->execute([':e'=>$tenant['company'],':s'=>$tenant['site']]);
    $membershipBefore=(int)$db->query('SELECT COUNT(*) FROM socio_membresia')->fetchColumn();
    $membershipBatch=$service->createFromPath('membresias','generic','membresias_dry_run.csv',MigrationTestFactory::fixture('membresias_dry_run.csv'));
    $membershipReport=$service->dryRun($membershipBatch['uuid'],$membershipBatch['mapping']);
    check('membresías valida referencias en modo dry-run', (int)$membershipReport['batch']['row_count']===3 && (int)$membershipReport['batch']['error_count']===0);
    $blocked=false;
    try { $service->confirm($membershipBatch['uuid']); } catch (MigrationException $e) { $blocked=$e->safeCode()==='dry_run_only'; }
    check('membresías no permite importación real', $blocked);
    check('dry-run de membresías no crea contratos', (int)$db->query('SELECT COUNT(*) FROM socio_membresia')->fetchColumn()===$membershipBefore);
} finally {
    MigrationTestFactory::cleanup($db,$tenant);
}
finishTests();
