<?php

require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/Support/SchemaMigrationTestFactory.php';

$fixture=SchemaMigrationTestFactory::create('v34_access_policy');
try {
    SchemaMigrationTestFactory::applyThrough($fixture['db'],$fixture['name'],33);
    $manager=new MigrationManager($fixture['db'],dirname(__DIR__,2).'/app/config',0);
    $manager->baselineExisting();
    $track=$fixture['db']->prepare('INSERT INTO schema_migrations (migration,checksum,release_version) VALUES (?,?,?)');
    for($version=23;$version<=33;$version++){
        $file=dirname(__DIR__,2).'/app/config/migracion_v'.$version.'.sql';
        $track->execute([basename($file),hash_file('sha256',$file),'integration-test-v33']);
    }
    check('v34 se ejecuta realmente',$manager->migratePending()===['migracion_v34.sql']);
    check('v34 crea estado actual',SchemaMigrationTestFactory::tableExists($fixture['db'],'access_policy'));
    check('v34 crea historial inmutable',SchemaMigrationTestFactory::tableExists($fixture['db'],'access_policy_event'));
    check('v34 crea unicidad tenant/sede/socio',SchemaMigrationTestFactory::indexExists($fixture['db'],'access_policy','uq_access_policy_member_scope'));
    check('v34 crea índice de caducidad',SchemaMigrationTestFactory::indexExists($fixture['db'],'access_policy','idx_access_policy_expiry'));
    check('v34 crea idempotencia por tenant',SchemaMigrationTestFactory::indexExists($fixture['db'],'access_policy_event','uq_access_policy_event_idempotency'));
    check('v34 se aplica una sola vez',$manager->migratePending()===[]);
    $fixture['db']->exec('ALTER TABLE access_policy DROP INDEX idx_access_policy_expiry');
    check('schema gate detecta índice de caducidad ausente',(bool)array_filter(
        $manager->status()['structural_mismatch'],static fn(string $item):bool=>str_contains($item,'idx_access_policy_expiry')
    ));
    $fixture['db']->exec(
        'ALTER TABLE access_policy ADD KEY idx_access_policy_expiry (state,expires_at_utc,id_access_policy)'
    );
    $fixture['db']->exec('ALTER TABLE access_policy DROP CONSTRAINT chk_access_policy_temporary_expiry');
    check('schema gate detecta CHECK temporal ausente',(bool)array_filter(
        $manager->status()['structural_mismatch'],
        static fn(string $item):bool=>str_contains($item,'chk_access_policy_temporary_expiry')
    ));
} finally { SchemaMigrationTestFactory::drop($fixture); }
finishTests();
