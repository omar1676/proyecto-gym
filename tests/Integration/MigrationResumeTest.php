<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/services/MigrationService.php';
require_once dirname(__DIR__) . '/Support/MigrationTestFactory.php';

$db=Database::getInstance()->getConnection();
$tenant=MigrationTestFactory::tenant($db,'resume'.bin2hex(random_bytes(2)));
try {
    $service=MigrationTestFactory::service($db,$tenant);
    $batch=$service->createFromPath('socios','resume','socios_100.csv',MigrationTestFactory::fixture('socios_100.csv'));
    $service->dryRun($batch['uuid'],$batch['mapping']);
    $failed=false;
    try {
        $service->confirm($batch['uuid'],20,static function(array $row): void {
            if ((int)$row['row_number'] === 25) throw new RuntimeException('fallo sintético de lote');
        });
    } catch (MigrationException $e) { $failed=$e->safeCode()==='chunk_failed'; }
    check('un fallo sintético interrumpe el lote', $failed);
    $partial=$service->getBatch($batch['uuid']);
    check('el batch queda marcado parcial', $partial['status']==='partial');
    $stmt=$db->prepare("SELECT COUNT(*) FROM usuario WHERE id_empresa=:e AND rol='socio'"); $stmt->execute([':e'=>$tenant['company']]);
    check('solo permanece el primer lote confirmado', (int)$stmt->fetchColumn()===20 && (int)$partial['imported_count']===20);
    $done=$service->confirm($batch['uuid'],20);
    check('la reanudación completa el batch', in_array($done['batch']['status'],['completed','completed_with_warnings'],true));
    $stmt->execute([':e'=>$tenant['company']]);
    check('reanudar llega a 100 socios sin duplicar', (int)$stmt->fetchColumn()===100 && (int)$done['batch']['imported_count']===100);
    $maps=$db->prepare("SELECT COUNT(*) FROM migration_entity_map WHERE id_empresa=:e AND entity_type='socios'"); $maps->execute([':e'=>$tenant['company']]);
    check('la reanudación conserva un mapa por socio', (int)$maps->fetchColumn()===100);
} finally { MigrationTestFactory::cleanup($db,$tenant); }
finishTests();
