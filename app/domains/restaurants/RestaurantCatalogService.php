<?php

require_once __DIR__ . '/RestaurantCatalogServiceBase.php';

final class RestaurantCatalogService extends RestaurantCatalogServiceBase
{
    /** @return array{catalog_id:int,version:int,duplicate:bool} */
    public function createCatalog(array $input): array
    {
        $scope = $input;
        $name = self::text($input['name'] ?? null, 150, 'nombre de catálogo');
        $description = self::text($input['description'] ?? null, 500, 'descripción', true);
        $status = self::status($input['status'] ?? 'DRAFT', ['DRAFT','ACTIVE','ARCHIVED'], 'catálogo');
        $key = self::uuid($input['idempotency_key'] ?? null);
        $payload = ['name' => $name, 'description' => $description, 'status' => $status];
        $fingerprint = self::fingerprint($payload);

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $name, $description, $status, $key, $fingerprint
        ): array {
            $existing = $this->existingCreate(
                'restaurant_catalog', 'id_restaurant_catalog', $company, $account, $brand, $key, $fingerprint
            );
            if ($existing !== null) {
                return ['catalog_id' => (int) $existing['entity_id'], 'version' => (int) $existing['version'], 'duplicate' => true];
            }
            $stmt = $this->db->prepare(
                'INSERT INTO restaurant_catalog
                 (id_restaurant_account,id_empresa,id_restaurant_brand,name,slug,description,status,version,idempotency_key,request_fingerprint)
                 VALUES (:account,:company,:brand,:name,:slug,:description,:status,1,:key,:fingerprint)'
            );
            $stmt->execute([
                ':account' => $account, ':company' => $company, ':brand' => $brand,
                ':name' => $name, ':slug' => self::slug($name), ':description' => $description,
                ':status' => $status, ':key' => $key, ':fingerprint' => $fingerprint,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->audit($company, 'RESTAURANT_CATALOG_CREATED', 'restaurant_catalog', $id, null, $status, 'RESTAURANT_CATALOG_CREATED', ['brand_id' => $brand]);
            return ['catalog_id' => $id, 'version' => 1, 'duplicate' => false];
        });
    }

    /** @return array{catalog_id:int,version:int,duplicate:bool} */
    public function updateCatalog(array $input): array
    {
        $scope = $input;
        $catalogId = self::positiveId($input['catalog_id'] ?? null, 'catálogo');
        $expected = self::expectedVersion($input['expected_version'] ?? null);
        $name = self::text($input['name'] ?? null, 150, 'nombre de catálogo');
        $description = self::text($input['description'] ?? null, 500, 'descripción', true);
        $status = self::status($input['status'] ?? null, ['DRAFT','ACTIVE','ARCHIVED'], 'catálogo');
        $key = self::uuid($input['idempotency_key'] ?? null);
        $fingerprint = self::fingerprint(compact('catalogId', 'expected', 'name', 'description', 'status'));

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $catalogId, $expected, $name, $description, $status, $key, $fingerprint
        ): array {
            $duplicate = $this->existingMutation($company, $account, $brand, 'CATALOG', $catalogId, 'UPDATE', $key, $fingerprint);
            if ($duplicate !== null) {
                return ['catalog_id' => $catalogId, 'version' => $duplicate['version'], 'duplicate' => true];
            }
            $row = $this->scopedRow('restaurant_catalog', 'id_restaurant_catalog', $catalogId, $company, $account, $brand, true);
            if ((string) $row['status'] === 'ARCHIVED' && $status !== 'ARCHIVED') {
                throw new DomainException('Un catálogo archivado no se reactiva silenciosamente.');
            }
            $stmt = $this->db->prepare(
                'UPDATE restaurant_catalog
                    SET name=:name,slug=:slug,description=:description,status=:status,version=version+1
                  WHERE id_restaurant_catalog=:id AND id_empresa=:company AND version=:expected'
            );
            $stmt->execute([
                ':name' => $name, ':slug' => self::slug($name), ':description' => $description,
                ':status' => $status, ':id' => $catalogId, ':company' => $company, ':expected' => $expected,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('Conflicto de versión al editar el catálogo.');
            }
            $version = $expected + 1;
            $this->recordMutation($company, $account, $brand, 'CATALOG', $catalogId, 'UPDATE', $key, $fingerprint, $version);
            $this->audit($company, 'RESTAURANT_CATALOG_UPDATED', 'restaurant_catalog', $catalogId, (string) $row['status'], $status, 'RESTAURANT_CATALOG_UPDATED', ['version' => $version]);
            return ['catalog_id' => $catalogId, 'version' => $version, 'duplicate' => false];
        });
    }

    /** @return array{category_id:int,version:int,duplicate:bool} */
    public function createCategory(array $input): array
    {
        $scope = $input;
        $catalogId = self::positiveId($input['catalog_id'] ?? null, 'catálogo');
        $name = self::text($input['name'] ?? null, 120, 'categoría');
        $description = self::text($input['description'] ?? null, 500, 'descripción', true);
        $status = self::status($input['status'] ?? 'ACTIVE', ['ACTIVE','INACTIVE','ARCHIVED'], 'categoría');
        $order = self::order($input['sort_order'] ?? 0);
        $key = self::uuid($input['idempotency_key'] ?? null);
        $fingerprint = self::fingerprint(compact('catalogId', 'name', 'description', 'status', 'order'));

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $catalogId, $name, $description, $status, $order, $key, $fingerprint
        ): array {
            $this->scopedRow('restaurant_catalog', 'id_restaurant_catalog', $catalogId, $company, $account, $brand, true);
            $existing = $this->existingCreate(
                'restaurant_category', 'id_restaurant_category', $company, $account, $brand, $key, $fingerprint
            );
            if ($existing !== null) {
                return ['category_id' => (int) $existing['entity_id'], 'version' => (int) $existing['version'], 'duplicate' => true];
            }
            $stmt = $this->db->prepare(
                'INSERT INTO restaurant_category
                 (id_restaurant_catalog,id_restaurant_account,id_empresa,id_restaurant_brand,name,slug,description,status,sort_order,version,idempotency_key,request_fingerprint)
                 VALUES (:catalog,:account,:company,:brand,:name,:slug,:description,:status,:ordering,1,:key,:fingerprint)'
            );
            $stmt->execute([
                ':catalog' => $catalogId, ':account' => $account, ':company' => $company, ':brand' => $brand,
                ':name' => $name, ':slug' => self::slug($name), ':description' => $description,
                ':status' => $status, ':ordering' => $order, ':key' => $key, ':fingerprint' => $fingerprint,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->audit($company, 'RESTAURANT_CATEGORY_CREATED', 'restaurant_category', $id, null, $status, 'RESTAURANT_CATEGORY_CREATED', ['catalog_id' => $catalogId]);
            return ['category_id' => $id, 'version' => 1, 'duplicate' => false];
        });
    }

    /** @return array{catalog_id:int,location_id:int,duplicate:bool} */
    public function assignLocation(array $input): array
    {
        $scope = $input;
        $catalogId = self::positiveId($input['catalog_id'] ?? null, 'catálogo');
        $locationId = self::positiveId($input['location_id'] ?? null, 'local');
        $key = self::uuid($input['idempotency_key'] ?? null);
        $fingerprint = self::fingerprint(compact('catalogId', 'locationId'));

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $catalogId, $locationId, $key, $fingerprint
        ): array {
            $this->scopedRow('restaurant_catalog', 'id_restaurant_catalog', $catalogId, $company, $account, $brand, true);
            $this->locationRow($locationId, $company, $account, $brand);
            $duplicate = $this->existingMutation($company, $account, $brand, 'CATALOG', $catalogId, 'ASSIGN_LOCATION', $key, $fingerprint);
            if ($duplicate !== null) {
                return ['catalog_id' => $catalogId, 'location_id' => $locationId, 'duplicate' => true];
            }
            $stmt = $this->db->prepare(
                "INSERT INTO restaurant_catalog_location
                 (id_restaurant_catalog,id_restaurant_location,id_restaurant_account,id_empresa,id_restaurant_brand,status)
                 VALUES (:catalog,:location,:account,:company,:brand,'ACTIVE')
                 ON DUPLICATE KEY UPDATE status='ACTIVE'"
            );
            $stmt->execute([
                ':catalog' => $catalogId, ':location' => $locationId, ':account' => $account,
                ':company' => $company, ':brand' => $brand,
            ]);
            $this->recordMutation($company, $account, $brand, 'CATALOG', $catalogId, 'ASSIGN_LOCATION', $key, $fingerprint, 1);
            $this->audit($company, 'RESTAURANT_CATALOG_LOCATION_ASSIGNED', 'restaurant_catalog', $catalogId, null, (string) $locationId, 'RESTAURANT_CATALOG_LOCATION_ASSIGNED', ['location_id' => $locationId]);
            return ['catalog_id' => $catalogId, 'location_id' => $locationId, 'duplicate' => false];
        });
    }

    /** @return list<array<string,mixed>> */
    public function listCategories(int $companyId, int $accountId, int $brandId, int $catalogId): array
    {
        $this->assertRootScope($companyId, $accountId, $brandId);
        $this->scopedRow('restaurant_catalog', 'id_restaurant_catalog', $catalogId, $companyId, $accountId, $brandId);
        $stmt = $this->db->prepare(
            'SELECT id_restaurant_category AS category_id,name,description,status,sort_order,version
               FROM restaurant_category
              WHERE id_empresa=:company AND id_restaurant_account=:account
                AND id_restaurant_brand=:brand AND id_restaurant_catalog=:catalog
              ORDER BY sort_order,id_restaurant_category'
        );
        $stmt->execute([':company' => $companyId, ':account' => $accountId, ':brand' => $brandId, ':catalog' => $catalogId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
