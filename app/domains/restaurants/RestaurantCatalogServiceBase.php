<?php

require_once dirname(__DIR__, 2) . '/helpers/AuditPolicy.php';
require_once dirname(__DIR__, 2) . '/helpers/Money.php';
require_once dirname(__DIR__, 2) . '/helpers/TenantLifecyclePolicy.php';
require_once dirname(__DIR__, 2) . '/models/LogModel.php';

/**
 * Límite técnico común de R02.
 *
 * No es autorización HTTP. Mientras RESTAURANT_RBAC_PENDING_JAMA siga abierto,
 * solo acepta un operador global activo y las capas Restaurant no publican
 * rutas. El scope completo se vuelve a comprobar dentro de cada transacción.
 */
abstract class RestaurantCatalogServiceBase
{
    protected const MAX_MONEY_MINOR = 999999999999;
    protected PDO $db;
    protected int $actorId;

    public function __construct(PDO $db, int $actorId)
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('Falta el actor sintético de Restaurants.');
        }
        $this->db = $db;
        $this->actorId = $actorId;
        $this->assertActorActive();
    }

    protected function assertActorActive(): void
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM usuario
              WHERE id_usuario=:actor AND rol='superadmin' AND activo=1
                AND id_empresa IS NULL AND id_gimnasio IS NULL LIMIT 1"
        );
        $stmt->execute([':actor' => $this->actorId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('R02 solo admite un operador global sintético válido.');
        }
    }

    /** @return mixed */
    protected function write(array $scope, callable $operation)
    {
        $companyId = self::positiveId($scope['company_id'] ?? null, 'empresa');
        $accountId = self::positiveId($scope['account_id'] ?? null, 'account');
        $brandId = self::positiveId($scope['brand_id'] ?? null, 'marca');
        $lease = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $companyId);
        try {
            $this->db->beginTransaction();
            $this->assertRootScope($companyId, $accountId, $brandId);
            $result = $operation($companyId, $accountId, $brandId);
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($error instanceof DomainException || $error instanceof InvalidArgumentException) {
                throw $error;
            }
            if ($error instanceof PDOException && (string) $error->getCode() === '23000') {
                throw new DomainException('La mutación Restaurant entra en conflicto con el contrato de datos.', 0, $error);
            }
            throw new RuntimeException('La mutación Restaurant falló sin confirmar efectos parciales.', 0, $error);
        } finally {
            $lease->release();
        }
    }

    protected function assertRootScope(int $companyId, int $accountId, int $brandId): void
    {
        // La autorización se comprueba en cada uso: una instancia creada antes
        // del offboarding no conserva privilegios después de desactivar al actor.
        $this->assertActorActive();
        $stmt = $this->db->prepare(
            "SELECT 1
               FROM restaurant_account a
               INNER JOIN restaurant_brand b
                 ON b.id_restaurant_account=a.id_restaurant_account AND b.id_empresa=a.id_empresa
              WHERE a.id_empresa=:company AND a.id_restaurant_account=:account
                AND b.id_restaurant_brand=:brand
                AND a.status='ACTIVE' AND b.status='ACTIVE'
              LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([':company' => $companyId, ':account' => $accountId, ':brand' => $brandId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('El ámbito account/brand de Restaurants no está operativo.');
        }
    }

    /** @return array<string,mixed> */
    protected function scopedRow(
        string $table,
        string $idColumn,
        int $id,
        int $companyId,
        int $accountId,
        int $brandId,
        bool $forUpdate = false
    ): array {
        $allowed = [
            'restaurant_catalog' => 'id_restaurant_catalog',
            'restaurant_category' => 'id_restaurant_category',
            'restaurant_product' => 'id_restaurant_product',
            'restaurant_product_variant' => 'id_restaurant_product_variant',
            'restaurant_modifier_group' => 'id_restaurant_modifier_group',
            'restaurant_modifier' => 'id_restaurant_modifier',
            'restaurant_price' => 'id_restaurant_price',
            'restaurant_availability' => 'id_restaurant_availability',
            'restaurant_product_allergen_declaration' => 'id_restaurant_allergen_declaration',
            'restaurant_product_media' => 'id_restaurant_product_media',
        ];
        if (($allowed[$table] ?? null) !== $idColumn) {
            throw new LogicException('Entidad Restaurant no permitida.');
        }
        $sql = "SELECT * FROM {$table}
                 WHERE {$idColumn}=:id AND id_empresa=:company
                   AND id_restaurant_account=:account AND id_restaurant_brand=:brand LIMIT 1";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':company' => $companyId,
            ':account' => $accountId,
            ':brand' => $brandId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('El recurso Restaurant no pertenece al ámbito solicitado.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    protected function locationRow(int $id, int $companyId, int $accountId, int $brandId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM restaurant_location
              WHERE id_restaurant_location=:id AND id_empresa=:company
                AND id_restaurant_account=:account AND id_restaurant_brand=:brand
                AND status='ACTIVE' LIMIT 1"
        );
        $stmt->execute([':id' => $id, ':company' => $companyId, ':account' => $accountId, ':brand' => $brandId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('El local no pertenece a la marca solicitada.');
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    protected function existingCreate(
        string $table,
        string $idColumn,
        int $companyId,
        int $accountId,
        int $brandId,
        string $key,
        string $fingerprint
    ): ?array {
        $allowed = [
            'restaurant_catalog' => 'id_restaurant_catalog',
            'restaurant_category' => 'id_restaurant_category',
            'restaurant_product' => 'id_restaurant_product',
            'restaurant_product_variant' => 'id_restaurant_product_variant',
            'restaurant_modifier_group' => 'id_restaurant_modifier_group',
            'restaurant_modifier' => 'id_restaurant_modifier',
            'restaurant_product_allergen_declaration' => 'id_restaurant_allergen_declaration',
            'restaurant_product_media' => 'id_restaurant_product_media',
        ];
        if (($allowed[$table] ?? null) !== $idColumn) {
            throw new LogicException('Idempotencia no permitida para la entidad.');
        }
        $stmt = $this->db->prepare(
            "SELECT {$idColumn} AS entity_id,id_restaurant_account,id_restaurant_brand,request_fingerprint,version
               FROM {$table} WHERE id_empresa=:company AND idempotency_key=:key LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([':company' => $companyId, ':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if ((int) $row['id_restaurant_account'] !== $accountId
            || (int) $row['id_restaurant_brand'] !== $brandId
            || !hash_equals((string) $row['request_fingerprint'], $fingerprint)) {
            throw new DomainException('La clave idempotente ya fue usada con otro ámbito o payload.');
        }
        return $row;
    }

    /** @return array{duplicate:bool,version:int}|null */
    protected function existingMutation(
        int $companyId,
        int $accountId,
        int $brandId,
        string $entityType,
        int $entityId,
        string $operation,
        string $key,
        string $fingerprint
    ): ?array {
        $stmt = $this->db->prepare(
            'SELECT * FROM restaurant_catalog_mutation
              WHERE id_empresa=:company AND idempotency_key=:key LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':company' => $companyId, ':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $matches = (int) $row['id_restaurant_account'] === $accountId
            && (int) $row['id_restaurant_brand'] === $brandId
            && (string) $row['entity_type'] === $entityType
            && (int) $row['entity_id'] === $entityId
            && (string) $row['operation'] === $operation
            && hash_equals((string) $row['request_fingerprint'], $fingerprint);
        if (!$matches) {
            throw new DomainException('La clave idempotente ya fue usada con otra mutación.');
        }
        return ['duplicate' => true, 'version' => (int) $row['result_version']];
    }

    protected function recordMutation(
        int $companyId,
        int $accountId,
        int $brandId,
        string $entityType,
        int $entityId,
        string $operation,
        string $key,
        string $fingerprint,
        int $resultVersion
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO restaurant_catalog_mutation
             (id_restaurant_account,id_empresa,id_restaurant_brand,entity_type,entity_id,operation,
              idempotency_key,request_fingerprint,result_version,id_actor)
             VALUES (:account,:company,:brand,:type,:entity,:operation,:key,:fingerprint,:version,:actor)'
        );
        $stmt->execute([
            ':account' => $accountId,
            ':company' => $companyId,
            ':brand' => $brandId,
            ':type' => $entityType,
            ':entity' => $entityId,
            ':operation' => $operation,
            ':key' => $key,
            ':fingerprint' => $fingerprint,
            ':version' => $resultVersion,
            ':actor' => $this->actorId,
        ]);
    }

    protected function audit(
        int $companyId,
        string $action,
        string $entity,
        ?int $entityId,
        ?string $before,
        ?string $after,
        string $reason,
        array $metadata = []
    ): void {
        (new LogModel($companyId, $this->db))->registrarCambio(
            $this->actorId,
            $action,
            'Mutación interna de Restaurants R02',
            null,
            $entity,
            $entityId,
            $before,
            $after,
            null,
            'exito',
            $reason,
            $metadata,
            'usuario',
            'SYSTEM',
            AuditPolicy::REQUIRED
        );
    }

    protected static function positiveId(mixed $value, string $field): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new InvalidArgumentException('Identificador inválido: ' . $field . '.');
        }
        return (int) $validated;
    }

    protected static function expectedVersion(mixed $value): int
    {
        return self::positiveId($value, 'version');
    }

    protected static function text(mixed $value, int $max, string $field, bool $nullable = false): ?string
    {
        $text = trim((string) $value);
        if ($text === '' && $nullable) {
            return null;
        }
        if ($text === '' || mb_strlen($text) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text)) {
            throw new InvalidArgumentException('Texto inválido: ' . $field . '.');
        }
        return $text;
    }

    protected static function status(mixed $value, array $allowed, string $field): string
    {
        $status = strtoupper(trim((string) $value));
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Estado inválido: ' . $field . '.');
        }
        return $status;
    }

    protected static function order(mixed $value): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
        if ($validated === false) {
            throw new InvalidArgumentException('Orden inválido.');
        }
        return (int) $validated;
    }

    protected static function uuid(mixed $value): string
    {
        $key = strtolower(trim((string) $value));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key)) {
            throw new InvalidArgumentException('La clave idempotente no es un UUID v4 válido.');
        }
        return $key;
    }

    protected static function fingerprint(array $payload): string
    {
        ksort($payload);
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected static function slug(string $value): string
    {
        $slug = mb_strtolower($value);
        $slug = strtr($slug, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c']);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        if ($slug === '') {
            throw new InvalidArgumentException('No se pudo construir un slug válido.');
        }
        return mb_substr($slug, 0, 100);
    }

    protected static function moneyMinor(mixed $value, bool $allowNegative = false): int
    {
        $minor = Money::cents($value);
        if ((!$allowNegative && $minor < 0) || abs($minor) > self::MAX_MONEY_MINOR) {
            throw new InvalidArgumentException('Importe Restaurant fuera de rango.');
        }
        return $minor;
    }

    protected static function currency(mixed $value): string
    {
        $currency = strtoupper(trim((string) $value));
        if ($currency !== 'EUR') {
            throw new InvalidArgumentException('R02 solo admite la moneda EUR de forma explícita.');
        }
        return $currency;
    }

    protected static function channel(mixed $value, bool $nullable = false): ?string
    {
        if (($value === null || trim((string) $value) === '') && $nullable) {
            return null;
        }
        return self::status($value, ['IN_STORE','QR','TAKEAWAY','WEB','DELIVERY'], 'channel');
    }

    /** @return array{scope_type:string,location_id:?int,channel:?string} */
    protected function dimensions(array $input, int $companyId, int $accountId, int $brandId): array
    {
        $type = self::status($input['scope_type'] ?? 'BRAND', ['BRAND','LOCATION','CHANNEL','LOCATION_CHANNEL'], 'scope');
        $location = isset($input['location_id']) && $input['location_id'] !== ''
            ? self::positiveId($input['location_id'], 'local')
            : null;
        $channel = self::channel($input['channel'] ?? null, true);
        $valid = ($type === 'BRAND' && $location === null && $channel === null)
            || ($type === 'LOCATION' && $location !== null && $channel === null)
            || ($type === 'CHANNEL' && $location === null && $channel !== null)
            || ($type === 'LOCATION_CHANNEL' && $location !== null && $channel !== null);
        if (!$valid) {
            throw new InvalidArgumentException('Las dimensiones de ámbito son incoherentes.');
        }
        if ($location !== null) {
            $this->locationRow($location, $companyId, $accountId, $brandId);
        }
        return ['scope_type' => $type, 'location_id' => $location, 'channel' => $channel];
    }

    protected static function scopeKey(int $productId, ?int $variantId, array $dimensions): string
    {
        return hash('sha256', implode(':', [
            $productId,
            $variantId ?? 0,
            $dimensions['scope_type'],
            $dimensions['location_id'] ?? 0,
            $dimensions['channel'] ?? '-',
        ]));
    }
}
