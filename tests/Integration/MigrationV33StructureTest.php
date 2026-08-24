<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/SchemaMigrationTestFactory.php';

$fixture = SchemaMigrationTestFactory::create('v33_structure');
try {
    SchemaMigrationTestFactory::applyThrough($fixture['db'], $fixture['name'], 32);
    $manager = new MigrationManager($fixture['db'], dirname(__DIR__, 2) . '/app/config', 0);
    $manager->baselineExisting();
    $track = $fixture['db']->prepare('INSERT INTO schema_migrations (migration,checksum,release_version) VALUES (?,?,?)');
    for ($version=23; $version<=32; $version++) {
        $file=dirname(__DIR__,2).'/app/config/migracion_v'.$version.'.sql';
        $track->execute([basename($file),hash_file('sha256',$file),'f25a-test-v32']);
    }
    check('v33 se ejecuta realmente', $manager->migratePending() === ['migracion_v33.sql']);
    $tables = [
        'training_exercise','training_exercise_media','training_template','training_template_discipline',
        'training_template_day','training_template_block','training_template_exercise','training_plan',
        'training_plan_discipline','training_plan_day','training_plan_block','training_plan_exercise',
        'training_plan_exercise_media','training_assignment','training_session','training_session_exercise',
    ];
    foreach ($tables as $table) check("v33 crea {$table}", SchemaMigrationTestFactory::tableExists($fixture['db'],$table));
    check('catálogo global inicial es multidisciplina', (int)$fixture['db']->query(
        'SELECT COUNT(DISTINCT discipline) FROM training_exercise WHERE id_empresa IS NULL'
    )->fetchColumn() >= 6);
    check('catálogo global contiene nueve ejercicios sintéticos', (int)$fixture['db']->query(
        'SELECT COUNT(*) FROM training_exercise WHERE id_empresa IS NULL'
    )->fetchColumn() === 9);
    check('unicidad de plan principal está en DB', SchemaMigrationTestFactory::indexExists(
        $fixture['db'],'training_assignment','uq_training_assignment_active_member'
    ));
    check('idempotencia de sesión está en DB', SchemaMigrationTestFactory::indexExists(
        $fixture['db'],'training_session','uq_training_session_idempotency'
    ));
    check('v33 no se reaplica', $manager->migratePending() === []);
    check('v33 termina sin checksum ni estructura pendiente', $manager->status()['checksum_mismatch'] === []
        && $manager->status()['structural_mismatch'] === []);
    $fixture['db']->exec('DROP TABLE training_session_exercise');
    check('schema gate detecta v33 parcial', (bool)array_filter(
        $manager->status()['structural_mismatch'], static fn(string $item):bool=>str_contains($item,'training_session_exercise')
    ));
} finally {
    SchemaMigrationTestFactory::drop($fixture);
}

finishTests();
