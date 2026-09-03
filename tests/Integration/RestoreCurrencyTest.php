<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/SchemaMigrationTestFactory.php';
require_once dirname(__DIR__, 2) . '/app/helpers/RestoreVerifier.php';

$fixture = SchemaMigrationTestFactory::create('restore_old_valid');
$migrationDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gimnera_f211_migrations_' . bin2hex(random_bytes(6));
$artifact = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gimnera_f211_restore_' . bin2hex(random_bytes(6)) . '.sql.gz';
try {
    SchemaMigrationTestFactory::copyMigrationsThrough($migrationDir, 27);
    (new MigrationManager($fixture['db'], $migrationDir))->migrateFresh();

    file_put_contents($artifact, 'synthetic-backup-artifact');
    $sha = (string) hash_file('sha256', $artifact);
    file_put_contents($artifact . '.sha256', $sha . '  ' . basename($artifact) . PHP_EOL);
    file_put_contents($artifact . '.manifest.json', json_encode([
        'kind' => 'database',
        'artifact' => basename($artifact),
        'size_bytes' => filesize($artifact),
        'sha256' => $sha,
    ], JSON_PRETTY_PRINT));

    $before = RestoreVerifier::verify($fixture['db'], dirname(__DIR__, 2) . '/app/config', $artifact);
    check('backup antiguo válido no se presenta como corrupción', $before['BACKUP_INTEGRITY'] === 'OK');
    check('verificador separa esquema v27 de actual v34', $before['SCHEMA_AT_BACKUP'] === 27 && $before['CURRENT_SCHEMA'] === 34);
    check('verificador exige migración sin invalidar integridad', $before['SCHEMA_CURRENCY'] === 'OLD' && $before['MIGRATION_REQUIRED'] === 'YES');

    $currentManager = new MigrationManager($fixture['db']);
    check('migración posterior del restore aplica v28-v34', $currentManager->migratePending() === ['migracion_v28.sql', 'migracion_v29.sql', 'migracion_v30.sql', 'migracion_v31.sql', 'migracion_v32.sql', 'migracion_v33.sql', 'migracion_v34.sql']);
    $after = RestoreVerifier::verify($fixture['db'], dirname(__DIR__, 2) . '/app/config', $artifact);
    check('restore migrado conserva integridad y queda current', $after['BACKUP_INTEGRITY'] === 'OK'
        && $after['SCHEMA_CURRENCY'] === 'CURRENT'
        && $after['MIGRATION_REQUIRED'] === 'NO');
} finally {
    @unlink($artifact);
    @unlink($artifact . '.sha256');
    @unlink($artifact . '.manifest.json');
    if (is_dir($migrationDir)) SchemaMigrationTestFactory::removeDirectory($migrationDir);
    SchemaMigrationTestFactory::drop($fixture);
}

finishTests();
