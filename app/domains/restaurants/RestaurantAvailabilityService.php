<?php

require_once __DIR__ . '/RestaurantCatalogServiceBase.php';

final class RestaurantAvailabilityService extends RestaurantCatalogServiceBase
{
    /** @return array{availability_id:int,version:int,is_available:bool,duplicate:bool} */
    public function setAvailability(array $input): array
    {
        $scope = $input;
        $productId = self::positiveId($input['product_id'] ?? null, 'producto');
        $variantId = isset($input['variant_id']) && $input['variant_id'] !== ''
            ? self::positiveId($input['variant_id'], 'variante') : null;
        $available = filter_var($input['is_available'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($available === null) {
            throw new InvalidArgumentException('Disponibilidad inválida.');
        }
        $reason = self::text($input['reason'] ?? null, 255, 'motivo', true);
        $expected = filter_var($input['expected_version'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($expected === false) {
            throw new InvalidArgumentException('Versión de disponibilidad inválida.');
        }
        $key = self::uuid($input['idempotency_key'] ?? null);

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $input, $productId, $variantId, $available, $reason, $expected, $key
        ): array {
            $this->scopedRow('restaurant_product', 'id_restaurant_product', $productId, $company, $account, $brand, true);
            if ($variantId !== null) {
                $variant = $this->scopedRow(
                    'restaurant_product_variant', 'id_restaurant_product_variant', $variantId,
                    $company, $account, $brand, true
                );
                if ((int) $variant['id_restaurant_product'] !== $productId) {
                    throw new DomainException('La variante no pertenece al producto.');
                }
            }
            $dimensions = $this->dimensions($input, $company, $account, $brand);
            $scopeKey = self::scopeKey($productId, $variantId, $dimensions);
            $fingerprint = self::fingerprint([
                'product_id' => $productId, 'variant_id' => $variantId, 'available' => $available,
                'reason' => $reason, 'expected_version' => (int) $expected,
                'scope_type' => $dimensions['scope_type'], 'location_id' => $dimensions['location_id'],
                'channel' => $dimensions['channel'],
            ]);

            $retry = $this->db->prepare(
                'SELECT id_restaurant_availability,id_restaurant_account,id_restaurant_brand,
                        request_fingerprint,result_version,new_is_available
                   FROM restaurant_availability_history
                  WHERE id_empresa=:company AND idempotency_key=:key LIMIT 1 FOR UPDATE'
            );
            $retry->execute([':company' => $company, ':key' => $key]);
            $history = $retry->fetch(PDO::FETCH_ASSOC);
            if ($history) {
                if ((int) $history['id_restaurant_account'] !== $account
                    || (int) $history['id_restaurant_brand'] !== $brand
                    || !hash_equals((string) $history['request_fingerprint'], $fingerprint)) {
                    throw new DomainException('La clave idempotente de disponibilidad ya se usó con otros datos.');
                }
                return [
                    'availability_id' => (int) $history['id_restaurant_availability'],
                    'version' => (int) $history['result_version'],
                    'is_available' => (bool) $history['new_is_available'],
                    'duplicate' => true,
                ];
            }

            $find = $this->db->prepare(
                'SELECT * FROM restaurant_availability
                  WHERE id_empresa=:company AND id_restaurant_account=:account
                    AND id_restaurant_brand=:brand AND scope_key=:scope_key LIMIT 1 FOR UPDATE'
            );
            $find->execute([':company' => $company, ':account' => $account, ':brand' => $brand, ':scope_key' => $scopeKey]);
            $current = $find->fetch(PDO::FETCH_ASSOC);
            $old = null;
            if (!$current) {
                if ((int) $expected !== 0) {
                    throw new DomainException('La disponibilidad aún no existe; expected_version debe ser 0.');
                }
                $insert = $this->db->prepare(
                    'INSERT INTO restaurant_availability
                     (id_restaurant_product,id_restaurant_product_variant,id_restaurant_location,id_restaurant_account,id_empresa,
                      id_restaurant_brand,scope_type,channel,scope_key,is_available,reason,version,idempotency_key,request_fingerprint,updated_by)
                     VALUES (:product,:variant,:location,:account,:company,:brand,:scope_type,:channel,:scope_key,:available,:reason,1,:key,:fingerprint,:actor)'
                );
                $insert->execute([
                    ':product' => $productId, ':variant' => $variantId, ':location' => $dimensions['location_id'],
                    ':account' => $account, ':company' => $company, ':brand' => $brand,
                    ':scope_type' => $dimensions['scope_type'], ':channel' => $dimensions['channel'],
                    ':scope_key' => $scopeKey, ':available' => $available ? 1 : 0, ':reason' => $reason,
                    ':key' => $key, ':fingerprint' => $fingerprint, ':actor' => $this->actorId,
                ]);
                $id = (int) $this->db->lastInsertId();
                $version = 1;
            } else {
                $id = (int) $current['id_restaurant_availability'];
                $old = (int) $current['is_available'];
                if ((int) $current['version'] !== (int) $expected) {
                    throw new DomainException('Conflicto de versión al cambiar disponibilidad.');
                }
                $update = $this->db->prepare(
                    'UPDATE restaurant_availability
                        SET is_available=:available,reason=:reason,version=version+1,
                            idempotency_key=:key,request_fingerprint=:fingerprint,updated_by=:actor
                      WHERE id_restaurant_availability=:id AND id_empresa=:company AND version=:expected'
                );
                $update->execute([
                    ':available' => $available ? 1 : 0, ':reason' => $reason,
                    ':key' => $key, ':fingerprint' => $fingerprint, ':actor' => $this->actorId,
                    ':id' => $id, ':company' => $company, ':expected' => $expected,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('Conflicto de versión al cambiar disponibilidad.');
                }
                $version = (int) $expected + 1;
            }
            $historyInsert = $this->db->prepare(
                'INSERT INTO restaurant_availability_history
                 (id_restaurant_availability,id_restaurant_product,id_restaurant_account,id_empresa,id_restaurant_brand,
                  old_is_available,new_is_available,result_version,id_actor,idempotency_key,request_fingerprint)
                 VALUES (:availability,:product,:account,:company,:brand,:old_value,:new_value,:version,:actor,:key,:fingerprint)'
            );
            $historyInsert->execute([
                ':availability' => $id, ':product' => $productId, ':account' => $account,
                ':company' => $company, ':brand' => $brand, ':old_value' => $old,
                ':new_value' => $available ? 1 : 0, ':version' => $version, ':actor' => $this->actorId,
                ':key' => $key, ':fingerprint' => $fingerprint,
            ]);
            $this->audit(
                $company, 'RESTAURANT_AVAILABILITY_CHANGED', 'restaurant_availability', $id,
                $old === null ? null : (string) $old, $available ? '1' : '0', 'RESTAURANT_AVAILABILITY_CHANGED',
                ['product_id' => $productId, 'scope_type' => $dimensions['scope_type'], 'version' => $version]
            );
            return ['availability_id' => $id, 'version' => $version, 'is_available' => $available, 'duplicate' => false];
        });
    }

    /** @return list<array<string,mixed>> */
    public function candidates(int $companyId, int $accountId, int $brandId, int $productId): array
    {
        $this->assertRootScope($companyId, $accountId, $brandId);
        $this->scopedRow('restaurant_product', 'id_restaurant_product', $productId, $companyId, $accountId, $brandId);
        $stmt = $this->db->prepare(
            'SELECT id_restaurant_availability AS availability_id,id_restaurant_product_variant AS variant_id,
                    scope_type,id_restaurant_location AS location_id,channel,is_available,reason,version
               FROM restaurant_availability
              WHERE id_empresa=:company AND id_restaurant_account=:account
                AND id_restaurant_brand=:brand AND id_restaurant_product=:product
              ORDER BY scope_type,id_restaurant_location,channel,id_restaurant_availability'
        );
        $stmt->execute([':company' => $companyId, ':account' => $accountId, ':brand' => $brandId, ':product' => $productId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
