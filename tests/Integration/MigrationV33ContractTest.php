<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/SchemaMigrationTestFactory.php';

$fixture = SchemaMigrationTestFactory::create('v33_contract');
try {
    SchemaMigrationTestFactory::applyThrough($fixture['db'], $fixture['name'], 33);
    $manager = new MigrationManager($fixture['db'], dirname(__DIR__, 2) . '/app/config', 0);
    $manager->baselineExisting();
    $track = $fixture['db']->prepare(
        'INSERT INTO schema_migrations (migration,checksum,release_version) VALUES (?,?,?)'
    );
    for ($version = 23; $version <= 33; $version++) {
        $file = dirname(__DIR__, 2) . '/app/config/migracion_v' . $version . '.sql';
        $track->execute([basename($file), hash_file('sha256', $file), 'post-f25a-contract']);
    }
    check('v33 completa parte sin mismatch', $manager->status()['structural_mismatch'] === []);

    $shapeRejected=false;$fixture['db']->exec('SET FOREIGN_KEY_CHECKS=0');
    try{
        $fixture['db']->exec(
            "INSERT INTO training_template_exercise "
            . "(id_training_template_block,id_empresa,id_training_exercise,exercise_catalog_scope,execution_type,item_order) "
            . "VALUES (999999,999999,999999,999999,'REPS',1)"
        );
    }catch(PDOException){$shapeRejected=true;}
    finally{$fixture['db']->exec('SET FOREIGN_KEY_CHECKS=1');}
    check('DB rechaza REPS sin series ni repeticiones', $shapeRejected);

    $fixture['db']->exec('ALTER TABLE training_assignment DROP INDEX uq_training_assignment_active_member');
    check('gate detecta pérdida de unicidad del plan activo', (bool) array_filter(
        $manager->status()['structural_mismatch'],
        static fn(string $item): bool => str_contains($item, 'uq_training_assignment_active_member')
    ));
    $fixture['db']->exec(
        'ALTER TABLE training_assignment ADD UNIQUE KEY uq_training_assignment_active_member (id_empresa,active_member_scope)'
    );

    $fixture['db']->exec('ALTER TABLE training_session_exercise DROP FOREIGN KEY fk_training_session_exercise_plan_scope');
    check('gate detecta pérdida de FK de resultado a plan', (bool) array_filter(
        $manager->status()['structural_mismatch'],
        static fn(string $item): bool => str_contains($item, 'fk_training_session_exercise_plan_scope')
    ));
    $fixture['db']->exec(
        'ALTER TABLE training_session_exercise ADD CONSTRAINT fk_training_session_exercise_plan_scope '
        . 'FOREIGN KEY (id_training_plan_exercise,id_empresa,id_gimnasio,id_socio) '
        . 'REFERENCES training_plan_exercise (id_training_plan_exercise,id_empresa,id_gimnasio,id_socio) ON DELETE RESTRICT'
    );

    $fixture['db']->exec('ALTER TABLE training_plan_exercise DROP CONSTRAINT chk_training_plan_execution_shape');
    check('gate detecta pérdida de CHECK por modalidad', (bool) array_filter(
        $manager->status()['structural_mismatch'],
        static fn(string $item): bool => str_contains($item, 'chk_training_plan_execution_shape')
    ));

    $fixture['db']->exec('ALTER TABLE training_session_exercise DROP CONSTRAINT chk_training_session_completed');
    check('gate detecta pérdida de CHECK de resultado completado', (bool) array_filter(
        $manager->status()['structural_mismatch'],
        static fn(string $item): bool => str_contains($item, 'chk_training_session_completed')
    ));
} catch (Throwable $error) {
    check('contrato hostil v33 completo', false);
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
} finally {
    SchemaMigrationTestFactory::drop($fixture);
}

finishTests();
