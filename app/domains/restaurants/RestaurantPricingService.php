<?php

require_once __DIR__ . '/RestaurantCatalogServiceBase.php';

final class RestaurantPricingService extends RestaurantCatalogServiceBase
{
    /** @return array{price_id:int,version:int,amount_minor:int,currency:string,duplicate:bool} */
    public function setPrice(array $input): array
    {
        $scope = $input;
        $productId = self::positiveId($input['product_id'] ?? null, 'producto');
        $variantId = isset($input['variant_id']) && $input['variant_id'] !== ''
            ? self::positiveId($input['variant_id'], 'variante') : null;
        $amount = self::moneyMinor($input['amount'] ?? null);
        $currency = self::currency($input['currency'] ?? 'EUR');
        $expected = filter_var($input['expected_version'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($expected === false) {
            throw new InvalidArgumentException('Versión de precio inválida.');
        }
        $key = self::uuid($input['idempotency_key'] ?? null);

        return $this->write($scope, function (int $company, int $account, int $brand) use (
            $input, $productId, $variantId, $amount, $currency, $expected, $key
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
                'product_id' => $productId, 'variant_id' => $variantId, 'amount_minor' => $amount,
                'currency' => $currency, 'expected_version' => (int) $expected,
                'scope_type' => $dimensions['scope_type'], 'location_id' => $dimensions['location_id'],
                'channel' => $dimensions['channel'],
            ]);

            $retry = $this->db->prepare(
                'SELECT h.id_restaurant_price,h.id_restaurant_account,h.id_restaurant_brand,
                        h.request_fingerprint,h.result_version,h.new_amount_minor,h.currency
                   FROM restaurant_price_history h
                  WHERE h.id_empresa=:company AND h.idempotency_key=:key LIMIT 1 FOR UPDATE'
            );
            $retry->execute([':company' => $company, ':key' => $key]);
            $existingHistory = $retry->fetch(PDO::FETCH_ASSOC);
            if ($existingHistory) {
                if ((int) $existingHistory['id_restaurant_account'] !== $account
                    || (int) $existingHistory['id_restaurant_brand'] !== $brand
                    || !hash_equals((string) $existingHistory['request_fingerprint'], $fingerprint)) {
                    throw new DomainException('La clave idempotente de precio ya se usó con otros datos.');
                }
                return [
                    'price_id' => (int) $existingHistory['id_restaurant_price'],
                    'version' => (int) $existingHistory['result_version'],
                    'amount_minor' => (int) $existingHistory['new_amount_minor'],
                    'currency' => (string) $existingHistory['currency'],
                    'duplicate' => true,
                ];
            }

            $find = $this->db->prepare(
                'SELECT * FROM restaurant_price
                  WHERE id_empresa=:company AND id_restaurant_account=:account
                    AND id_restaurant_brand=:brand AND scope_key=:scope_key LIMIT 1 FOR UPDATE'
            );
            $find->execute([':company' => $company, ':account' => $account, ':brand' => $brand, ':scope_key' => $scopeKey]);
            $price = $find->fetch(PDO::FETCH_ASSOC);
            $oldAmount = null;
            if (!$price) {
                if ((int) $expected !== 0) {
                    throw new DomainException('El precio aún no existe; expected_version debe ser 0.');
                }
                $insert = $this->db->prepare(
                    'INSERT INTO restaurant_price
                     (id_restaurant_product,id_restaurant_product_variant,id_restaurant_location,id_restaurant_account,id_empresa,
                      id_restaurant_brand,scope_type,channel,scope_key,amount_minor,currency,status,version,idempotency_key,request_fingerprint)
                     VALUES (:product,:variant,:location,:account,:company,:brand,:scope_type,:channel,:scope_key,:amount,:currency,\'ACTIVE\',1,:key,:fingerprint)'
                );
                $insert->execute([
                    ':product' => $productId, ':variant' => $variantId, ':location' => $dimensions['location_id'],
                    ':account' => $account, ':company' => $company, ':brand' => $brand,
                    ':scope_type' => $dimensions['scope_type'], ':channel' => $dimensions['channel'],
                    ':scope_key' => $scopeKey, ':amount' => $amount, ':currency' => $currency,
                    ':key' => $key, ':fingerprint' => $fingerprint,
                ]);
                $priceId = (int) $this->db->lastInsertId();
                $version = 1;
            } else {
                $priceId = (int) $price['id_restaurant_price'];
                $oldAmount = (int) $price['amount_minor'];
                if ((int) $price['version'] !== (int) $expected) {
                    throw new DomainException('Conflicto de versión al cambiar el precio.');
                }
                $update = $this->db->prepare(
                    'UPDATE restaurant_price
                        SET amount_minor=:amount,currency=:currency,status=\'ACTIVE\',version=version+1,
                            idempotency_key=:key,request_fingerprint=:fingerprint
                      WHERE id_restaurant_price=:id AND id_empresa=:company AND version=:expected'
                );
                $update->execute([
                    ':amount' => $amount, ':currency' => $currency, ':key' => $key,
                    ':fingerprint' => $fingerprint, ':id' => $priceId, ':company' => $company, ':expected' => $expected,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('Conflicto de versión al cambiar el precio.');
                }
                $version = (int) $expected + 1;
            }

            $history = $this->db->prepare(
                'INSERT INTO restaurant_price_history
                 (id_restaurant_price,id_restaurant_product,id_restaurant_account,id_empresa,id_restaurant_brand,
                  old_amount_minor,new_amount_minor,result_version,currency,id_actor,idempotency_key,request_fingerprint)
                 VALUES (:price,:product,:account,:company,:brand,:old_amount,:new_amount,:result_version,:currency,:actor,:key,:fingerprint)'
            );
            $history->execute([
                ':price' => $priceId, ':product' => $productId, ':account' => $account,
                ':company' => $company, ':brand' => $brand, ':old_amount' => $oldAmount,
                ':new_amount' => $amount, ':result_version' => $version, ':currency' => $currency, ':actor' => $this->actorId,
                ':key' => $key, ':fingerprint' => $fingerprint,
            ]);
            $this->audit(
                $company,
                'RESTAURANT_PRICE_CHANGED',
                'restaurant_price',
                $priceId,
                $oldAmount === null ? null : (string) $oldAmount,
                (string) $amount,
                'RESTAURANT_PRICE_CHANGED',
                ['product_id' => $productId, 'scope_type' => $dimensions['scope_type'], 'version' => $version]
            );
            return ['price_id' => $priceId, 'version' => $version, 'amount_minor' => $amount, 'currency' => $currency, 'duplicate' => false];
        });
    }

    /**
     * Devuelve candidatos, no un ganador. La precedencia comercial permanece
     * DOMAIN_DECISION_PENDING_JAMA.
     *
     * @return list<array<string,mixed>>
     */
    public function candidates(int $companyId, int $accountId, int $brandId, int $productId, ?int $variantId = null): array
    {
        $this->assertRootScope($companyId, $accountId, $brandId);
        $this->scopedRow('restaurant_product', 'id_restaurant_product', $productId, $companyId, $accountId, $brandId);
        $sql = 'SELECT id_restaurant_price AS price_id,scope_type,id_restaurant_location AS location_id,
                       channel,amount_minor,currency,version
                  FROM restaurant_price
                 WHERE id_empresa=:company AND id_restaurant_account=:account AND id_restaurant_brand=:brand
                   AND id_restaurant_product=:product AND status=\'ACTIVE\'';
        $params = [':company' => $companyId, ':account' => $accountId, ':brand' => $brandId, ':product' => $productId];
        if ($variantId === null) {
            $sql .= ' AND id_restaurant_product_variant IS NULL';
        } else {
            $sql .= ' AND id_restaurant_product_variant=:variant';
            $params[':variant'] = $variantId;
        }
        $sql .= ' ORDER BY scope_type,id_restaurant_location,channel,id_restaurant_price';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
