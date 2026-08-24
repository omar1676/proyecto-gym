<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/SchemaMigrationTestFactory.php';

$fixture = SchemaMigrationTestFactory::create('v30_structure');
try {
    SchemaMigrationTestFactory::applyThrough($fixture['db'], $fixture['name'], 29);
    $manager = new MigrationManager($fixture['db'], dirname(__DIR__, 2) . '/app/config', 0);
    $manager->baselineExisting();
    $track = $fixture['db']->prepare(
        'INSERT INTO schema_migrations (migration,checksum,release_version) VALUES (?,?,?)'
    );
    for ($version = 23; $version <= 29; $version++) {
        $file = dirname(__DIR__, 2) . '/app/config/migracion_v' . $version . '.sql';
        $track->execute([basename($file), hash_file('sha256', $file), 'f23-test-v29']);
    }
    check('v29 inicia sin profile_version', (int) $fixture['db']->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='usuario' AND column_name='profile_version'"
    )->fetchColumn() === 0);
    check('v30 se ejecuta realmente antes de las posteriores', $manager->migratePending() === [
        'migracion_v30.sql', 'migracion_v31.sql',
    ]);
    check('v30 crea profile_version NOT NULL', (string) $fixture['db']->query(
        "SELECT CONCAT(IS_NULLABLE,':',COLUMN_DEFAULT,':',COLUMN_TYPE) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='usuario' AND column_name='profile_version'"
    )->fetchColumn() === 'NO:1:int(10) unsigned');
    check('segunda ejecución no reaplica v30', $manager->migratePending() === []);
    $fixture['db']->exec('ALTER TABLE usuario DROP COLUMN profile_version');
    $status = $manager->status();
    check('schema gate detecta v30 estructuralmente incompleta', (bool) array_filter(
        $status['structural_mismatch'], static fn(string $item): bool => str_contains($item, 'usuario.profile_version')
    ));
} finally {
    SchemaMigrationTestFactory::drop($fixture);
}

finishTests();
