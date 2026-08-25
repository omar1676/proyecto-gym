<?php

require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/Support/SchemaMigrationTestFactory.php';

$fixture=SchemaMigrationTestFactory::create('v33_access_policy');
try {
    SchemaMigrationTestFactory::applyThrough($fixture['db'],$fixture['name'],32);
    $manager=new MigrationManager($fixture['db'],dirname(__DIR__,2).'/app/config',0);
    $manager->baselineExisting();
    $track=$fixture['db']->prepare('INSERT INTO schema_migrations (migration,checksum,release_version) VALUES (?,?,?)');
    for($version=23;$version<=32;$version++){
        $file=dirname(__DIR__,2).'/app/config/migracion_v'.$version.'.sql';
        $track->execute([basename($file),hash_file('sha256',$file),'f26-test-v32']);
    }
    check('v33 se ejecuta realmente',$manager->migratePending()===['migracion_v33.sql']);
    check('v33 crea estado actual',SchemaMigrationTestFactory::tableExists($fixture['db'],'access_policy'));
    check('v33 crea historial inmutable',SchemaMigrationTestFactory::tableExists($fixture['db'],'access_policy_event'));
    check('v33 crea unicidad tenant/sede/socio',SchemaMigrationTestFactory::indexExists($fixture['db'],'access_policy','uq_access_policy_member_scope'));
    check('v33 crea índice de caducidad',SchemaMigrationTestFactory::indexExists($fixture['db'],'access_policy','idx_access_policy_expiry'));
    check('v33 crea idempotencia por tenant',SchemaMigrationTestFactory::indexExists($fixture['db'],'access_policy_event','uq_access_policy_event_idempotency'));
    check('v33 se aplica una sola vez',$manager->migratePending()===[]);
    $fixture['db']->exec('ALTER TABLE access_policy DROP INDEX idx_access_policy_expiry');
    check('schema gate detecta índice de caducidad ausente',(bool)array_filter(
        $manager->status()['structural_mismatch'],static fn(string $item):bool=>str_contains($item,'idx_access_policy_expiry')
    ));
} finally { SchemaMigrationTestFactory::drop($fixture); }
finishTests();
