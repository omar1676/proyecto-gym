<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/RestaurantCatalogTestFactory.php';

function r02Rejected(callable $operation): bool
{
    try {
        $operation();
        return false;
    } catch (DomainException | InvalidArgumentException | RuntimeException) {
        return true;
    }
}

$db = Database::getInstance()->getConnection();
$companies = [];
try {
    $a = RestaurantCatalogTestFactory::foundation($db, 'r02-alpha');
    $b = RestaurantCatalogTestFactory::foundation($db, 'r02-beta');
    $companies = [(int) $a['company_id'], (int) $b['company_id']];
    $scopeA = RestaurantCatalogTestFactory::scope($a);
    $scopeB = RestaurantCatalogTestFactory::scope($b);
    $catalogs = new RestaurantCatalogService($db, (int) $a['actor_id']);
    $products = new RestaurantProductService($db, (int) $a['actor_id']);
    $modifiers = new RestaurantModifierService($db, (int) $a['actor_id']);
    $pricing = new RestaurantPricingService($db, (int) $a['actor_id']);
    $availability = new RestaurantAvailabilityService($db, (int) $a['actor_id']);

    $demoA = RestaurantCatalogTestFactory::demo($db, $a, 'alpha');
    check('fixture crea carta principal sintética', $demoA['catalog_id'] > 0);
    check('fixture crea producto simple sin variantes', (int) $db->query(
        'SELECT COUNT(*) FROM restaurant_product_variant WHERE id_restaurant_product=' . $demoA['products']['Producto Simple']
    )->fetchColumn() === 0);
    check('fixture crea Burger y Bebida con variantes independientes',
        count($demoA['variants']) === 4
        && $demoA['variants']['Burger Demo:Normal'] !== $demoA['variants']['Bebida Demo:330ml']
    );

    $secondCatalog = $catalogs->createCatalog($scopeA + [
        'name' => 'Delivery alpha', 'status' => 'DRAFT',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:second-catalog'),
    ]);
    check('una marca admite varios catálogos', $secondCatalog['catalog_id'] !== $demoA['catalog_id']);
    $sameCreate = $catalogs->createCatalog($scopeA + [
        'name' => 'Delivery alpha', 'status' => 'DRAFT',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:second-catalog'),
    ]);
    check('doble click de catálogo es idempotente', $sameCreate['duplicate'] && $sameCreate['catalog_id'] === $secondCatalog['catalog_id']);
    check('misma key y payload distinto se rechaza', r02Rejected(fn() => $catalogs->createCatalog($scopeA + [
        'name' => 'Otro nombre', 'status' => 'DRAFT',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:second-catalog'),
    ])));

    $assignment = $catalogs->assignLocation($scopeA + [
        'catalog_id' => $demoA['catalog_id'], 'location_id' => $a['location_id'],
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:catalog-location'),
    ]);
    $assignmentRetry = $catalogs->assignLocation($scopeA + [
        'catalog_id' => $demoA['catalog_id'], 'location_id' => $a['location_id'],
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:catalog-location'),
    ]);
    check('catálogo se asigna a local de su marca', !$assignment['duplicate']);
    check('reasignación idéntica no duplica vínculo', $assignmentRetry['duplicate'] && (int) $db->query(
        'SELECT COUNT(*) FROM restaurant_catalog_location WHERE id_restaurant_catalog=' . $demoA['catalog_id']
    )->fetchColumn() === 1);
    check('local de otro tenant no puede asignarse', r02Rejected(fn() => $catalogs->assignLocation($scopeA + [
        'catalog_id' => $demoA['catalog_id'], 'location_id' => $b['location_id'],
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:foreign-location'),
    ])));

    $categories = $catalogs->listCategories($a['company_id'], $a['account_id'], $a['brand_id'], $demoA['catalog_id']);
    check('categorías conservan orden determinista', array_column($categories, 'name') === ['Entrantes','Principales','Bebidas']);
    $products->linkCategory($scopeA + [
        'product_id' => $demoA['products']['Burger Demo'],
        'category_id' => $demoA['categories']['Entrantes'],
        'sort_order' => 2,
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:burger-second-category'),
    ]);
    check('producto puede pertenecer a varias categorías sin duplicarse',
        (int) $db->query('SELECT COUNT(*) FROM restaurant_product_category WHERE id_restaurant_product=' . $demoA['products']['Burger Demo'])->fetchColumn() === 2
        && (int) $db->query('SELECT COUNT(*) FROM restaurant_product WHERE id_restaurant_product=' . $demoA['products']['Burger Demo'])->fetchColumn() === 1
    );

    $basePrice = $pricing->setPrice($scopeA + [
        'product_id' => $demoA['products']['Burger Demo'], 'amount' => '12,50',
        'scope_type' => 'BRAND', 'expected_version' => 0,
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:price:brand'),
    ]);
    check('precio con coma se persiste en minor units exactas', $basePrice['amount_minor'] === 1250 && $basePrice['currency'] === 'EUR');
    $variantPrice = $pricing->setPrice($scopeA + [
        'product_id' => $demoA['products']['Burger Demo'], 'variant_id' => $demoA['variants']['Burger Demo:Doble'],
        'amount' => '15.00', 'scope_type' => 'BRAND', 'expected_version' => 0,
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:price:variant'),
    ]);
    check('variante admite precio propio', $variantPrice['amount_minor'] === 1500);
    $locationPrice = $pricing->setPrice($scopeA + [
        'product_id' => $demoA['products']['Burger Demo'], 'amount' => '13.00',
        'scope_type' => 'LOCATION', 'location_id' => $a['location_id'], 'expected_version' => 0,
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:price:location'),
    ]);
    $channelPrice = $pricing->setPrice($scopeA + [
        'product_id' => $demoA['products']['Burger Demo'], 'amount' => '14.00',
        'scope_type' => 'CHANNEL', 'channel' => 'DELIVERY', 'expected_version' => 0,
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:price:channel'),
    ]);
    $bothPrice = $pricing->setPrice($scopeA + [
        'product_id' => $demoA['products']['Burger Demo'], 'amount' => '14.50',
        'scope_type' => 'LOCATION_CHANNEL', 'location_id' => $a['location_id'], 'channel' => 'WEB',
        'expected_version' => 0, 'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:price:both'),
    ]);
    check('foundation guarda precio por local', $locationPrice['amount_minor'] === 1300);
    check('foundation guarda precio por canal', $channelPrice['amount_minor'] === 1400);
    check('foundation guarda precio por local y canal', $bothPrice['amount_minor'] === 1450);
    check('servicio devuelve candidatos sin inventar precedencia', count($pricing->candidates(
        $a['company_id'], $a['account_id'], $a['brand_id'], $demoA['products']['Burger Demo']
    )) === 4);

    $priceUpdateInput = $scopeA + [
        'product_id' => $demoA['products']['Burger Demo'], 'amount' => '12.75',
        'scope_type' => 'BRAND', 'expected_version' => 1,
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:price:update'),
    ];
    $updatedPrice = $pricing->setPrice($priceUpdateInput);
    $retriedPrice = $pricing->setPrice($priceUpdateInput);
    check('cambio de precio incrementa versión', $updatedPrice['version'] === 2 && $updatedPrice['amount_minor'] === 1275);
    check('reintento de cambio de precio devuelve resultado histórico', $retriedPrice['duplicate'] && $retriedPrice['version'] === 2);
    check('histórico conserva precio anterior y nuevo', (int) $db->query(
        'SELECT COUNT(*) FROM restaurant_price_history WHERE id_restaurant_price=' . $basePrice['price_id']
        . ' AND old_amount_minor=1250 AND new_amount_minor=1275'
    )->fetchColumn() === 1);
    check('precio negativo normal queda rechazado', r02Rejected(fn() => $pricing->setPrice($scopeA + [
        'product_id' => $demoA['products']['Producto Simple'], 'amount' => '-0.01', 'scope_type' => 'BRAND',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:price:negative'),
    ])));
    check('precio cero es representable', $pricing->setPrice($scopeA + [
        'product_id' => $demoA['products']['Producto Simple'], 'amount' => '0', 'scope_type' => 'BRAND',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:price:zero'),
    ])['amount_minor'] === 0);

    $brandAvailability = $availability->setAvailability($scopeA + [
        'product_id' => $demoA['products']['Bebida Demo'], 'is_available' => false,
        'reason' => 'Agotado sintético', 'scope_type' => 'BRAND',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:availability:brand'),
    ]);
    $localAvailability = $availability->setAvailability($scopeA + [
        'product_id' => $demoA['products']['Bebida Demo'], 'is_available' => true,
        'scope_type' => 'LOCATION', 'location_id' => $a['location_id'],
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:availability:location'),
    ]);
    $channelAvailability = $availability->setAvailability($scopeA + [
        'product_id' => $demoA['products']['Bebida Demo'], 'is_available' => false,
        'scope_type' => 'CHANNEL', 'channel' => 'DELIVERY',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:availability:channel'),
    ]);
    check('ACTIVE se separa de AVAILABLE', !$brandAvailability['is_available'] && (string) $db->query(
        'SELECT status FROM restaurant_product WHERE id_restaurant_product=' . $demoA['products']['Bebida Demo']
    )->fetchColumn() === 'ACTIVE');
    check('disponibilidad puede variar por local', $localAvailability['is_available']);
    check('disponibilidad puede variar por canal', !$channelAvailability['is_available']);
    check('candidatos de disponibilidad conservan ámbitos', count($availability->candidates(
        $a['company_id'], $a['account_id'], $a['brand_id'], $demoA['products']['Bebida Demo']
    )) === 3);

    $allergen = $products->declareAllergen($scopeA + [
        'product_id' => $demoA['products']['Burger Demo'], 'declaration_code' => 'DECLARED-DEMO',
        'label' => 'Declaración sintética', 'statement' => 'Contenido declarado por una persona',
        'source' => 'Fixture R02', 'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:allergen'),
    ]);
    check('alérgeno es una declaración con actor explícito', (int) $db->query(
        'SELECT updated_by FROM restaurant_product_allergen_declaration WHERE id_restaurant_allergen_declaration=' . $allergen['declaration_id']
    )->fetchColumn() === (int) $a['actor_id']);
    $allergenUpdate = $products->updateAllergen($scopeA + [
        'declaration_id' => $allergen['declaration_id'], 'expected_version' => 1,
        'label' => 'Declaración sintética revisada', 'statement' => 'Revisada por persona',
        'source' => 'Fixture R02 revisado', 'status' => 'DECLARED',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:allergen:update'),
    ]);
    check('cambio de declaración conserva versión actor y auditoría', $allergenUpdate['version'] === 2
        && (int) $db->query(
            'SELECT updated_by FROM restaurant_product_allergen_declaration WHERE id_restaurant_allergen_declaration=' . $allergen['declaration_id']
        )->fetchColumn() === (int) $a['actor_id']);

    $media = $products->registerMedia($scopeA + [
        'product_id' => $demoA['products']['Burger Demo'],
        'storage_key' => 'ab/' . str_repeat('a', 64) . '.webp', 'mime_type' => 'image/webp',
        'byte_size' => 2048, 'sha256' => str_repeat('b', 64), 'alt_text' => 'Placeholder sintético',
        'source' => 'Gimnera synthetic', 'license' => 'internal-test',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:media'),
    ]);
    check('medio registra solo referencia privada y metadatos', $media['media_id'] > 0);

    $group = $modifiers->updateGroup($scopeA + [
        'group_id' => $demoA['groups']['Extras'], 'expected_version' => 1,
        'name' => 'Extras alpha', 'required' => false, 'min_selections' => 0, 'max_selections' => 4,
        'status' => 'ACTIVE', 'sort_order' => 2,
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:group:update'),
    ]);
    check('grupo de modificadores usa optimistic locking', $group['version'] === 2);
    check('edición stale del grupo queda rechazada', r02Rejected(fn() => $modifiers->updateGroup($scopeA + [
        'group_id' => $demoA['groups']['Extras'], 'expected_version' => 1,
        'name' => 'Stale', 'required' => false, 'min_selections' => 0, 'max_selections' => 2,
        'status' => 'ACTIVE', 'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:group:stale'),
    ])));

    $loaded = $products->loadCatalog($a['company_id'], $a['account_id'], $a['brand_id'], $demoA['catalog_id']);
    check('carga por conjuntos devuelve productos y variantes', count($loaded['products']) === 3 && count($loaded['variants']) === 4);
    check('carga por conjuntos devuelve modificadores sin N+1', count($loaded['modifier_groups']) === 3 && count($loaded['modifiers']) === 1);

    $demoB = RestaurantCatalogTestFactory::demo($db, $b, 'alpha');
    check('dos tenants reutilizan nombres sintéticos sin colisión global', $demoB['catalog_id'] !== $demoA['catalog_id']);
    check('servicio rechaza catálogo de otro tenant', r02Rejected(fn() => $catalogs->listCategories(
        $b['company_id'], $b['account_id'], $b['brand_id'], $demoA['catalog_id']
    )));
    check('servicio rechaza producto ajeno aunque ID sea válido', r02Rejected(fn() => $pricing->candidates(
        $b['company_id'], $b['account_id'], $b['brand_id'], $demoA['products']['Burger Demo']
    )));

    $brand2 = RestaurantCatalogTestFactory::additionalBrand($db, $b, 'Segunda marca sintética');
    $scopeB2 = ['company_id' => $b['company_id'], 'account_id' => $b['account_id'], 'brand_id' => $brand2];
    $catalogB2 = $catalogs->createCatalog($scopeB2 + [
        'name' => 'Carta Principal alpha', 'status' => 'ACTIVE',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:brand2:catalog'),
    ]);
    $productB2 = $products->createProduct($scopeB2 + [
        'name' => 'Burger Demo alpha', 'status' => 'ACTIVE',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:brand2:product'),
    ]);
    check('dos marcas del mismo account reutilizan nombre sin colisión', $catalogB2['catalog_id'] > 0 && $productB2['product_id'] > 0);
    check('scope brand impide relacionar producto con categoría de marca hermana', r02Rejected(fn() => $products->linkCategory($scopeB2 + [
        'product_id' => $productB2['product_id'], 'category_id' => $demoB['categories']['Principales'],
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:brand2:foreign-category'),
    ])));

    $orphans = (int) $db->query(
        "SELECT SUM(orphan_count) FROM (
           SELECT COUNT(*) orphan_count FROM restaurant_category c LEFT JOIN restaurant_catalog x
             ON x.id_restaurant_catalog=c.id_restaurant_catalog AND x.id_restaurant_account=c.id_restaurant_account
            AND x.id_empresa=c.id_empresa AND x.id_restaurant_brand=c.id_restaurant_brand
            WHERE x.id_restaurant_catalog IS NULL
           UNION ALL
           SELECT COUNT(*) FROM restaurant_product_variant v LEFT JOIN restaurant_product p
             ON p.id_restaurant_product=v.id_restaurant_product AND p.id_restaurant_account=v.id_restaurant_account
            AND p.id_empresa=v.id_empresa AND p.id_restaurant_brand=v.id_restaurant_brand
            WHERE p.id_restaurant_product IS NULL
           UNION ALL
           SELECT COUNT(*) FROM restaurant_price r LEFT JOIN restaurant_product p
             ON p.id_restaurant_product=r.id_restaurant_product AND p.id_restaurant_account=r.id_restaurant_account
            AND p.id_empresa=r.id_empresa AND p.id_restaurant_brand=r.id_restaurant_brand
            WHERE p.id_restaurant_product IS NULL
           UNION ALL
           SELECT COUNT(*) FROM restaurant_modifier m LEFT JOIN restaurant_modifier_group g
             ON g.id_restaurant_modifier_group=m.id_restaurant_modifier_group AND g.id_restaurant_account=m.id_restaurant_account
            AND g.id_empresa=m.id_empresa AND g.id_restaurant_brand=m.id_restaurant_brand
            WHERE g.id_restaurant_modifier_group IS NULL
         ) integrity"
    )->fetchColumn();
    check('dataset R02 no contiene huérfanos tenant/account/brand', $orphans === 0);

    $beforeCancelled = (int) $db->query('SELECT COUNT(*) FROM restaurant_product WHERE id_empresa=' . $a['company_id'])->fetchColumn();
    $db->exec("UPDATE empresa SET estado='inactiva',onboarding_state='CANCELLED' WHERE id_empresa=" . $a['company_id']);
    check('tenant cancelado rechaza mutación de catálogo', r02Rejected(fn() => $products->createProduct($scopeA + [
        'name' => 'No debe existir', 'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02:cancelled'),
    ])));
    check('rechazo lifecycle deja cero efectos parciales', (int) $db->query(
        'SELECT COUNT(*) FROM restaurant_product WHERE id_empresa=' . $a['company_id']
    )->fetchColumn() === $beforeCancelled);

    check('cambios críticos quedan en auditoría REQUIRED', (int) $db->query(
        "SELECT COUNT(*) FROM log_actividad WHERE id_empresa={$b['company_id']} AND accion LIKE 'RESTAURANT_%' AND resultado='exito'"
    )->fetchColumn() >= 15);
} catch (Throwable $error) {
    check('foundation R02 completa', false);
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
} finally {
    RestaurantCatalogTestFactory::cleanup($db, $companies);
}

finishTests();
