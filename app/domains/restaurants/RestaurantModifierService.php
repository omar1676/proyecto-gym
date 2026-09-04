<?php

require_once __DIR__ . '/RestaurantCatalogServiceBase.php';

final class RestaurantModifierService extends RestaurantCatalogServiceBase
{
    /** @return array{group_id:int,version:int,duplicate:bool} */
    public function createGroup(array $input): array
    {
        $scope = $input;
        $name = self::text($input['name'] ?? null, 120, 'grupo de modificadores');
        $required = filter_var($input['required'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $min = filter_var($input['min_selections'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
        $max = filter_var($input['max_selections'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
        if ($required === null || $min === false || $max === false || $min > $max
            || ($required && $min < 1) || (!$required && $min !== 0)) {
            throw new InvalidArgumentException('Los límites del grupo de modificadores son incoherentes.');
        }
        $status = self::status($input['status'] ?? 'ACTIVE', ['ACTIVE','INACTIVE','ARCHIVED'], 'grupo');
        $order = self::order($input['sort_order'] ?? 0);
        $key = self::uuid($input['idempotency_key'] ?? null);
        $fingerprint = self::fingerprint(compact('name', 'required', 'min', 'max', 'status', 'order'));

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $name, $required, $min, $max, $status, $order, $key, $fingerprint
        ): array {
            $existing = $this->existingCreate(
                'restaurant_modifier_group', 'id_restaurant_modifier_group', $company, $account, $brand, $key, $fingerprint
            );
            if ($existing !== null) {
                return ['group_id' => (int) $existing['entity_id'], 'version' => (int) $existing['version'], 'duplicate' => true];
            }
            $stmt = $this->db->prepare(
                'INSERT INTO restaurant_modifier_group
                 (id_restaurant_account,id_empresa,id_restaurant_brand,name,slug,is_required,min_selections,max_selections,sort_order,status,version,idempotency_key,request_fingerprint)
                 VALUES (:account,:company,:brand,:name,:slug,:required,:minimum,:maximum,:ordering,:status,1,:key,:fingerprint)'
            );
            $stmt->execute([
                ':account' => $account, ':company' => $company, ':brand' => $brand,
                ':name' => $name, ':slug' => self::slug($name), ':required' => $required ? 1 : 0,
                ':minimum' => $min, ':maximum' => $max, ':ordering' => $order, ':status' => $status,
                ':key' => $key, ':fingerprint' => $fingerprint,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->audit($company, 'RESTAURANT_MODIFIER_GROUP_CREATED', 'restaurant_modifier_group', $id, null, $status, 'RESTAURANT_MODIFIER_GROUP_CREATED', ['brand_id' => $brand]);
            return ['group_id' => $id, 'version' => 1, 'duplicate' => false];
        });
    }

    /** @return array{group_id:int,version:int,duplicate:bool} */
    public function updateGroup(array $input): array
    {
        $scope = $input;
        $groupId = self::positiveId($input['group_id'] ?? null, 'grupo');
        $expected = self::expectedVersion($input['expected_version'] ?? null);
        $name = self::text($input['name'] ?? null, 120, 'grupo de modificadores');
        $required = filter_var($input['required'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $min = filter_var($input['min_selections'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
        $max = filter_var($input['max_selections'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
        if ($required === null || $min === false || $max === false || $min > $max
            || ($required && $min < 1) || (!$required && $min !== 0)) {
            throw new InvalidArgumentException('Los límites del grupo de modificadores son incoherentes.');
        }
        $status = self::status($input['status'] ?? null, ['ACTIVE','INACTIVE','ARCHIVED'], 'grupo');
        $order = self::order($input['sort_order'] ?? 0);
        $key = self::uuid($input['idempotency_key'] ?? null);
        $fingerprint = self::fingerprint(compact('groupId', 'expected', 'name', 'required', 'min', 'max', 'status', 'order'));

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $groupId, $expected, $name, $required, $min, $max, $status, $order, $key, $fingerprint
        ): array {
            $duplicate = $this->existingMutation($company, $account, $brand, 'MODIFIER_GROUP', $groupId, 'UPDATE', $key, $fingerprint);
            if ($duplicate !== null) {
                return ['group_id' => $groupId, 'version' => $duplicate['version'], 'duplicate' => true];
            }
            $row = $this->scopedRow('restaurant_modifier_group', 'id_restaurant_modifier_group', $groupId, $company, $account, $brand, true);
            if ((string) $row['status'] === 'ARCHIVED' && $status !== 'ARCHIVED') {
                throw new DomainException('Un grupo archivado no se reactiva silenciosamente.');
            }
            $stmt = $this->db->prepare(
                'UPDATE restaurant_modifier_group
                    SET name=:name,slug=:slug,is_required=:required,min_selections=:minimum,max_selections=:maximum,
                        sort_order=:ordering,status=:status,version=version+1
                  WHERE id_restaurant_modifier_group=:id AND id_empresa=:company AND version=:expected'
            );
            $stmt->execute([
                ':name' => $name, ':slug' => self::slug($name), ':required' => $required ? 1 : 0,
                ':minimum' => $min, ':maximum' => $max, ':ordering' => $order, ':status' => $status,
                ':id' => $groupId, ':company' => $company, ':expected' => $expected,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('Conflicto de versión al editar el grupo.');
            }
            $version = $expected + 1;
            $this->recordMutation($company, $account, $brand, 'MODIFIER_GROUP', $groupId, 'UPDATE', $key, $fingerprint, $version);
            $this->audit($company, 'RESTAURANT_MODIFIER_GROUP_UPDATED', 'restaurant_modifier_group', $groupId, (string) $row['status'], $status, 'RESTAURANT_MODIFIER_GROUP_UPDATED', ['version' => $version]);
            return ['group_id' => $groupId, 'version' => $version, 'duplicate' => false];
        });
    }

    /** @return array{modifier_id:int,version:int,duplicate:bool} */
    public function createModifier(array $input): array
    {
        $scope = $input;
        $groupId = self::positiveId($input['group_id'] ?? null, 'grupo');
        $name = self::text($input['name'] ?? null, 120, 'modificador');
        $delta = self::moneyMinor($input['price_delta'] ?? '0', true);
        $currency = self::currency($input['currency'] ?? 'EUR');
        $status = self::status($input['status'] ?? 'ACTIVE', ['ACTIVE','INACTIVE','ARCHIVED'], 'modificador');
        $order = self::order($input['sort_order'] ?? 0);
        $key = self::uuid($input['idempotency_key'] ?? null);
        $fingerprint = self::fingerprint(compact('groupId', 'name', 'delta', 'currency', 'status', 'order'));

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $groupId, $name, $delta, $currency, $status, $order, $key, $fingerprint
        ): array {
            $this->scopedRow('restaurant_modifier_group', 'id_restaurant_modifier_group', $groupId, $company, $account, $brand, true);
            $existing = $this->existingCreate(
                'restaurant_modifier', 'id_restaurant_modifier', $company, $account, $brand, $key, $fingerprint
            );
            if ($existing !== null) {
                return ['modifier_id' => (int) $existing['entity_id'], 'version' => (int) $existing['version'], 'duplicate' => true];
            }
            $stmt = $this->db->prepare(
                'INSERT INTO restaurant_modifier
                 (id_restaurant_modifier_group,id_restaurant_account,id_empresa,id_restaurant_brand,name,slug,price_delta_minor,currency,status,sort_order,version,idempotency_key,request_fingerprint)
                 VALUES (:group_id,:account,:company,:brand,:name,:slug,:delta,:currency,:status,:ordering,1,:key,:fingerprint)'
            );
            $stmt->execute([
                ':group_id' => $groupId, ':account' => $account, ':company' => $company, ':brand' => $brand,
                ':name' => $name, ':slug' => self::slug($name), ':delta' => $delta, ':currency' => $currency,
                ':status' => $status, ':ordering' => $order, ':key' => $key, ':fingerprint' => $fingerprint,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->audit($company, 'RESTAURANT_MODIFIER_CREATED', 'restaurant_modifier', $id, null, (string) $delta, 'RESTAURANT_MODIFIER_CREATED', ['group_id' => $groupId]);
            return ['modifier_id' => $id, 'version' => 1, 'duplicate' => false];
        });
    }

    /** @return array{modifier_id:int,version:int,duplicate:bool} */
    public function updateModifier(array $input): array
    {
        $scope = $input;
        $modifierId = self::positiveId($input['modifier_id'] ?? null, 'modificador');
        $expected = self::expectedVersion($input['expected_version'] ?? null);
        $name = self::text($input['name'] ?? null, 120, 'modificador');
        $delta = self::moneyMinor($input['price_delta'] ?? '0', true);
        $currency = self::currency($input['currency'] ?? 'EUR');
        $status = self::status($input['status'] ?? null, ['ACTIVE','INACTIVE','ARCHIVED'], 'modificador');
        $order = self::order($input['sort_order'] ?? 0);
        $key = self::uuid($input['idempotency_key'] ?? null);
        $fingerprint = self::fingerprint(compact('modifierId', 'expected', 'name', 'delta', 'currency', 'status', 'order'));

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $modifierId, $expected, $name, $delta, $currency, $status, $order, $key, $fingerprint
        ): array {
            $duplicate = $this->existingMutation($company, $account, $brand, 'MODIFIER', $modifierId, 'UPDATE', $key, $fingerprint);
            if ($duplicate !== null) {
                return ['modifier_id' => $modifierId, 'version' => $duplicate['version'], 'duplicate' => true];
            }
            $row = $this->scopedRow('restaurant_modifier', 'id_restaurant_modifier', $modifierId, $company, $account, $brand, true);
            if ((string) $row['status'] === 'ARCHIVED' && $status !== 'ARCHIVED') {
                throw new DomainException('Un modificador archivado no se reactiva silenciosamente.');
            }
            $stmt = $this->db->prepare(
                'UPDATE restaurant_modifier
                    SET name=:name,slug=:slug,price_delta_minor=:delta,currency=:currency,status=:status,
                        sort_order=:ordering,version=version+1
                  WHERE id_restaurant_modifier=:id AND id_empresa=:company AND version=:expected'
            );
            $stmt->execute([
                ':name' => $name, ':slug' => self::slug($name), ':delta' => $delta, ':currency' => $currency,
                ':status' => $status, ':ordering' => $order, ':id' => $modifierId,
                ':company' => $company, ':expected' => $expected,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('Conflicto de versión al editar el modificador.');
            }
            $version = $expected + 1;
            $this->recordMutation($company, $account, $brand, 'MODIFIER', $modifierId, 'UPDATE', $key, $fingerprint, $version);
            $this->audit($company, 'RESTAURANT_MODIFIER_UPDATED', 'restaurant_modifier', $modifierId, (string) $row['price_delta_minor'], (string) $delta, 'RESTAURANT_MODIFIER_UPDATED', ['version' => $version]);
            return ['modifier_id' => $modifierId, 'version' => $version, 'duplicate' => false];
        });
    }

    /** @return array{product_id:int,group_id:int,duplicate:bool} */
    public function attachToProduct(array $input): array
    {
        $scope = $input;
        $productId = self::positiveId($input['product_id'] ?? null, 'producto');
        $groupId = self::positiveId($input['group_id'] ?? null, 'grupo');
        $order = self::order($input['sort_order'] ?? 0);
        $key = self::uuid($input['idempotency_key'] ?? null);
        $fingerprint = self::fingerprint(compact('productId', 'groupId', 'order'));

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $productId, $groupId, $order, $key, $fingerprint
        ): array {
            $this->scopedRow('restaurant_product', 'id_restaurant_product', $productId, $company, $account, $brand, true);
            $this->scopedRow('restaurant_modifier_group', 'id_restaurant_modifier_group', $groupId, $company, $account, $brand, true);
            $duplicate = $this->existingMutation($company, $account, $brand, 'PRODUCT', $productId, 'ATTACH_MODIFIER_GROUP', $key, $fingerprint);
            if ($duplicate !== null) {
                return ['product_id' => $productId, 'group_id' => $groupId, 'duplicate' => true];
            }
            $stmt = $this->db->prepare(
                'INSERT INTO restaurant_product_modifier_group
                 (id_restaurant_product,id_restaurant_modifier_group,id_restaurant_account,id_empresa,id_restaurant_brand,sort_order)
                 VALUES (:product,:group_id,:account,:company,:brand,:ordering)
                 ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order)'
            );
            $stmt->execute([
                ':product' => $productId, ':group_id' => $groupId, ':account' => $account,
                ':company' => $company, ':brand' => $brand, ':ordering' => $order,
            ]);
            $this->recordMutation($company, $account, $brand, 'PRODUCT', $productId, 'ATTACH_MODIFIER_GROUP', $key, $fingerprint, 1);
            $this->audit($company, 'RESTAURANT_MODIFIER_GROUP_ATTACHED', 'restaurant_product', $productId, null, (string) $groupId, 'RESTAURANT_MODIFIER_GROUP_ATTACHED', ['group_id' => $groupId]);
            return ['product_id' => $productId, 'group_id' => $groupId, 'duplicate' => false];
        });
    }
}
