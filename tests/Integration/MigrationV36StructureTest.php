<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/SchemaMigrationTestFactory.php';
require_once dirname(__DIR__, 2) . '/app/helpers/SchemaCompatibility.php';

$fixture = SchemaMigrationTestFactory::create('v36_restaurant_catalog');
try {
    SchemaMigrationTestFactory::applyThrough($fixture['db'], $fixture['name'], 35);
    $manager = new MigrationManager($fixture['db'], dirname(__DIR__, 2) . '/app/config', 0);
    $manager->baselineExisting();
    $track = $fixture['db']->prepare(
        'INSERT INTO schema_migrations (migration,checksum,release_version) VALUES (?,?,?)'
    );
    for ($version = 23; $version <= 35; $version++) {
        $file = dirname(__DIR__, 2) . '/app/config/migracion_v' . $version . '.sql';
        $track->execute([basename($file), hash_file('sha256', $file), 'restaurants-test-v35']);
    }

    check('v36 es la única migración pendiente', $manager->status()['pending'] === ['migracion_v36.sql']);
    check('v36 se ejecuta realmente', $manager->migratePending() === ['migracion_v36.sql']);
    $tables = [
        'restaurant_catalog', 'restaurant_catalog_location', 'restaurant_category',
        'restaurant_product', 'restaurant_product_category', 'restaurant_product_variant',
        'restaurant_modifier_group', 'restaurant_modifier', 'restaurant_product_modifier_group',
        'restaurant_price', 'restaurant_price_history', 'restaurant_availability',
        'restaurant_availability_history', 'restaurant_product_allergen_declaration',
        'restaurant_product_media', 'restaurant_catalog_mutation',
    ];
    foreach ($tables as $table) {
        check('v36 crea ' . $table, SchemaMigrationTestFactory::tableExists($fixture['db'], $table));
    }
    foreach ([
        ['restaurant_location', 'uq_restaurant_location_brand_scope'],
        ['restaurant_catalog', 'uq_restaurant_catalog_scope'],
        ['restaurant_catalog', 'uq_restaurant_catalog_idempotency'],
        ['restaurant_category', 'uq_restaurant_category_scope'],
        ['restaurant_product', 'uq_restaurant_product_scope'],
        ['restaurant_product_variant', 'uq_restaurant_variant_scope'],
        ['restaurant_modifier_group', 'uq_restaurant_modifier_group_scope'],
        ['restaurant_price', 'uq_restaurant_price_scope_key'],
        ['restaurant_availability', 'uq_restaurant_availability_scope_key'],
        ['restaurant_catalog_mutation', 'uq_restaurant_catalog_mutation_key'],
    ] as [$table, $index]) {
        check('v36 crea ' . $index, SchemaMigrationTestFactory::indexExists($fixture['db'], $table, $index));
    }
    $moneyTypes = $fixture['db']->query(
        "SELECT table_name,column_name,data_type
           FROM information_schema.columns
          WHERE table_schema=DATABASE()
            AND (column_name LIKE '%amount_minor' OR column_name='price_delta_minor')"
    )->fetchAll(PDO::FETCH_ASSOC);
    check('todos los importes R02 usan enteros exactos', count($moneyTypes) >= 4 && array_reduce(
        $moneyTypes,
        static fn(bool $ok, array $row): bool => $ok && $row['data_type'] === 'bigint',
        true
    ));
    check('v36 termina sin mismatch estructural', $manager->status()['structural_mismatch'] === []);
    check('v36 no se aplica dos veces', $manager->migratePending() === []);

    $fixture['db']->exec("UPDATE schema_migrations SET checksum=REPEAT('0',64) WHERE migration='migracion_v36.sql'");
    check('checksum incorrecto de v36 se detecta', $manager->status()['checksum_mismatch'] === ['migracion_v36.sql']);
    $correctChecksum = hash_file('sha256', dirname(__DIR__, 2) . '/app/config/migracion_v36.sql');
    $restoreChecksum = $fixture['db']->prepare("UPDATE schema_migrations SET checksum=? WHERE migration='migracion_v36.sql'");
    $restoreChecksum->execute([$correctChecksum]);
    $future = $fixture['db']->prepare('INSERT INTO schema_migrations (migration,checksum,release_version) VALUES (?,?,?)');
    $future->execute(['migracion_v37.sql', str_repeat('f', 64), 'synthetic-future']);
    check('schema futuro desconocido se detecta', in_array('unknown_migration:migracion_v37.sql', $manager->status()['structural_mismatch'], true));
    $futureRuntimeRejected = false;
    try { SchemaCompatibility::assertRuntime($fixture['db'], dirname(__DIR__, 2)); } catch (RuntimeException) { $futureRuntimeRejected = true; }
    check('runtime v36 rechaza schema futuro v37', $futureRuntimeRejected);
    $fixture['db']->exec("DELETE FROM schema_migrations WHERE migration='migracion_v37.sql'");

    $fixture['db']->exec('ALTER TABLE restaurant_price DROP INDEX uq_restaurant_price_scope_key');
    check('schema gate detecta unicidad de precio ausente', (bool) array_filter(
        $manager->status()['structural_mismatch'],
        static fn(string $item): bool => str_contains($item, 'uq_restaurant_price_scope_key')
    ));
    $fixture['db']->exec('ALTER TABLE restaurant_price DROP FOREIGN KEY fk_restaurant_price_location_scope');
    check('schema gate detecta FK de local ausente', (bool) array_filter(
        $manager->status()['structural_mismatch'],
        static fn(string $item): bool => str_contains($item, 'fk_restaurant_price_location_scope')
    ));
    $fixture['db']->exec('ALTER TABLE restaurant_modifier_group DROP CONSTRAINT chk_restaurant_modifier_group_bounds');
    check('schema gate detecta CHECK min/max ausente', (bool) array_filter(
        $manager->status()['structural_mismatch'],
        static fn(string $item): bool => str_contains($item, 'chk_restaurant_modifier_group_bounds')
    ));
} finally {
    SchemaMigrationTestFactory::drop($fixture);
}

$partial = SchemaMigrationTestFactory::create('v36_partial_catalog');
try {
    SchemaMigrationTestFactory::applyThrough($partial['db'], $partial['name'], 35);
    $manager = new MigrationManager($partial['db'], dirname(__DIR__, 2) . '/app/config', 0);
    $manager->baselineExisting();
    $track = $partial['db']->prepare(
        'INSERT INTO schema_migrations (migration,checksum,release_version) VALUES (?,?,?)'
    );
    for ($version = 23; $version <= 35; $version++) {
        $file = dirname(__DIR__, 2) . '/app/config/migracion_v' . $version . '.sql';
        $track->execute([basename($file), hash_file('sha256', $file), 'restaurants-test-v35']);
    }
    $partial['db']->exec(
        'ALTER TABLE restaurant_location ADD UNIQUE KEY uq_restaurant_location_brand_scope '
        . '(id_restaurant_location,id_restaurant_account,id_empresa,id_restaurant_brand)'
    );
    check('v36 parcial sin tracking se detecta', in_array(
        'partial_effects_without_record:migracion_v36.sql',
        $manager->status()['structural_mismatch'],
        true
    ));
    $blocked = false;
    try { $manager->migratePending(); } catch (RuntimeException) { $blocked = true; }
    check('v36 parcial no se reanuda a ciegas', $blocked);
} finally {
    SchemaMigrationTestFactory::drop($partial);
}

finishTests();
