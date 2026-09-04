<?php

require_once __DIR__ . '/RestaurantCatalogServiceBase.php';

final class RestaurantProductService extends RestaurantCatalogServiceBase
{
    /** @return array{product_id:int,version:int,duplicate:bool} */
    public function createProduct(array $input): array
    {
        $scope = $input;
        $name = self::text($input['name'] ?? null, 160, 'producto');
        $description = self::text($input['description'] ?? null, 2000, 'descripción', true);
        $status = self::status($input['status'] ?? 'DRAFT', ['DRAFT','ACTIVE','INACTIVE','ARCHIVED'], 'producto');
        $key = self::uuid($input['idempotency_key'] ?? null);
        $fingerprint = self::fingerprint(compact('name', 'description', 'status'));

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $name, $description, $status, $key, $fingerprint
        ): array {
            $existing = $this->existingCreate(
                'restaurant_product', 'id_restaurant_product', $company, $account, $brand, $key, $fingerprint
            );
            if ($existing !== null) {
                return ['product_id' => (int) $existing['entity_id'], 'version' => (int) $existing['version'], 'duplicate' => true];
            }
            $stmt = $this->db->prepare(
                'INSERT INTO restaurant_product
                 (id_restaurant_account,id_empresa,id_restaurant_brand,name,slug,description,status,version,idempotency_key,request_fingerprint)
                 VALUES (:account,:company,:brand,:name,:slug,:description,:status,1,:key,:fingerprint)'
            );
            $stmt->execute([
                ':account' => $account, ':company' => $company, ':brand' => $brand,
                ':name' => $name, ':slug' => self::slug($name), ':description' => $description,
                ':status' => $status, ':key' => $key, ':fingerprint' => $fingerprint,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->audit($company, 'RESTAURANT_PRODUCT_CREATED', 'restaurant_product', $id, null, $status, 'RESTAURANT_PRODUCT_CREATED', ['brand_id' => $brand]);
            return ['product_id' => $id, 'version' => 1, 'duplicate' => false];
        });
    }

    /** @return array{product_id:int,version:int,duplicate:bool} */
    public function updateProduct(array $input): array
    {
        $scope = $input;
        $productId = self::positiveId($input['product_id'] ?? null, 'producto');
        $expected = self::expectedVersion($input['expected_version'] ?? null);
        $name = self::text($input['name'] ?? null, 160, 'producto');
        $description = self::text($input['description'] ?? null, 2000, 'descripción', true);
        $status = self::status($input['status'] ?? null, ['DRAFT','ACTIVE','INACTIVE','ARCHIVED'], 'producto');
        $key = self::uuid($input['idempotency_key'] ?? null);
        $fingerprint = self::fingerprint(compact('productId', 'expected', 'name', 'description', 'status'));

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $productId, $expected, $name, $description, $status, $key, $fingerprint
        ): array {
            $duplicate = $this->existingMutation($company, $account, $brand, 'PRODUCT', $productId, 'UPDATE', $key, $fingerprint);
            if ($duplicate !== null) {
                return ['product_id' => $productId, 'version' => $duplicate['version'], 'duplicate' => true];
            }
            $row = $this->scopedRow('restaurant_product', 'id_restaurant_product', $productId, $company, $account, $brand, true);
            if ((string) $row['status'] === 'ARCHIVED' && $status !== 'ARCHIVED') {
                throw new DomainException('Un producto archivado no se reactiva silenciosamente.');
            }
            $stmt = $this->db->prepare(
                'UPDATE restaurant_product
                    SET name=:name,slug=:slug,description=:description,status=:status,version=version+1
                  WHERE id_restaurant_product=:id AND id_empresa=:company AND version=:expected'
            );
            $stmt->execute([
                ':name' => $name, ':slug' => self::slug($name), ':description' => $description,
                ':status' => $status, ':id' => $productId, ':company' => $company, ':expected' => $expected,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('Conflicto de versión al editar el producto.');
            }
            $version = $expected + 1;
            $this->recordMutation($company, $account, $brand, 'PRODUCT', $productId, 'UPDATE', $key, $fingerprint, $version);
            $this->audit($company, 'RESTAURANT_PRODUCT_UPDATED', 'restaurant_product', $productId, (string) $row['status'], $status, 'RESTAURANT_PRODUCT_UPDATED', ['version' => $version]);
            return ['product_id' => $productId, 'version' => $version, 'duplicate' => false];
        });
    }

    /** @return array{product_id:int,category_id:int,duplicate:bool} */
    public function linkCategory(array $input): array
    {
        $scope = $input;
        $productId = self::positiveId($input['product_id'] ?? null, 'producto');
        $categoryId = self::positiveId($input['category_id'] ?? null, 'categoría');
        $order = self::order($input['sort_order'] ?? 0);
        $key = self::uuid($input['idempotency_key'] ?? null);
        $fingerprint = self::fingerprint(compact('productId', 'categoryId', 'order'));

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $productId, $categoryId, $order, $key, $fingerprint
        ): array {
            $this->scopedRow('restaurant_product', 'id_restaurant_product', $productId, $company, $account, $brand, true);
            $category = $this->scopedRow('restaurant_category', 'id_restaurant_category', $categoryId, $company, $account, $brand, true);
            $duplicate = $this->existingMutation($company, $account, $brand, 'PRODUCT', $productId, 'LINK_CATEGORY', $key, $fingerprint);
            if ($duplicate !== null) {
                return ['product_id' => $productId, 'category_id' => $categoryId, 'duplicate' => true];
            }
            $stmt = $this->db->prepare(
                'INSERT INTO restaurant_product_category
                 (id_restaurant_product,id_restaurant_category,id_restaurant_catalog,id_restaurant_account,id_empresa,id_restaurant_brand,sort_order)
                 VALUES (:product,:category,:catalog,:account,:company,:brand,:ordering)
                 ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order)'
            );
            $stmt->execute([
                ':product' => $productId, ':category' => $categoryId,
                ':catalog' => (int) $category['id_restaurant_catalog'], ':account' => $account,
                ':company' => $company, ':brand' => $brand, ':ordering' => $order,
            ]);
            $this->recordMutation($company, $account, $brand, 'PRODUCT', $productId, 'LINK_CATEGORY', $key, $fingerprint, 1);
            $this->audit($company, 'RESTAURANT_PRODUCT_CATEGORY_LINKED', 'restaurant_product', $productId, null, (string) $categoryId, 'RESTAURANT_PRODUCT_CATEGORY_LINKED', ['category_id' => $categoryId]);
            return ['product_id' => $productId, 'category_id' => $categoryId, 'duplicate' => false];
        });
    }

    /** @return array{variant_id:int,version:int,duplicate:bool} */
    public function createVariant(array $input): array
    {
        $scope = $input;
        $productId = self::positiveId($input['product_id'] ?? null, 'producto');
        $label = self::text($input['label'] ?? null, 120, 'variante');
        $status = self::status($input['status'] ?? 'ACTIVE', ['ACTIVE','INACTIVE','ARCHIVED'], 'variante');
        $order = self::order($input['sort_order'] ?? 0);
        $key = self::uuid($input['idempotency_key'] ?? null);
        $fingerprint = self::fingerprint(compact('productId', 'label', 'status', 'order'));

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $productId, $label, $status, $order, $key, $fingerprint
        ): array {
            $this->scopedRow('restaurant_product', 'id_restaurant_product', $productId, $company, $account, $brand, true);
            $existing = $this->existingCreate(
                'restaurant_product_variant', 'id_restaurant_product_variant', $company, $account, $brand, $key, $fingerprint
            );
            if ($existing !== null) {
                return ['variant_id' => (int) $existing['entity_id'], 'version' => (int) $existing['version'], 'duplicate' => true];
            }
            $stmt = $this->db->prepare(
                'INSERT INTO restaurant_product_variant
                 (id_restaurant_product,id_restaurant_account,id_empresa,id_restaurant_brand,label,slug,status,sort_order,version,idempotency_key,request_fingerprint)
                 VALUES (:product,:account,:company,:brand,:label,:slug,:status,:ordering,1,:key,:fingerprint)'
            );
            $stmt->execute([
                ':product' => $productId, ':account' => $account, ':company' => $company, ':brand' => $brand,
                ':label' => $label, ':slug' => self::slug($label), ':status' => $status,
                ':ordering' => $order, ':key' => $key, ':fingerprint' => $fingerprint,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->audit($company, 'RESTAURANT_VARIANT_CREATED', 'restaurant_product_variant', $id, null, $status, 'RESTAURANT_VARIANT_CREATED', ['product_id' => $productId]);
            return ['variant_id' => $id, 'version' => 1, 'duplicate' => false];
        });
    }

    /** @return array{declaration_id:int,version:int,duplicate:bool} */
    public function declareAllergen(array $input): array
    {
        $scope = $input;
        $productId = self::positiveId($input['product_id'] ?? null, 'producto');
        $code = strtoupper((string) self::text($input['declaration_code'] ?? null, 40, 'código de declaración'));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_.-]{0,39}$/', $code)) {
            throw new InvalidArgumentException('Código de declaración no válido.');
        }
        $label = self::text($input['label'] ?? null, 120, 'etiqueta declarada');
        $statement = self::text($input['statement'] ?? null, 500, 'declaración', true);
        $source = self::text($input['source'] ?? null, 255, 'fuente', true);
        $key = self::uuid($input['idempotency_key'] ?? null);
        $fingerprint = self::fingerprint(compact('productId', 'code', 'label', 'statement', 'source'));

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $productId, $code, $label, $statement, $source, $key, $fingerprint
        ): array {
            $this->scopedRow('restaurant_product', 'id_restaurant_product', $productId, $company, $account, $brand, true);
            $existing = $this->existingCreate(
                'restaurant_product_allergen_declaration', 'id_restaurant_allergen_declaration',
                $company, $account, $brand, $key, $fingerprint
            );
            if ($existing !== null) {
                return ['declaration_id' => (int) $existing['entity_id'], 'version' => (int) $existing['version'], 'duplicate' => true];
            }
            $stmt = $this->db->prepare(
                "INSERT INTO restaurant_product_allergen_declaration
                 (id_restaurant_product,id_restaurant_account,id_empresa,id_restaurant_brand,declaration_code,label,statement,source,status,version,idempotency_key,request_fingerprint,updated_by)
                 VALUES (:product,:account,:company,:brand,:code,:label,:statement,:source,'DECLARED',1,:key,:fingerprint,:actor)"
            );
            $stmt->execute([
                ':product' => $productId, ':account' => $account, ':company' => $company, ':brand' => $brand,
                ':code' => $code, ':label' => $label, ':statement' => $statement, ':source' => $source,
                ':key' => $key, ':fingerprint' => $fingerprint, ':actor' => $this->actorId,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->audit($company, 'RESTAURANT_ALLERGEN_DECLARED', 'restaurant_product_allergen_declaration', $id, null, 'DECLARED', 'RESTAURANT_ALLERGEN_DECLARED', ['product_id' => $productId]);
            return ['declaration_id' => $id, 'version' => 1, 'duplicate' => false];
        });
    }

    /** @return array{declaration_id:int,version:int,duplicate:bool} */
    public function updateAllergen(array $input): array
    {
        $scope = $input;
        $declarationId = self::positiveId($input['declaration_id'] ?? null, 'declaración');
        $expected = self::expectedVersion($input['expected_version'] ?? null);
        $label = self::text($input['label'] ?? null, 120, 'etiqueta declarada');
        $statement = self::text($input['statement'] ?? null, 500, 'declaración', true);
        $source = self::text($input['source'] ?? null, 255, 'fuente', true);
        $status = self::status($input['status'] ?? 'DECLARED', ['DECLARED','WITHDRAWN'], 'declaración');
        $key = self::uuid($input['idempotency_key'] ?? null);
        $fingerprint = self::fingerprint(compact('declarationId', 'expected', 'label', 'statement', 'source', 'status'));

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $declarationId, $expected, $label, $statement, $source, $status, $key, $fingerprint
        ): array {
            $duplicate = $this->existingMutation($company, $account, $brand, 'ALLERGEN', $declarationId, 'UPDATE', $key, $fingerprint);
            if ($duplicate !== null) {
                return ['declaration_id' => $declarationId, 'version' => $duplicate['version'], 'duplicate' => true];
            }
            $row = $this->scopedRow(
                'restaurant_product_allergen_declaration', 'id_restaurant_allergen_declaration',
                $declarationId, $company, $account, $brand, true
            );
            $stmt = $this->db->prepare(
                'UPDATE restaurant_product_allergen_declaration
                    SET label=:label,statement=:statement,source=:source,status=:status,
                        updated_by=:actor,version=version+1
                  WHERE id_restaurant_allergen_declaration=:id AND id_empresa=:company AND version=:expected'
            );
            $stmt->execute([
                ':label' => $label, ':statement' => $statement, ':source' => $source, ':status' => $status,
                ':actor' => $this->actorId, ':id' => $declarationId, ':company' => $company, ':expected' => $expected,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('Conflicto de versión al cambiar la declaración.');
            }
            $version = $expected + 1;
            $this->recordMutation($company, $account, $brand, 'ALLERGEN', $declarationId, 'UPDATE', $key, $fingerprint, $version);
            $this->audit($company, 'RESTAURANT_ALLERGEN_CHANGED', 'restaurant_product_allergen_declaration', $declarationId, (string) $row['status'], $status, 'RESTAURANT_ALLERGEN_CHANGED', ['version' => $version]);
            return ['declaration_id' => $declarationId, 'version' => $version, 'duplicate' => false];
        });
    }

    /** @return array{media_id:int,version:int,duplicate:bool} */
    public function registerMedia(array $input): array
    {
        $scope = $input;
        $productId = self::positiveId($input['product_id'] ?? null, 'producto');
        $storageKey = strtolower(trim((string) ($input['storage_key'] ?? '')));
        $mime = strtolower(trim((string) ($input['mime_type'] ?? '')));
        $size = filter_var($input['byte_size'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10485760]]);
        $sha = strtolower(trim((string) ($input['sha256'] ?? '')));
        $alt = self::text($input['alt_text'] ?? null, 180, 'texto alternativo');
        $source = self::text($input['source'] ?? null, 255, 'fuente', true);
        $license = self::text($input['license'] ?? null, 120, 'licencia', true);
        $order = self::order($input['sort_order'] ?? 0);
        $key = self::uuid($input['idempotency_key'] ?? null);
        $extensions = ['image/jpeg' => ['jpg','jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
        if (!preg_match('/^[a-f0-9]{2}\/[a-f0-9]{64}\.(jpg|jpeg|png|webp)$/', $storageKey)
            || !isset($extensions[$mime]) || $size === false || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
            throw new InvalidArgumentException('Metadatos de medio privado no válidos.');
        }
        $extension = pathinfo($storageKey, PATHINFO_EXTENSION);
        if (!in_array($extension, $extensions[$mime], true)) {
            throw new InvalidArgumentException('La extensión no coincide con el MIME validado.');
        }
        $fingerprint = self::fingerprint(compact('productId', 'storageKey', 'mime', 'size', 'sha', 'alt', 'source', 'license', 'order'));

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $productId, $storageKey, $mime, $size, $sha, $alt, $source, $license, $order, $key, $fingerprint
        ): array {
            $this->scopedRow('restaurant_product', 'id_restaurant_product', $productId, $company, $account, $brand, true);
            $existing = $this->existingCreate(
                'restaurant_product_media', 'id_restaurant_product_media', $company, $account, $brand, $key, $fingerprint
            );
            if ($existing !== null) {
                return ['media_id' => (int) $existing['entity_id'], 'version' => (int) $existing['version'], 'duplicate' => true];
            }
            $stmt = $this->db->prepare(
                'INSERT INTO restaurant_product_media
                 (id_restaurant_product,id_restaurant_account,id_empresa,id_restaurant_brand,storage_key,mime_type,byte_size,sha256,alt_text,source,license,status,sort_order,version,idempotency_key,request_fingerprint)
                 VALUES (:product,:account,:company,:brand,:storage,:mime,:size,:sha,:alt,:source,:license,\'ACTIVE\',:ordering,1,:key,:fingerprint)'
            );
            $stmt->execute([
                ':product' => $productId, ':account' => $account, ':company' => $company, ':brand' => $brand,
                ':storage' => $storageKey, ':mime' => $mime, ':size' => $size, ':sha' => $sha,
                ':alt' => $alt, ':source' => $source, ':license' => $license, ':ordering' => $order,
                ':key' => $key, ':fingerprint' => $fingerprint,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->audit($company, 'RESTAURANT_PRODUCT_MEDIA_REGISTERED', 'restaurant_product_media', $id, null, 'ACTIVE', 'RESTAURANT_MEDIA_REGISTERED', ['product_id' => $productId]);
            return ['media_id' => $id, 'version' => 1, 'duplicate' => false];
        });
    }

    /**
     * Carga acotada: usa seis consultas de conjunto, nunca una por producto.
     * No resuelve precio efectivo ni publicación porque esas reglas esperan Jama.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public function loadCatalog(int $companyId, int $accountId, int $brandId, int $catalogId): array
    {
        $this->assertRootScope($companyId, $accountId, $brandId);
        $this->scopedRow('restaurant_catalog', 'id_restaurant_catalog', $catalogId, $companyId, $accountId, $brandId);
        $scope = [':company' => $companyId, ':account' => $accountId, ':brand' => $brandId, ':catalog' => $catalogId];
        $queries = [
            'categories' => 'SELECT id_restaurant_category AS category_id,name,status,sort_order
                               FROM restaurant_category WHERE id_empresa=:company AND id_restaurant_account=:account
                                AND id_restaurant_brand=:brand AND id_restaurant_catalog=:catalog
                              ORDER BY sort_order,id_restaurant_category',
            'products' => 'SELECT DISTINCT p.id_restaurant_product AS product_id,p.name,p.description,p.status,p.version
                             FROM restaurant_product_category pc
                             INNER JOIN restaurant_product p
                               ON p.id_restaurant_product=pc.id_restaurant_product
                              AND p.id_restaurant_account=pc.id_restaurant_account
                              AND p.id_empresa=pc.id_empresa AND p.id_restaurant_brand=pc.id_restaurant_brand
                            WHERE pc.id_empresa=:company AND pc.id_restaurant_account=:account
                              AND pc.id_restaurant_brand=:brand AND pc.id_restaurant_catalog=:catalog
                            ORDER BY p.name,p.id_restaurant_product',
            'links' => 'SELECT id_restaurant_product AS product_id,id_restaurant_category AS category_id,sort_order
                          FROM restaurant_product_category WHERE id_empresa=:company AND id_restaurant_account=:account
                           AND id_restaurant_brand=:brand AND id_restaurant_catalog=:catalog
                         ORDER BY id_restaurant_category,sort_order,id_restaurant_product',
            'variants' => 'SELECT v.id_restaurant_product_variant AS variant_id,v.id_restaurant_product AS product_id,v.label,v.status,v.sort_order
                             FROM restaurant_product_variant v
                             INNER JOIN restaurant_product_category pc
                               ON pc.id_restaurant_product=v.id_restaurant_product AND pc.id_restaurant_account=v.id_restaurant_account
                              AND pc.id_empresa=v.id_empresa AND pc.id_restaurant_brand=v.id_restaurant_brand
                            WHERE pc.id_empresa=:company AND pc.id_restaurant_account=:account
                              AND pc.id_restaurant_brand=:brand AND pc.id_restaurant_catalog=:catalog
                            GROUP BY v.id_restaurant_product_variant,v.id_restaurant_product,v.label,v.status,v.sort_order
                            ORDER BY v.id_restaurant_product,v.sort_order,v.id_restaurant_product_variant',
            'modifier_groups' => 'SELECT DISTINCT g.id_restaurant_modifier_group AS group_id,pg.id_restaurant_product AS product_id,
                                         g.name,g.is_required,g.min_selections,g.max_selections,pg.sort_order
                                    FROM restaurant_product_modifier_group pg
                                    INNER JOIN restaurant_modifier_group g
                                      ON g.id_restaurant_modifier_group=pg.id_restaurant_modifier_group
                                     AND g.id_restaurant_account=pg.id_restaurant_account
                                     AND g.id_empresa=pg.id_empresa AND g.id_restaurant_brand=pg.id_restaurant_brand
                                    INNER JOIN restaurant_product_category pc
                                      ON pc.id_restaurant_product=pg.id_restaurant_product
                                     AND pc.id_restaurant_account=pg.id_restaurant_account
                                     AND pc.id_empresa=pg.id_empresa AND pc.id_restaurant_brand=pg.id_restaurant_brand
                                   WHERE pc.id_empresa=:company AND pc.id_restaurant_account=:account
                                     AND pc.id_restaurant_brand=:brand AND pc.id_restaurant_catalog=:catalog
                                   ORDER BY pg.id_restaurant_product,pg.sort_order,g.id_restaurant_modifier_group',
            'modifiers' => 'SELECT DISTINCT m.id_restaurant_modifier AS modifier_id,m.id_restaurant_modifier_group AS group_id,
                                   m.name,m.price_delta_minor,m.currency,m.status,m.sort_order
                              FROM restaurant_modifier m
                              INNER JOIN restaurant_product_modifier_group pg
                                ON pg.id_restaurant_modifier_group=m.id_restaurant_modifier_group
                               AND pg.id_restaurant_account=m.id_restaurant_account
                               AND pg.id_empresa=m.id_empresa AND pg.id_restaurant_brand=m.id_restaurant_brand
                              INNER JOIN restaurant_product_category pc
                                ON pc.id_restaurant_product=pg.id_restaurant_product
                               AND pc.id_restaurant_account=pg.id_restaurant_account
                               AND pc.id_empresa=pg.id_empresa AND pc.id_restaurant_brand=pg.id_restaurant_brand
                             WHERE pc.id_empresa=:company AND pc.id_restaurant_account=:account
                               AND pc.id_restaurant_brand=:brand AND pc.id_restaurant_catalog=:catalog
                             ORDER BY m.id_restaurant_modifier_group,m.sort_order,m.id_restaurant_modifier',
        ];
        $result = [];
        foreach ($queries as $name => $sql) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($scope);
            $result[$name] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $result;
    }
}
