<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/RestaurantCatalogTestFactory.php';

function r02SecurityRejected(callable $operation): bool
{
    try { $operation(); return false; }
    catch (DomainException | InvalidArgumentException | RuntimeException | PDOException) { return true; }
}

$db = Database::getInstance()->getConnection();
$companies = [];
try {
    $a = RestaurantCatalogTestFactory::foundation($db, 'r02-security-a');
    $b = RestaurantCatalogTestFactory::foundation($db, 'r02-security-b');
    $companies = [$a['company_id'], $b['company_id']];
    $scopeA = RestaurantCatalogTestFactory::scope($a);
    $scopeB = RestaurantCatalogTestFactory::scope($b);
    $demoA = RestaurantCatalogTestFactory::demo($db, $a, 'security-a');
    $demoB = RestaurantCatalogTestFactory::demo($db, $b, 'security-b');
    $products = new RestaurantProductService($db, $a['actor_id']);
    $catalogs = new RestaurantCatalogService($db, $a['actor_id']);
    $modifiers = new RestaurantModifierService($db, $a['actor_id']);
    $pricing = new RestaurantPricingService($db, $a['actor_id']);
    $availability = new RestaurantAvailabilityService($db, $a['actor_id']);

    $tenantActor = RestaurantOrganizationTestFactory::createTenantActor($db, $a['company_id'], 'direccion');
    check('actor tenant no obtiene servicio R02 privilegiado', r02SecurityRejected(
        fn() => new RestaurantProductService($db, $tenantActor)
    ));

    $db->exec('UPDATE usuario SET activo=0 WHERE id_usuario=' . (int) $a['actor_id']);
    check('offboarding invalida una instancia R02 creada previamente', r02SecurityRejected(fn() => $catalogs->listCategories(
        $a['company_id'], $a['account_id'], $a['brand_id'], $demoA['catalog_id']
    )));
    $db->exec('UPDATE usuario SET activo=1 WHERE id_usuario=' . (int) $a['actor_id']);

    check('scope manipulado no enlaza producto A con categoría B', r02SecurityRejected(fn() => $products->linkCategory($scopeA + [
        'product_id' => $demoA['products']['Burger Demo'], 'category_id' => $demoB['categories']['Principales'],
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:foreign-category'),
    ])));
    check('scope manipulado no añade variante a producto B', r02SecurityRejected(fn() => $products->createVariant($scopeA + [
        'product_id' => $demoB['products']['Burger Demo'], 'label' => 'Ajena',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:foreign-variant'),
    ])));
    check('scope manipulado no adjunta grupo B a producto A', r02SecurityRejected(fn() => $modifiers->attachToProduct($scopeA + [
        'product_id' => $demoA['products']['Burger Demo'], 'group_id' => $demoB['groups']['Extras'],
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:foreign-group'),
    ])));
    check('scope manipulado no usa local B para precio A', r02SecurityRejected(fn() => $pricing->setPrice($scopeA + [
        'product_id' => $demoA['products']['Burger Demo'], 'amount' => '10.00',
        'scope_type' => 'LOCATION', 'location_id' => $b['location_id'],
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:foreign-price-location'),
    ])));
    check('scope manipulado no usa local B para disponibilidad A', r02SecurityRejected(fn() => $availability->setAvailability($scopeA + [
        'product_id' => $demoA['products']['Burger Demo'], 'is_available' => true,
        'scope_type' => 'LOCATION', 'location_id' => $b['location_id'],
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:foreign-availability-location'),
    ])));

    $dbCrossTenant = false;
    try {
        $stmt = $db->prepare(
            "INSERT INTO restaurant_catalog
             (id_restaurant_account,id_empresa,id_restaurant_brand,name,slug,status,version,idempotency_key,request_fingerprint)
             VALUES (:account,:company,:brand,'Cruce','cruce','DRAFT',1,:key,:fingerprint)"
        );
        $stmt->execute([
            ':account' => $a['account_id'], ':company' => $a['company_id'], ':brand' => $b['brand_id'],
            ':key' => RestaurantCatalogTestFactory::uuid('security:db-cross'), ':fingerprint' => str_repeat('a', 64),
        ]);
    } catch (PDOException $error) { $dbCrossTenant = (string) $error->getCode() === '23000'; }
    check('MariaDB rechaza marca cross-tenant aunque falle PHP', $dbCrossTenant);

    $hostileName = "Demo <script>alert(1)</script> ' OR 1=1 --";
    $hostile = $products->createProduct($scopeA + [
        'name' => $hostileName, 'description' => '<img src=x onerror=alert(1)>',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:hostile-text'),
    ]);
    $stored = $db->query('SELECT name FROM restaurant_product WHERE id_restaurant_product=' . $hostile['product_id'])->fetchColumn();
    check('payload SQLi/XSS queda como dato inerte parametrizado', $stored === $hostileName);
    check('payload hostil no altera otras filas', (int) $db->query(
        'SELECT COUNT(*) FROM restaurant_product WHERE id_empresa=' . $a['company_id']
    )->fetchColumn() === 4);

    foreach (['NaN','Infinity','1.001','9999999999999.99','-0.01'] as $index => $amount) {
        check('precio hostil rechazado: ' . $amount, r02SecurityRejected(fn() => $pricing->setPrice($scopeA + [
            'product_id' => $hostile['product_id'], 'amount' => $amount, 'scope_type' => 'BRAND',
            'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:amount:' . $index),
        ])));
    }
    check('moneda no habilitada se rechaza', r02SecurityRejected(fn() => $pricing->setPrice($scopeA + [
        'product_id' => $hostile['product_id'], 'amount' => '1.00', 'currency' => 'USD', 'scope_type' => 'BRAND',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:currency'),
    ])));
    check('canal inventado se rechaza', r02SecurityRejected(fn() => $availability->setAvailability($scopeA + [
        'product_id' => $hostile['product_id'], 'is_available' => true, 'scope_type' => 'CHANNEL',
        'channel' => 'ROOT_SHELL', 'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:channel'),
    ])));
    check('dimensiones incoherentes se rechazan', r02SecurityRejected(fn() => $pricing->setPrice($scopeA + [
        'product_id' => $hostile['product_id'], 'amount' => '1.00', 'scope_type' => 'BRAND',
        'location_id' => $a['location_id'], 'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:dimensions'),
    ])));

    foreach ([
        ['bad-negative', false, -1, 1], ['bad-min-max', true, 2, 1],
        ['bad-required', true, 0, 1], ['bad-optional', false, 1, 2], ['bad-max', false, 0, 51],
    ] as [$seed, $required, $min, $max]) {
        check('grupo imposible se rechaza: ' . $seed, r02SecurityRejected(fn() => $modifiers->createGroup($scopeA + [
            'name' => $seed, 'required' => $required, 'min_selections' => $min, 'max_selections' => $max,
            'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:group:' . $seed),
        ])));
    }

    $mediaBase = $scopeA + [
        'product_id' => $hostile['product_id'], 'byte_size' => 1024,
        'sha256' => str_repeat('c', 64), 'alt_text' => 'Sintético',
    ];
    $badMedia = [
        ['../../shell.php', 'image/jpeg', 1024],
        ['aa/' . str_repeat('a', 64) . '.php', 'image/jpeg', 1024],
        ['aa/' . str_repeat('a', 64) . '.jpg.php', 'image/jpeg', 1024],
        ['aa/' . str_repeat('a', 64) . '.svg', 'image/svg+xml', 1024],
        ['aa/' . str_repeat('a', 64) . '.png', 'image/jpeg', 1024],
        ['aa/' . str_repeat('a', 64) . '.jpg', 'image/jpeg', 10485761],
    ];
    foreach ($badMedia as $index => [$storage, $mime, $size]) {
        check('medio hostil rechazado #' . $index, r02SecurityRejected(fn() => $products->registerMedia(array_replace($mediaBase, [
            'storage_key' => $storage, 'mime_type' => $mime, 'byte_size' => $size,
            'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:media:' . $index),
        ]))));
    }
    check('declaración de alérgeno no admite código hostil', r02SecurityRejected(fn() => $products->declareAllergen($scopeA + [
        'product_id' => $hostile['product_id'], 'declaration_code' => '<script>', 'label' => 'x',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:allergen-code'),
    ])));

    $archived = $products->updateProduct($scopeA + [
        'product_id' => $hostile['product_id'], 'expected_version' => 1,
        'name' => $hostileName, 'description' => null, 'status' => 'ARCHIVED',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:archive'),
    ]);
    check('producto se archiva sin hard delete', $archived['version'] === 2);
    check('producto archivado no se reactiva inconsistentemente', r02SecurityRejected(fn() => $products->updateProduct($scopeA + [
        'product_id' => $hostile['product_id'], 'expected_version' => 2,
        'name' => 'Revive', 'status' => 'ACTIVE',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:reactivate'),
    ])));

    $beforeAuditFault = (int) $db->query('SELECT COUNT(*) FROM restaurant_product WHERE id_empresa=' . $a['company_id'])->fetchColumn();
    $db->exec("CREATE TRIGGER r02_reject_audit BEFORE INSERT ON log_actividad FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'");
    try {
        check('fallo de auditoría REQUIRED rechaza mutación', r02SecurityRejected(fn() => $products->createProduct($scopeA + [
            'name' => 'Audit must rollback', 'idempotency_key' => RestaurantCatalogTestFactory::uuid('security:audit-fault'),
        ])));
    } finally {
        $db->exec('DROP TRIGGER IF EXISTS r02_reject_audit');
    }
    check('fallo de auditoría revierte producto', (int) $db->query(
        'SELECT COUNT(*) FROM restaurant_product WHERE id_empresa=' . $a['company_id']
    )->fetchColumn() === $beforeAuditFault);

    $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    check('R02 no publica rutas HTTP Restaurant', !str_contains($routes, 'restaurant_catalog') && !str_contains($routes, '/restaurants'));
    check('R02 no crea entidades prohibidas', (int) $db->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()
          AND table_name IN ('restaurant_order','restaurant_ticket','restaurant_payment','restaurant_stock','restaurant_kitchen','restaurant_recipe')"
    )->fetchColumn() === 0);
} catch (Throwable $error) {
    check('seguridad R02 completa', false);
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
} finally {
    $db->exec('DROP TRIGGER IF EXISTS r02_reject_audit');
    RestaurantCatalogTestFactory::cleanup($db, $companies);
}

finishTests();
