<?php

require_once __DIR__ . '/RestaurantOrganizationTestFactory.php';
require_once dirname(__DIR__, 2) . '/app/domains/restaurants/RestaurantCatalogService.php';
require_once dirname(__DIR__, 2) . '/app/domains/restaurants/RestaurantProductService.php';
require_once dirname(__DIR__, 2) . '/app/domains/restaurants/RestaurantModifierService.php';
require_once dirname(__DIR__, 2) . '/app/domains/restaurants/RestaurantPricingService.php';
require_once dirname(__DIR__, 2) . '/app/domains/restaurants/RestaurantAvailabilityService.php';

final class RestaurantCatalogTestFactory
{
    /** @return array{company_id:int,account_id:int,brand_id:int,legal_entity_id:int,location_id:int,actor_id:int} */
    public static function foundation(PDO $db, string $suffix, bool $active = true): array
    {
        $actor = RestaurantOrganizationTestFactory::actor($db);
        $company = RestaurantOrganizationTestFactory::createCompany($db, $suffix, $active);
        if (!$active) {
            return [
                'company_id' => $company, 'account_id' => 0, 'brand_id' => 0,
                'legal_entity_id' => 0, 'location_id' => 0, 'actor_id' => $actor,
            ];
        }
        $created = (new RestaurantOrganizationService($db, $actor))->provision(
            RestaurantOrganizationTestFactory::input($company, 'r02-' . $suffix, [
                'brand_name' => 'Gimnera Food Demo ' . $suffix,
                'legal_entity_name' => 'Gimnera Food Demo Legal ' . $suffix,
                'location_name' => 'Gimnera Food Demo Local ' . $suffix,
            ])
        );
        return [
            'company_id' => $company,
            'account_id' => (int) $created['account_id'],
            'brand_id' => (int) $created['brand_id'],
            'legal_entity_id' => (int) $created['legal_entity_id'],
            'location_id' => (int) $created['location_id'],
            'actor_id' => $actor,
        ];
    }

    /** @return array<string,int> */
    public static function scope(array $foundation): array
    {
        return [
            'company_id' => (int) $foundation['company_id'],
            'account_id' => (int) $foundation['account_id'],
            'brand_id' => (int) $foundation['brand_id'],
        ];
    }

    public static function additionalBrand(PDO $db, array $foundation, string $name): int
    {
        $slug = 'synthetic-' . substr(hash('sha256', $name . ':' . microtime(true)), 0, 20);
        $stmt = $db->prepare(
            "INSERT INTO restaurant_brand
             (id_restaurant_account,id_empresa,name,slug,status,version)
             VALUES (:account,:company,:name,:slug,'ACTIVE',1)"
        );
        $stmt->execute([
            ':account' => $foundation['account_id'], ':company' => $foundation['company_id'],
            ':name' => $name, ':slug' => $slug,
        ]);
        return (int) $db->lastInsertId();
    }

    public static function uuid(string $seed): string
    {
        $hash = hash('sha256', $seed);
        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-4' . substr($hash, 13, 3)
            . '-8' . substr($hash, 17, 3) . '-' . substr($hash, 20, 12);
    }

    /** @return array{catalog_id:int,categories:array<string,int>,products:array<string,int>,variants:array<string,int>,groups:array<string,int>} */
    public static function demo(PDO $db, array $foundation, string $seed): array
    {
        $scope = self::scope($foundation);
        $catalogService = new RestaurantCatalogService($db, (int) $foundation['actor_id']);
        $productService = new RestaurantProductService($db, (int) $foundation['actor_id']);
        $modifierService = new RestaurantModifierService($db, (int) $foundation['actor_id']);

        $catalog = $catalogService->createCatalog($scope + [
            'name' => 'Carta Principal ' . $seed,
            'status' => 'ACTIVE',
            'idempotency_key' => self::uuid($seed . ':catalog'),
        ]);
        $categories = [];
        foreach (['Entrantes', 'Principales', 'Bebidas'] as $index => $name) {
            $created = $catalogService->createCategory($scope + [
                'catalog_id' => $catalog['catalog_id'], 'name' => $name,
                'sort_order' => $index + 1,
                'idempotency_key' => self::uuid($seed . ':category:' . $name),
            ]);
            $categories[$name] = (int) $created['category_id'];
        }
        $products = [];
        foreach (['Burger Demo', 'Bebida Demo', 'Producto Simple'] as $name) {
            $created = $productService->createProduct($scope + [
                'name' => $name . ' ' . $seed, 'status' => 'ACTIVE',
                'idempotency_key' => self::uuid($seed . ':product:' . $name),
            ]);
            $products[$name] = (int) $created['product_id'];
        }
        $productService->linkCategory($scope + [
            'product_id' => $products['Burger Demo'], 'category_id' => $categories['Principales'],
            'idempotency_key' => self::uuid($seed . ':link:burger'),
        ]);
        $productService->linkCategory($scope + [
            'product_id' => $products['Bebida Demo'], 'category_id' => $categories['Bebidas'],
            'idempotency_key' => self::uuid($seed . ':link:drink'),
        ]);
        $productService->linkCategory($scope + [
            'product_id' => $products['Producto Simple'], 'category_id' => $categories['Entrantes'],
            'idempotency_key' => self::uuid($seed . ':link:simple'),
        ]);

        $variants = [];
        foreach ([['Burger Demo','Normal'], ['Burger Demo','Doble'], ['Bebida Demo','330ml'], ['Bebida Demo','500ml']] as [$product, $label]) {
            $created = $productService->createVariant($scope + [
                'product_id' => $products[$product], 'label' => $label,
                'idempotency_key' => self::uuid($seed . ':variant:' . $product . ':' . $label),
            ]);
            $variants[$product . ':' . $label] = (int) $created['variant_id'];
        }

        $groups = [];
        foreach ([['Punto', true, 1, 1], ['Extras', false, 0, 3], ['Salsas', false, 0, 2]] as $index => [$name, $required, $min, $max]) {
            $created = $modifierService->createGroup($scope + [
                'name' => $name . ' ' . $seed, 'required' => $required,
                'min_selections' => $min, 'max_selections' => $max, 'sort_order' => $index,
                'idempotency_key' => self::uuid($seed . ':group:' . $name),
            ]);
            $groups[$name] = (int) $created['group_id'];
            $modifierService->attachToProduct($scope + [
                'product_id' => $products['Burger Demo'], 'group_id' => $created['group_id'],
                'sort_order' => $index,
                'idempotency_key' => self::uuid($seed . ':attach:' . $name),
            ]);
        }
        $modifierService->createModifier($scope + [
            'group_id' => $groups['Extras'], 'name' => 'Extra queso ' . $seed,
            'price_delta' => '1.50', 'currency' => 'EUR',
            'idempotency_key' => self::uuid($seed . ':modifier:cheese'),
        ]);
        return [
            'catalog_id' => (int) $catalog['catalog_id'], 'categories' => $categories,
            'products' => $products, 'variants' => $variants, 'groups' => $groups,
        ];
    }

    /** @param list<int> $companyIds */
    public static function cleanup(PDO $db, array $companyIds): void
    {
        $tables = [
            'restaurant_catalog_mutation', 'restaurant_availability_history', 'restaurant_price_history',
            'restaurant_product_media', 'restaurant_product_allergen_declaration', 'restaurant_availability',
            'restaurant_price', 'restaurant_product_modifier_group', 'restaurant_modifier',
            'restaurant_modifier_group', 'restaurant_product_variant', 'restaurant_product_category',
            'restaurant_product', 'restaurant_category', 'restaurant_catalog_location', 'restaurant_catalog',
        ];
        foreach (array_values(array_unique(array_map('intval', $companyIds))) as $companyId) {
            if ($companyId <= 0) continue;
            foreach ($tables as $table) {
                $stmt = $db->prepare("DELETE FROM {$table} WHERE id_empresa=:company");
                $stmt->execute([':company' => $companyId]);
            }
        }
        RestaurantOrganizationTestFactory::cleanup($db, $companyIds);
    }
}
