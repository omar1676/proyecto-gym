<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/SchemaMigrationTestFactory.php';

$fixture = SchemaMigrationTestFactory::create('partial_failure');
$migrationDir = sys_get_temp_dir() . '/gimnera_f19_migrations_' . bin2hex(random_bytes(6));
try {
    SchemaMigrationTestFactory::copyMigrationsThrough($migrationDir, 24);
    file_put_contents(
        $migrationDir . '/migracion_v25.sql',
        "CREATE TABLE obligacion_pago (id_obligacion BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB;\n"
        . "ESTA SENTENCIA FALLA DE FORMA CONTROLADA;\n"
        . "CREATE TABLE cobro (id_cobro BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB;\n"
        . "CREATE TABLE caja_sesion (id_sesion BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB;\n"
        . "CREATE TABLE caja_movimiento (id_movimiento BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB;\n",
        LOCK_EX
    );

    $db = $fixture['db'];
    SchemaMigrationTestFactory::applyThrough($db, $fixture['name'], 21);
    $manager = new MigrationManager($db, $migrationDir);
    $manager->baselineExisting();
    $failed = false;
    try {
        $manager->migratePending();
    } catch (RuntimeException $e) {
        $failed = str_contains($e->getMessage(), 'migracion_v25.sql falló');
    }
    check('una migración que falla a mitad devuelve error', $failed);
    check('el primer DDL confirma el estado parcial simulado', SchemaMigrationTestFactory::tableExists($db, 'obligacion_pago'));
    check('los DDL posteriores al fallo no se ejecutan', !SchemaMigrationTestFactory::tableExists($db, 'cobro'));
    check('la migración parcial no se registra', (int) $db->query(
        "SELECT COUNT(*) FROM schema_migrations WHERE migration='migracion_v25.sql'"
    )->fetchColumn() === 0);
    $status = $manager->status();
    check('el siguiente arranque detecta efectos parciales', in_array(
        'partial_effects_without_record:migracion_v25.sql',
        $status['structural_mismatch'],
        true
    ));
    $stopped = false;
    try {
        $manager->migratePending();
    } catch (RuntimeException $e) {
        $stopped = str_contains($e->getMessage(), 'inconsistente');
    }
    check('el siguiente arranque no declara éxito ni repite DDL', $stopped);
} finally {
    SchemaMigrationTestFactory::drop($fixture);
    if (is_dir($migrationDir)) {
        SchemaMigrationTestFactory::removeDirectory($migrationDir);
    }
}
finishTests();
