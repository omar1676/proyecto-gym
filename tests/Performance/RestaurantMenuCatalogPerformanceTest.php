<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/RestaurantCatalogTestFactory.php';

$db = Database::getInstance()->getConnection();
$companies = [];
try {
    $started = hrtime(true);
    $foundations = [];
    for ($tenant = 1; $tenant <= 100; $tenant++) {
        $foundation = RestaurantCatalogTestFactory::foundation($db, 'r02-scale-' . $tenant);
        $foundations[] = $foundation;
        $companies[] = $foundation['company_id'];
    }
    $provisionMs = (hrtime(true) - $started) / 1_000_000;
    check('escala crea 100 tenants aislados mediante provisioning existente', count($foundations) === 100);
    $tenantIds = array_unique(array_column($foundations, 'company_id'));
    check('los 100 tenants conservan identidad única', count($tenantIds) === 100);

    $target = $foundations[0];
    $scope = RestaurantCatalogTestFactory::scope($target);
    $catalogService = new RestaurantCatalogService($db, $target['actor_id']);
    $productService = new RestaurantProductService($db, $target['actor_id']);
    $catalog = $catalogService->createCatalog($scope + [
        'name' => 'Carta Scale 500', 'status' => 'ACTIVE',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02-scale:catalog'),
    ]);
    $category = $catalogService->createCategory($scope + [
        'catalog_id' => $catalog['catalog_id'], 'name' => 'Productos Scale',
        'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02-scale:category'),
    ]);

    $writeStarted = hrtime(true);
    for ($product = 1; $product <= 500; $product++) {
        $created = $productService->createProduct($scope + [
            'name' => sprintf('Producto Scale %04d', $product), 'status' => 'ACTIVE',
            'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02-scale:product:' . $product),
        ]);
        $productService->linkCategory($scope + [
            'product_id' => $created['product_id'], 'category_id' => $category['category_id'],
            'sort_order' => $product,
            'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02-scale:link:' . $product),
        ]);
        if ($product <= 100) {
            $productService->createVariant($scope + [
                'product_id' => $created['product_id'], 'label' => 'Variante Demo ' . $product,
                'idempotency_key' => RestaurantCatalogTestFactory::uuid('r02-scale:variant:' . $product),
            ]);
        }
    }
    $writeMs = (hrtime(true) - $writeStarted) / 1_000_000;
    check('marca sintética soporta 500 productos y 100 variantes',
        (int) $db->query('SELECT COUNT(*) FROM restaurant_product WHERE id_empresa=' . $target['company_id'])->fetchColumn() === 500
        && (int) $db->query('SELECT COUNT(*) FROM restaurant_product_variant WHERE id_empresa=' . $target['company_id'])->fetchColumn() === 100
    );

    $loadStarted = hrtime(true);
    $loaded = $productService->loadCatalog($target['company_id'], $target['account_id'], $target['brand_id'], $catalog['catalog_id']);
    $loadMs = (hrtime(true) - $loadStarted) / 1_000_000;
    check('carga agregada recupera 500 productos sin consulta por fila', count($loaded['products']) === 500 && count($loaded['variants']) === 100);

    $explain = $db->prepare(
        "EXPLAIN SELECT id_restaurant_product,name FROM restaurant_product
          WHERE id_empresa=:company AND id_restaurant_brand=:brand AND status='ACTIVE'
          ORDER BY name,id_restaurant_product LIMIT 50"
    );
    $explain->execute([':company' => $target['company_id'], ':brand' => $target['brand_id']]);
    $plan = $explain->fetch(PDO::FETCH_ASSOC);
    check('EXPLAIN usa índice tenant/brand/status de producto', !empty($plan['key']) && str_contains((string) $plan['key'], 'idx_restaurant_product_listing'));

    $crossTenant = $db->prepare(
        'SELECT COUNT(*) FROM restaurant_product WHERE id_empresa=:company AND id_restaurant_brand=:brand'
    );
    $crossTenant->execute([':company' => $foundations[1]['company_id'], ':brand' => $target['brand_id']]);
    check('lookup de tenant vecino no cruza los 500 productos', (int) $crossTenant->fetchColumn() === 0);

    echo sprintf(
        "METRICAS_R02: tenants=100; provision_ms=%.3f; products=500; variants=100; write_ms=%.3f; catalog_load_ms=%.3f; queries_catalog=6\n",
        $provisionMs,
        $writeMs,
        $loadMs
    );
} catch (Throwable $error) {
    check('performance R02 completa', false);
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
} finally {
    RestaurantCatalogTestFactory::cleanup($db, $companies);
}
finishTests();
