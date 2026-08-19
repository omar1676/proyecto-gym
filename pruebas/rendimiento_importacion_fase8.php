<?php
putenv('APP_ENV=test');
require_once __DIR__ . '/_arranque.php';
require_once dirname(__DIR__) . '/app/services/MigrationService.php';
require_once dirname(__DIR__) . '/tests/Support/MigrationTestFactory.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (APP_ENV !== 'test' || Database::getInstance()->nombreBase() !== DB_NAME_PRUEBAS) {
    fwrite(STDERR, "Esta medición solo puede ejecutarse contra la base de pruebas.\n"); exit(1);
}

$db=Database::getInstance()->getConnection();
$tenant=MigrationTestFactory::tenant($db,'perf'.bin2hex(random_bytes(3)));
$fixture=MigrationTestFactory::fixture('socios_5000.csv');
$result=['database'=>Database::getInstance()->nombreBase(),'synthetic'=>true,'rows_expected'=>5000];
try {
    $reader=new CsvImportReader();
    $inspection=$reader->inspect($fixture,'socios_5000.csv');
    $parse=[];
    for($sample=0;$sample<3;$sample++){
        $start=hrtime(true); $count=0;
        foreach($reader->rows($fixture,$inspection['headers'],$inspection['delimiter']) as $_) $count++;
        $parse[]=(hrtime(true)-$start)/1e6;
    }
    sort($parse);
    $result['parsing']=['samples'=>3,'rows'=>$count,'p50_ms'=>round($parse[1],2),'max_ms'=>round($parse[2],2)];

    $service=MigrationTestFactory::service($db,$tenant);
    $batch=$service->createFromPath('socios','performance','socios_5000.csv',$fixture);
    $before=(int)$db->query('SELECT COUNT(*) FROM usuario')->fetchColumn();
    $start=hrtime(true);
    $report=$service->dryRun($batch['uuid'],$batch['mapping']);
    $result['dry_run']=[
        'ms'=>round((hrtime(true)-$start)/1e6,2),
        'rows'=>(int)$report['batch']['row_count'],'valid'=>(int)$report['batch']['valid_count'],
        'errors'=>(int)$report['batch']['error_count'],'business_rows_changed'=>(int)$db->query('SELECT COUNT(*) FROM usuario')->fetchColumn()-$before,
    ];
    $start=hrtime(true);
    $done=$service->confirm($batch['uuid'],250);
    $result['import']=[
        'ms'=>round((hrtime(true)-$start)/1e6,2),'chunk_size'=>250,
        'imported'=>(int)$done['batch']['imported_count'],'linked'=>(int)$done['batch']['linked_count'],
    ];
    $stmt=$db->prepare("SELECT COUNT(*) FROM usuario WHERE id_empresa=:e AND id_gimnasio=:s AND rol='socio'");
    $stmt->execute([':e'=>$tenant['company'],':s'=>$tenant['site']]);
    $result['verification']=['tenant_members'=>(int)$stmt->fetchColumn(),'last_committed_row'=>(int)$done['batch']['last_committed_row']];
    $result['memory']=['peak_bytes'=>memory_get_peak_usage(true),'peak_mb'=>round(memory_get_peak_usage(true)/1048576,2)];
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
    if ($result['dry_run']['errors']!==0 || $result['dry_run']['business_rows_changed']!==0 || $result['import']['imported']!==5000 || $result['verification']['tenant_members']!==5000) {
        throw new RuntimeException('La verificación de rendimiento no produjo los recuentos esperados.');
    }
} finally {
    MigrationTestFactory::cleanup($db,$tenant);
}
