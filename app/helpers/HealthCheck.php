<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/MigrationManager.php';
require_once __DIR__ . '/SchemaCompatibility.php';

final class HealthCheck
{
    public static function run(): array
    {
        $checks = ['database' => false, 'schema' => false, 'runtime_schema' => false, 'disk' => false];
        $failedComponent = null;
        try {
            $db = Database::getInstance()->getConnection();
            $checks['database'] = (int) $db->query('SELECT 1')->fetchColumn() === 1;
            if (!$checks['database']) {
                $failedComponent = 'database';
                throw new RuntimeException('Database check failed.');
            }
            $schema = (new MigrationManager($db))->status();
            $checks['schema'] = $schema['initialized'] && !$schema['pending']
                && !$schema['checksum_mismatch'] && empty($schema['structural_mismatch']);
            if (!$checks['schema']) {
                $failedComponent = 'schema';
                throw new RuntimeException('Schema check failed.');
            }
            SchemaCompatibility::assertRuntime($db, dirname(__DIR__, 2));
            $checks['runtime_schema'] = true;
        } catch (Throwable $e) {
            // El endpoint público nunca devuelve el detalle.
            $failedComponent ??= $checks['database'] ? 'schema' : 'database';
        }
        $total = @disk_total_space(dirname(__DIR__, 2));
        $free = @disk_free_space(dirname(__DIR__, 2));
        $checks['disk'] = $total && $free && (($free / $total) * 100 >= DISCO_LIBRE_MINIMO_PCT);
        if (!$checks['disk'] && $failedComponent === null) {
            $failedComponent = 'disk';
        }
        return [
            'ok' => !in_array(false, $checks, true),
            'component' => $failedComponent,
            'checks' => $checks,
        ];
    }
}
