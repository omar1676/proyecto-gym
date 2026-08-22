<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/SchemaMigrationTestFactory.php';

$fixture = SchemaMigrationTestFactory::create('v28_structure_complete');
try {
    $db = $fixture['db'];
    $manager = new MigrationManager($db);
    $applied = $manager->migrateFresh();
    check('instalación limpia ejecuta v28', in_array('migracion_v28.sql', $applied, true));

    foreach (['event_id', 'correlation_id', 'actor_type', 'origin', 'reason_code', 'metadata_json'] as $column) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.columns '
            . 'WHERE table_schema=DATABASE() AND table_name="log_actividad" AND column_name=:column'
        );
        $stmt->execute([':column' => $column]);
        check("v28 crea columna log_actividad.{$column}", (int) $stmt->fetchColumn() === 1);
    }
    foreach (['uq_log_event_id', 'idx_log_correlation', 'idx_log_empresa_origen_fecha'] as $index) {
        check("v28 crea índice log_actividad.{$index}", SchemaMigrationTestFactory::indexExists($db, 'log_actividad', $index));
    }

    check('segunda ejecución no reaplica v28', $manager->migratePending() === []);
    $status = $manager->status();
    check('v28 completa queda sin pendientes ni inconsistencias', $status['pending'] === [] && $status['checksum_mismatch'] === [] && $status['structural_mismatch'] === []);

    $db->exec('DROP INDEX idx_log_empresa_origen_fecha ON log_actividad');
    $hostile = $manager->status();
    check('falta del tercer índice v28 se detecta físicamente', (bool) array_filter(
        $hostile['structural_mismatch'],
        static fn(string $item): bool => str_contains($item, 'idx_log_empresa_origen_fecha')
    ));
    $stopped = false;
    try {
        $manager->migratePending();
    } catch (RuntimeException $e) {
        $stopped = str_contains($e->getMessage(), 'inconsistente');
    }
    check('migrador no declara éxito con v28 estructuralmente incompleta', $stopped);
} finally {
    SchemaMigrationTestFactory::drop($fixture);
}

finishTests();
