<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/SchemaMigrationTestFactory.php';

$fixture = SchemaMigrationTestFactory::create('hostile_schema');
try {
    $db = $fixture['db'];
    SchemaMigrationTestFactory::applyThrough($db, $fixture['name'], 22);
    $file = dirname(__DIR__, 2) . '/app/config/migracion_v25.sql';
    $stmt = $db->prepare(
        'INSERT INTO schema_migrations (migration,checksum,release_version) VALUES (?,?,?)'
    );
    $stmt->execute(['migracion_v25.sql', hash_file('sha256', $file), 'hostile-test']);

    $manager = new MigrationManager($db);
    $status = $manager->status();
    check('tracking hostil detecta hueco de orden', (bool) array_filter(
        $status['structural_mismatch'],
        static fn(string $item): bool => str_starts_with($item, 'migration_order_gap:')
    ));
    check('tracking hostil detecta efectos ausentes de v25', (bool) array_filter(
        $status['structural_mismatch'],
        static fn(string $item): bool => str_starts_with($item, 'applied_missing_effects:migracion_v25.sql:')
    ));
    $stopped = false;
    try {
        $manager->migratePending();
    } catch (RuntimeException $e) {
        $stopped = str_contains($e->getMessage(), 'inconsistente');
    }
    check('el migrador se detiene ante schema_migrations hostil', $stopped);
    check('no crea migration_batch al intentar reparar', !SchemaMigrationTestFactory::tableExists($db, 'migration_batch'));
    check('no registra migraciones adicionales', (int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 1);
} finally {
    SchemaMigrationTestFactory::drop($fixture);
}
finishTests();
