<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/SchemaMigrationTestFactory.php';

$fixture = SchemaMigrationTestFactory::create('baseline_v21');
try {
    $db = $fixture['db'];
    SchemaMigrationTestFactory::applyThrough($db, $fixture['name'], 21);
    $manager = new MigrationManager($db);
    $manager->baselineExisting();
    $afterBaseline = $manager->status();
    check('baseline v21 solo registra hasta v22', $afterBaseline['pending'] === [
        'migracion_v23.sql', 'migracion_v24.sql', 'migracion_v25.sql', 'migracion_v26.sql', 'migracion_v27.sql', 'migracion_v28.sql', 'migracion_v29.sql', 'migracion_v30.sql', 'migracion_v31.sql', 'migracion_v32.sql', 'migracion_v33.sql', 'migracion_v34.sql',
    ]);
    check('baseline v21 no inventa estructuras v24', !SchemaMigrationTestFactory::tableExists($db, 'migration_batch'));
    check('baseline v21 no inventa estructuras v25', !SchemaMigrationTestFactory::tableExists($db, 'obligacion_pago'));
    check('baseline v21 no inventa estructuras v26', !SchemaMigrationTestFactory::tableExists($db, 'access_sync_job'));

    $applied = $manager->migratePending();
    check('v23-v34 se ejecutan realmente y en orden', $applied === [
        'migracion_v23.sql', 'migracion_v24.sql', 'migracion_v25.sql', 'migracion_v26.sql', 'migracion_v27.sql', 'migracion_v28.sql', 'migracion_v29.sql', 'migracion_v30.sql', 'migracion_v31.sql', 'migracion_v32.sql', 'migracion_v33.sql', 'migracion_v34.sql',
    ]);
    foreach ([
        'migration_batch', 'obligacion_pago', 'cobro', 'access_identity_map',
        'access_sync_job', 'access_control_audit',
    ] as $table) {
        check('existe físicamente ' . $table, SchemaMigrationTestFactory::tableExists($db, $table));
    }
    check('v27 crea unicidad de mandato activo', SchemaMigrationTestFactory::indexExists($db, 'mandato_sepa', 'uq_mandato_socio_activo'));
    check('v27 crea claim único de remesa', SchemaMigrationTestFactory::indexExists($db, 'remesa_recibo', 'uq_recibo_membresia_en_cobro'));
    check('v28 crea correlación de auditoría', SchemaMigrationTestFactory::indexExists($db, 'log_actividad', 'idx_log_correlation'));
    check('v29 crea categorías tenant-aware', SchemaMigrationTestFactory::indexExists($db, 'categoria_producto', 'uq_categoria_empresa_nombre'));
    check('v30 crea versión optimista del perfil', SchemaMigrationTestFactory::tableExists($db, 'usuario')
        && (int) $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='usuario' AND column_name='profile_version'")->fetchColumn() === 1);
    check('v31 crea contrato Retention completo', SchemaMigrationTestFactory::tableExists($db, 'attendance_event')
        && SchemaMigrationTestFactory::tableExists($db, 'retention_detection'));
    check('v32 crea proyección UX de Retention', SchemaMigrationTestFactory::tableExists($db, 'retention_member_snapshot'));
    check('v33 crea Training Foundation', SchemaMigrationTestFactory::tableExists($db, 'training_plan')
        && SchemaMigrationTestFactory::tableExists($db, 'training_session'));
    check('v34 crea política e historial de acceso', SchemaMigrationTestFactory::tableExists($db, 'access_policy')
        && SchemaMigrationTestFactory::tableExists($db, 'access_policy_event'));
    $status = $manager->status();
    check('legacy v21 termina sin migraciones pendientes', $status['pending'] === []);
    check('legacy v21 termina sin checksum mismatch', $status['checksum_mismatch'] === []);
    check('legacy v21 termina sin incoherencia estructural', $status['structural_mismatch'] === []);
} finally {
    SchemaMigrationTestFactory::drop($fixture);
}
finishTests();
