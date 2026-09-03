<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/SchemaMigrationTestFactory.php';

$fixture = SchemaMigrationTestFactory::create('v35_restaurants');
try {
    SchemaMigrationTestFactory::applyThrough($fixture['db'], $fixture['name'], 34);
    $manager = new MigrationManager($fixture['db'], dirname(__DIR__, 2) . '/app/config', 0);
    $manager->baselineExisting();
    $track = $fixture['db']->prepare(
        'INSERT INTO schema_migrations (migration,checksum,release_version) VALUES (?,?,?)'
    );
    for ($version = 23; $version <= 34; $version++) {
        $file = dirname(__DIR__, 2) . '/app/config/migracion_v' . $version . '.sql';
        $track->execute([basename($file), hash_file('sha256', $file), 'restaurants-test-v34']);
    }

    check('v35 es la única migración pendiente', $manager->status()['pending'] === ['migracion_v35.sql']);
    check('v35 se ejecuta realmente', $manager->migratePending() === ['migracion_v35.sql']);
    foreach (['restaurant_account', 'restaurant_brand', 'restaurant_legal_entity', 'restaurant_location'] as $table) {
        check('v35 crea ' . $table, SchemaMigrationTestFactory::tableExists($fixture['db'], $table));
    }
    foreach ([
        ['restaurant_account', 'uq_restaurant_account_company'],
        ['restaurant_account', 'uq_restaurant_account_idempotency'],
        ['restaurant_account', 'uq_restaurant_account_scope'],
        ['restaurant_brand', 'uq_restaurant_brand_company_slug'],
        ['restaurant_brand', 'uq_restaurant_brand_scope'],
        ['restaurant_legal_entity', 'uq_restaurant_legal_company_code'],
        ['restaurant_legal_entity', 'uq_restaurant_legal_scope'],
        ['restaurant_location', 'uq_restaurant_location_company_slug'],
        ['restaurant_location', 'uq_restaurant_location_scope'],
    ] as [$table, $index]) {
        check('v35 crea ' . $index, SchemaMigrationTestFactory::indexExists($fixture['db'], $table, $index));
    }

    $foreignKeys = (int) $fixture['db']->query(
        "SELECT COUNT(*) FROM information_schema.referential_constraints
          WHERE constraint_schema=DATABASE() AND constraint_name LIKE 'fk_restaurant_%'"
    )->fetchColumn();
    check('v35 instala seis defensas FK', $foreignKeys === 6);
    $checks = (int) $fixture['db']->query(
        "SELECT COUNT(*) FROM information_schema.table_constraints
          WHERE constraint_schema=DATABASE() AND constraint_type='CHECK'
            AND constraint_name LIKE 'chk_restaurant_%'"
    )->fetchColumn();
    check('v35 instala cuatro checks de versión', $checks === 4);
    check('v35 no se reaplica', $manager->migratePending() === []);
    check('v35 termina sin mismatch', $manager->status()['structural_mismatch'] === []);

    $fixture['db']->exec('ALTER TABLE restaurant_account DROP INDEX uq_restaurant_account_idempotency');
    check('schema gate detecta idempotencia ausente', (bool) array_filter(
        $manager->status()['structural_mismatch'],
        static fn(string $item): bool => str_contains($item, 'uq_restaurant_account_idempotency')
    ));
    $fixture['db']->exec(
        'ALTER TABLE restaurant_location DROP FOREIGN KEY fk_restaurant_location_brand_scope'
    );
    check('schema gate detecta FK tenant-aware ausente', (bool) array_filter(
        $manager->status()['structural_mismatch'],
        static fn(string $item): bool => str_contains($item, 'fk_restaurant_location_brand_scope')
    ));
} finally {
    SchemaMigrationTestFactory::drop($fixture);
}
finishTests();
