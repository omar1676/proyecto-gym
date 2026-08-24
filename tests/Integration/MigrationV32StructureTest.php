<?php

require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/Support/SchemaMigrationTestFactory.php';

$fixture=SchemaMigrationTestFactory::create('v32_structure');
try{
    SchemaMigrationTestFactory::applyThrough($fixture['db'],$fixture['name'],31);
    $manager=new MigrationManager($fixture['db'],dirname(__DIR__,2).'/app/config',0);
    $manager->baselineExisting();
    $track=$fixture['db']->prepare('INSERT INTO schema_migrations (migration,checksum,release_version) VALUES (?,?,?)');
    for($version=23;$version<=31;$version++){
        $file=dirname(__DIR__,2).'/app/config/migracion_v'.$version.'.sql';
        $track->execute([basename($file),hash_file('sha256',$file),'f241-test-v31']);
    }
    check('v32 se ejecuta realmente',$manager->migratePending()===['migracion_v32.sql']);
    check('v32 crea snapshot diario',SchemaMigrationTestFactory::tableExists($fixture['db'],'retention_member_snapshot'));
    check('v32 crea proyección de visitas diarias',SchemaMigrationTestFactory::tableExists($fixture['db'],'attendance_daily_visit'));
    check('v32 crea índice dashboard',SchemaMigrationTestFactory::indexExists($fixture['db'],'retention_member_snapshot','idx_retention_snapshot_dashboard'));
    check('v32 crea índice historial diario',SchemaMigrationTestFactory::indexExists($fixture['db'],'attendance_event','idx_attendance_recent_daily'));
    check('v32 crea trigger de proyección',(int)$fixture['db']->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='trg_attendance_daily_after_insert'")->fetchColumn()===1);
    check('v32 no se reaplica',$manager->migratePending()===[]);
    $fixture['db']->exec('ALTER TABLE retention_member_snapshot DROP INDEX idx_retention_snapshot_dashboard');
    check('schema gate detecta snapshot incompleto',(bool)array_filter(
        $manager->status()['structural_mismatch'],static fn(string $item):bool=>str_contains($item,'idx_retention_snapshot_dashboard')
    ));
}finally{SchemaMigrationTestFactory::drop($fixture);}
finishTests();
