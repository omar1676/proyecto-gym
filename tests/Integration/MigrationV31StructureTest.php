<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/SchemaMigrationTestFactory.php';

$fixture = SchemaMigrationTestFactory::create('v31_structure');
try {
    SchemaMigrationTestFactory::applyThrough($fixture['db'], $fixture['name'], 30);
    $manager = new MigrationManager($fixture['db'], dirname(__DIR__, 2) . '/app/config', 0);
    $manager->baselineExisting();
    $track = $fixture['db']->prepare('INSERT INTO schema_migrations (migration,checksum,release_version) VALUES (?,?,?)');
    for ($version = 23; $version <= 30; $version++) {
        $file = dirname(__DIR__, 2) . '/app/config/migracion_v' . $version . '.sql';
        $track->execute([basename($file), hash_file('sha256', $file), 'f24-test-v30']);
    }
    check('v30 no contiene tablas Retention', !SchemaMigrationTestFactory::tableExists($fixture['db'], 'attendance_event'));
    check('v31 y sus migraciones posteriores se ejecutan realmente', $manager->migratePending() === ['migracion_v31.sql','migracion_v32.sql','migracion_v33.sql']);
    foreach (['retention_config','retention_activity_mapping','attendance_event','retention_run','retention_detection','retention_action'] as $table) {
        check("v31 crea {$table}", SchemaMigrationTestFactory::tableExists($fixture['db'], $table));
    }
    foreach ([
        ['usuario','uq_usuario_company_scope'], ['tipo_membresia','uq_tipo_membresia_scope'],
        ['attendance_event','uq_attendance_idempotency'], ['attendance_event','uq_attendance_external'],
        ['retention_run','uq_retention_run_daily'], ['retention_detection','uq_retention_detection_daily'],
        ['retention_action','uq_retention_action_idempotency'],
    ] as [$table,$index]) {
        check("v31 crea índice {$index}", SchemaMigrationTestFactory::indexExists($fixture['db'], $table, $index));
    }
    check('segunda ejecución no reaplica v31-v33', $manager->migratePending() === []);
    $fixture['db']->exec('ALTER TABLE attendance_event DROP INDEX uq_attendance_external');
    $status = $manager->status();
    check('schema gate detecta v31 incompleta', (bool)array_filter(
        $status['structural_mismatch'], static fn(string $item): bool => str_contains($item, 'uq_attendance_external')
    ));
} finally {
    SchemaMigrationTestFactory::drop($fixture);
}
finishTests();
