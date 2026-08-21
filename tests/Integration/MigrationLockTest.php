<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/SchemaMigrationTestFactory.php';

$fixture = SchemaMigrationTestFactory::create('advisory_lock');
try {
    $dbA = $fixture['db'];
    SchemaMigrationTestFactory::applyThrough($dbA, $fixture['name'], 21);
    (new MigrationManager($dbA))->baselineExisting();
    $dbB = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $fixture['name'] . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $lockName = 'gimnera:migrations:' . substr(hash('sha256', $fixture['name']), 0, 40);
    $stmt = $dbA->prepare('SELECT GET_LOCK(:name, 0)');
    $stmt->execute([':name' => $lockName]);
    check('migrador A adquiere el advisory lock', (int) $stmt->fetchColumn() === 1);

    $blocked = false;
    try {
        (new MigrationManager($dbB, null, 0))->migratePending();
    } catch (RuntimeException $e) {
        $blocked = str_contains($e->getMessage(), 'cerrojo');
    }
    check('migrador B falla explícitamente mientras A mantiene el lock', $blocked);
    check('B no ejecuta v23 bajo concurrencia', !SchemaMigrationTestFactory::indexExists(
        $dbB,
        'usuario',
        'idx_usuario_empresa_rol_orden'
    ));

    $release = $dbA->prepare('SELECT RELEASE_LOCK(:name)');
    $release->execute([':name' => $lockName]);
    check('migrador A libera el advisory lock', (int) $release->fetchColumn() === 1);
    (new MigrationManager($dbB, null, 0))->migratePending();
    check('B puede migrar después de liberar el lock', (new MigrationManager($dbB))->status()['pending'] === []);
} finally {
    SchemaMigrationTestFactory::drop($fixture);
}
finishTests();
