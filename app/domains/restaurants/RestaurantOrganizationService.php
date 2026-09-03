<?php

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/helpers/AuditPolicy.php';
require_once dirname(__DIR__, 2) . '/helpers/InputValidator.php';
require_once dirname(__DIR__, 2) . '/helpers/TenantLifecyclePolicy.php';
require_once dirname(__DIR__, 2) . '/models/LogModel.php';

/**
 * Primer límite de escritura de Gimnera Restaurants.
 *
 * No tiene rutas HTTP en esta fase. Solo una identidad global activa puede
 * crear el account de Restaurants de una organización Platform. Marca,
 * entidad legal y primer local se confirman en una única transacción.
 */
final class RestaurantOrganizationService
{
    private PDO $db;
    private int $actorId;
    /** @var null|callable(string):void */
    private $faultInjector;

    public function __construct(PDO $db, int $actorId, ?callable $faultInjector = null)
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('Falta el operador de plataforma.');
        }
        $this->db = $db;
        $this->actorId = $actorId;
        $this->faultInjector = $faultInjector;
        $this->assertGlobalPlatformAdmin();
    }

    /** @return array<string,int|string|bool> */
    public function provision(array $input): array
    {
        $data = $this->validate($input);
        $companyId = $data['company_id'];
        $lease = TenantLifecyclePolicy::acquirePlatformTransition($this->db, $companyId);

        try {
            $this->db->beginTransaction();

            $existingByKey = $this->findByIdempotencyKey($data['idempotency_key'], true);
            if ($existingByKey !== null) {
                if ((int) $existingByKey['id_empresa'] !== $companyId) {
                    throw new DomainException('La clave idempotente pertenece a otra organización.');
                }
                if (!hash_equals((string) $existingByKey['request_fingerprint'], $data['request_fingerprint'])) {
                    throw new DomainException('La clave idempotente ya fue utilizada con otros datos.');
                }
                $result = $this->resultForAccount($companyId, (int) $existingByKey['id_restaurant_account']);
                $this->db->commit();
                $result['duplicate'] = true;
                return $result;
            }

            $this->assertCompanyOperational($companyId);
            if ($this->findAccountForCompany($companyId, true) !== null) {
                throw new DomainException('La organización ya dispone de Gimnera Restaurants.');
            }

            $accountId = $this->insertAccount($data);
            $this->inject('account');
            $brandId = $this->insertBrand($accountId, $data);
            $this->inject('brand');
            $legalEntityId = $this->insertLegalEntity($accountId, $data);
            $this->inject('legal_entity');
            $locationId = $this->insertLocation($accountId, $brandId, $legalEntityId, $data);
            $this->inject('location');

            $activate = $this->db->prepare(
                "UPDATE restaurant_account SET status='ACTIVE'
                  WHERE id_restaurant_account=:account AND id_empresa=:company AND status='CONFIGURING'"
            );
            $activate->execute([':account' => $accountId, ':company' => $companyId]);
            if ($activate->rowCount() !== 1) {
                throw new RuntimeException('La foundation de Restaurants perdió su estado previo.');
            }

            (new LogModel($companyId, $this->db))->registrarCambio(
                $this->actorId,
                'RESTAURANT_ORGANIZATION_PROVISIONED',
                'Foundation organizativa de Restaurants creada',
                null,
                'restaurant_account',
                $accountId,
                'CONFIGURING',
                'ACTIVE',
                null,
                'exito',
                'RESTAURANT_FOUNDATION_READY',
                ['brand_id' => $brandId, 'legal_entity_id' => $legalEntityId, 'location_id' => $locationId],
                'usuario',
                'SYSTEM',
                AuditPolicy::REQUIRED
            );
            $this->inject('audit');

            $this->db->commit();
            return [
                'duplicate' => false,
                'company_id' => $companyId,
                'account_id' => $accountId,
                'brand_id' => $brandId,
                'legal_entity_id' => $legalEntityId,
                'location_id' => $locationId,
                'status' => 'ACTIVE',
            ];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->auditFailure($companyId, $error);
            if ($error instanceof DomainException || $error instanceof InvalidArgumentException) {
                throw $error;
            }
            if ($error instanceof PDOException && (string) $error->getCode() === '23000') {
                throw new DomainException('La foundation de Restaurants entra en conflicto con datos existentes.', 0, $error);
            }
            throw new RuntimeException('No se pudo crear Restaurants; no se guardó un estado parcial.', 0, $error);
        } finally {
            $lease->release();
        }
    }

    /** @return array<string,int|string>|null */
    public function findScoped(int $companyId, int $accountId): ?array
    {
        if ($companyId <= 0 || $accountId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT a.id_restaurant_account AS account_id,a.id_empresa AS company_id,a.status,
                    b.id_restaurant_brand AS brand_id,b.name AS brand_name,
                    le.id_restaurant_legal_entity AS legal_entity_id,le.name AS legal_entity_name,
                    l.id_restaurant_location AS location_id,l.name AS location_name,l.timezone
               FROM restaurant_account a
               INNER JOIN restaurant_brand b
                 ON b.id_restaurant_account=a.id_restaurant_account AND b.id_empresa=a.id_empresa
               INNER JOIN restaurant_legal_entity le
                 ON le.id_restaurant_account=a.id_restaurant_account AND le.id_empresa=a.id_empresa
               INNER JOIN restaurant_location l
                 ON l.id_restaurant_account=a.id_restaurant_account
                AND l.id_empresa=a.id_empresa
                AND l.id_restaurant_brand=b.id_restaurant_brand
                AND l.id_restaurant_legal_entity=le.id_restaurant_legal_entity
              WHERE a.id_empresa=:company AND a.id_restaurant_account=:account
              LIMIT 1'
        );
        $stmt->execute([':company' => $companyId, ':account' => $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array{company_id:int,idempotency_key:string,request_fingerprint:string,brand_name:string,legal_entity_name:string,location_name:string,timezone:string} */
    private function validate(array $input): array
    {
        $companyId = filter_var($input['company_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $key = mb_strtolower(trim((string) ($input['idempotency_key'] ?? '')));
        $brand = InputValidator::text($input['brand_name'] ?? '', 150);
        $legal = InputValidator::text($input['legal_entity_name'] ?? '', 180);
        $location = InputValidator::text($input['location_name'] ?? '', 150);
        $timezone = trim((string) ($input['timezone'] ?? 'Europe/Madrid'));

        if ($companyId === false || !$brand || !$legal || !$location) {
            throw new InvalidArgumentException('La foundation de Restaurants contiene datos incompletos o inválidos.');
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key)) {
            throw new InvalidArgumentException('La solicitud idempotente de Restaurants no es válida.');
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('La zona horaria del local no es válida.');
        }

        $normalized = [
            'company_id' => (int) $companyId,
            'brand_name' => $brand,
            'legal_entity_name' => $legal,
            'location_name' => $location,
            'timezone' => $timezone,
        ];
        return ['idempotency_key' => $key, 'request_fingerprint' => hash(
            'sha256',
            json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        )] + $normalized;
    }

    private function assertGlobalPlatformAdmin(): void
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM usuario
              WHERE id_usuario=:actor AND rol='superadmin' AND activo=1
                AND id_empresa IS NULL AND id_gimnasio IS NULL
              LIMIT 1"
        );
        $stmt->execute([':actor' => $this->actorId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('Solo una identidad global activa puede aprovisionar Restaurants.');
        }
    }

    private function assertCompanyOperational(int $companyId): void
    {
        $stmt = $this->db->prepare(
            'SELECT estado,onboarding_state FROM empresa WHERE id_empresa=:company LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':company' => $companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company || !TenantLifecyclePolicy::allows($company, TenantLifecyclePolicy::WRITE)) {
            throw new DomainException('La organización no está operativa para activar Restaurants.');
        }
    }

    /** @return array<string,mixed>|null */
    private function findByIdempotencyKey(string $key, bool $forUpdate): ?array
    {
        $sql = 'SELECT id_restaurant_account,id_empresa,status,request_fingerprint FROM restaurant_account WHERE idempotency_key=:key LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private function findAccountForCompany(int $companyId, bool $forUpdate): ?array
    {
        $sql = 'SELECT id_restaurant_account,id_empresa,status FROM restaurant_account WHERE id_empresa=:company LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':company' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function insertAccount(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO restaurant_account
             (id_empresa,idempotency_key,request_fingerprint,status,version)
             VALUES (:company,:idempotency,:fingerprint,'CONFIGURING',1)"
        );
        $stmt->execute([
            ':company' => $data['company_id'],
            ':idempotency' => $data['idempotency_key'],
            ':fingerprint' => $data['request_fingerprint'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function insertBrand(int $accountId, array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO restaurant_brand
             (id_restaurant_account,id_empresa,name,slug,status,version)
             VALUES (:account,:company,:name,:slug,'ACTIVE',1)"
        );
        $stmt->execute([
            ':account' => $accountId,
            ':company' => $data['company_id'],
            ':name' => $data['brand_name'],
            ':slug' => self::slug($data['brand_name'], 'marca'),
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function insertLegalEntity(int $accountId, array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO restaurant_legal_entity
             (id_restaurant_account,id_empresa,name,code,status,version)
             VALUES (:account,:company,:name,:code,'ACTIVE',1)"
        );
        $stmt->execute([
            ':account' => $accountId,
            ':company' => $data['company_id'],
            ':name' => $data['legal_entity_name'],
            ':code' => self::slug($data['legal_entity_name'], 'entidad'),
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function insertLocation(int $accountId, int $brandId, int $legalEntityId, array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO restaurant_location
             (id_restaurant_account,id_empresa,id_restaurant_brand,id_restaurant_legal_entity,
              name,slug,timezone,status,version)
             VALUES (:account,:company,:brand,:legal,:name,:slug,:timezone,'ACTIVE',1)"
        );
        $stmt->execute([
            ':account' => $accountId,
            ':company' => $data['company_id'],
            ':brand' => $brandId,
            ':legal' => $legalEntityId,
            ':name' => $data['location_name'],
            ':slug' => self::slug($data['location_name'], 'local'),
            ':timezone' => $data['timezone'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array<string,int|string|bool> */
    private function resultForAccount(int $companyId, int $accountId): array
    {
        $row = $this->findScoped($companyId, $accountId);
        if ($row === null) {
            throw new RuntimeException('La foundation idempotente está incompleta.');
        }
        return [
            'duplicate' => false,
            'company_id' => (int) $row['company_id'],
            'account_id' => (int) $row['account_id'],
            'brand_id' => (int) $row['brand_id'],
            'legal_entity_id' => (int) $row['legal_entity_id'],
            'location_id' => (int) $row['location_id'],
            'status' => (string) $row['status'],
        ];
    }

    private static function slug(string $value, string $fallback): string
    {
        $slug = mb_strtolower($value);
        $slug = strtr($slug, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c']);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        return mb_substr($slug !== '' ? $slug : $fallback, 0, 80);
    }

    private function inject(string $step): void
    {
        if ($this->faultInjector !== null) {
            ($this->faultInjector)($step);
        }
    }

    private function auditFailure(int $companyId, Throwable $error): void
    {
        try {
            (new LogModel($companyId, $this->db))->registrarCambio(
                $this->actorId,
                'RESTAURANT_ORGANIZATION_PROVISION_FAILED',
                'Foundation de Restaurants rechazada sin altas parciales',
                null,
                'restaurant_account',
                null,
                null,
                null,
                null,
                'fallo',
                'RESTAURANT_FOUNDATION_ROLLBACK',
                ['error_type' => get_class($error)],
                'usuario',
                'SYSTEM',
                AuditPolicy::BEST_EFFORT
            );
        } catch (Throwable) {
            // La excepción original conserva prioridad y no se simula éxito.
        }
    }
}
