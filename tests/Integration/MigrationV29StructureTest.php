<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/SchemaMigrationTestFactory.php';

$fixture = SchemaMigrationTestFactory::create('v29_structure_complete');
try {
    $db = $fixture['db'];
    $manager = new MigrationManager($db);
    $applied = $manager->migrateFresh();
    check('instalación limpia ejecuta v29', in_array('migracion_v29.sql', $applied, true));

    foreach (['slug', 'onboarding_key', 'onboarding_state', 'onboarding_updated_at'] as $column) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="empresa" AND column_name=:column');
        $stmt->execute([':column' => $column]);
        check("v29 crea empresa.{$column}", (int) $stmt->fetchColumn() === 1);
    }
    foreach (['identidad_empresa_scope'] as $column) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="usuario" AND column_name=:column');
        $stmt->execute([':column' => $column]);
        check("v29 crea usuario.{$column}", (int) $stmt->fetchColumn() === 1);
    }
    $stmt = $db->prepare('SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name="categoria_producto" AND column_name="id_empresa"');
    $stmt->execute();
    check('categoría pasa a tenant y no admite NULL', $stmt->fetchColumn() === 'NO');

    foreach ([
        ['empresa','uq_empresa_slug'], ['empresa','uq_empresa_onboarding_key'],
        ['empresa','idx_empresa_onboarding_state'],
        ['usuario','uq_usuario_empresa_dni'], ['usuario','uq_usuario_empresa_email'],
        ['usuario','uq_usuario_empresa_username'], ['gimnasio','uq_gimnasio_empresa_nombre'],
        ['categoria_producto','uq_categoria_empresa_nombre'],
    ] as [$table, $index]) {
        check("v29 crea {$table}.{$index}", SchemaMigrationTestFactory::indexExists($db, $table, $index));
    }

    check('segunda ejecución no reaplica v29', $manager->migratePending() === []);
    $status = $manager->status();
    check('v29 completa queda sin pendientes ni inconsistencias', $status['pending'] === [] && $status['checksum_mismatch'] === [] && $status['structural_mismatch'] === []);

    $db->exec('DROP INDEX uq_categoria_empresa_nombre ON categoria_producto');
    $hostile = $manager->status();
    check('falta de aislamiento estructural v29 se detecta', (bool) array_filter(
        $hostile['structural_mismatch'],
        static fn(string $item): bool => str_contains($item, 'uq_categoria_empresa_nombre')
    ));
    $stopped = false;
    try { $manager->migratePending(); } catch (RuntimeException $e) { $stopped = str_contains($e->getMessage(), 'inconsistente'); }
    check('migrador no declara éxito con v29 incompleta', $stopped);
} finally {
    SchemaMigrationTestFactory::drop($fixture);
}

$legacy = SchemaMigrationTestFactory::create('v29_legacy_categories');
try {
    $db = $legacy['db'];
    SchemaMigrationTestFactory::applyThrough($db, $legacy['name'], 28);
    $companyA = (int) $db->query('SELECT MIN(id_empresa) FROM empresa')->fetchColumn();
    $db->exec("INSERT INTO empresa (nombre,nombre_comercial) VALUES ('TEST F22 Legacy B','Legacy B')");
    $companyB = (int) $db->lastInsertId();
    $db->exec("INSERT INTO gimnasio (id_empresa,nombre,slug,email_acceso,activo) VALUES ({$companyA},'TEST F22 Legacy A','legacy-a','legacy-a@test.invalid',1)");
    $siteA = (int) $db->lastInsertId();
    $db->exec("INSERT INTO gimnasio (id_empresa,nombre,slug,email_acceso,activo) VALUES ({$companyB},'TEST F22 Legacy B','legacy-b','legacy-b@test.invalid',1)");
    $siteB = (int) $db->lastInsertId();
    $db->exec("INSERT INTO categoria_producto (nombre_categoria) VALUES ('Compartida Legacy')");
    $category = (int) $db->lastInsertId();
    $db->exec("INSERT INTO producto (nombre,precio,stock,stock_minimo,estado,id_categoria,id_gimnasio) VALUES ('Legacy A',1.00,1,0,'activo',{$category},{$siteA}),('Legacy B',1.00,1,0,'activo',{$category},{$siteB})");

    $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/app/config/migracion_v29.sql');
    $db->exec($sql);
    check('v29 duplica categoría legacy usada por dos empresas', (int) $db->query("SELECT COUNT(*) FROM categoria_producto WHERE nombre_categoria='Compartida Legacy'")->fetchColumn() === 2);
    check('v29 remapea cada producto a categoría de su empresa', (int) $db->query(
        "SELECT COUNT(*) FROM producto p JOIN gimnasio g ON g.id_gimnasio=p.id_gimnasio JOIN categoria_producto c ON c.id_categoria=p.id_categoria AND c.id_empresa=g.id_empresa WHERE p.nombre IN ('Legacy A','Legacy B')"
    )->fetchColumn() === 2);
    check('v29 no pierde productos legacy', (int) $db->query("SELECT COUNT(*) FROM producto WHERE nombre IN ('Legacy A','Legacy B')")->fetchColumn() === 2);
} finally {
    SchemaMigrationTestFactory::drop($legacy);
}

finishTests();
