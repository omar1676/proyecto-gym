<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/AuditPolicy.php';
require_once dirname(__DIR__) . '/helpers/InputValidator.php';
require_once dirname(__DIR__) . '/models/LogModel.php';
require_once dirname(__DIR__) . '/helpers/MigrationManager.php';
require_once dirname(__DIR__) . '/helpers/TenantLifecyclePolicy.php';

/** Aprovisionamiento oficial, atómico e idempotente de un tenant. */
final class TenantProvisioningService
{
    private PDO $db;
    private int $actorId;
    /** @var null|callable(string):void */
    private $faultInjector;

    public function __construct(PDO $db, int $actorId, ?callable $faultInjector = null)
    {
        if ($actorId <= 0) throw new InvalidArgumentException('Falta el operador de plataforma.');
        $this->db = $db;
        $this->actorId = $actorId;
        $this->faultInjector = $faultInjector;
        $this->assertSuperadmin();
    }

    /** @return array<string,mixed> */
    public function provision(array $input): array
    {
        $data = $this->validate($input);
        $existing = $this->findByKey($data['idempotency_key']);
        if ($existing) return $this->existingResult($existing);

        $companyPassword = $this->temporaryPassword();
        $ownerPassword = $this->temporaryPassword();
        $cost = APP_ENV === 'test' ? 4 : 12;

        try {
            $this->db->beginTransaction();
            $companyId = $this->insertCompany($data);
            $this->inject('company');
            $siteId = $this->insertFirstSite($companyId, $data, $companyPassword, $cost);
            $this->inject('site');
            $ownerId = $this->insertOwner($companyId, $data, $ownerPassword, $cost);
            $this->inject('owner');
            $this->insertCategories($companyId, $data['categories']);
            $this->insertMembershipTypes($companyId, $data['membership_types']);

            $audit = new LogModel($companyId, $this->db);
            $this->audit($audit, 'ONBOARDING_STARTED', 'empresa', $companyId, null, 'CONFIGURING');
            $this->audit($audit, 'TENANT_CREATED', 'empresa', $companyId, null, $data['commercial_name']);
            $this->audit($audit, 'SEDE_CREATED', 'gimnasio', $siteId, null, $data['site_name'], $siteId);
            $this->audit($audit, 'OWNER_CREATED', 'usuario', $ownerId, null, $data['owner_username']);

            $update = $this->db->prepare(
                "UPDATE empresa
                    SET onboarding_state='READY_FOR_REVIEW', onboarding_updated_at=NOW()
                  WHERE id_empresa=:id AND onboarding_state='CONFIGURING'"
            );
            $update->execute([':id' => $companyId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('No se pudo cerrar la configuración inicial.');
            $this->audit($audit, 'ONBOARDING_COMPLETED', 'empresa', $companyId, 'CONFIGURING', 'READY_FOR_REVIEW');
            $this->inject('complete');
            $this->db->commit();

            return [
                'created' => true,
                'company_id' => $companyId,
                'site_id' => $siteId,
                'owner_id' => $ownerId,
                'state' => 'READY_FOR_REVIEW',
                'site_access_email' => $data['site_access_email'],
                'site_temporary_password' => $companyPassword,
                'owner_username' => $data['owner_username'],
                'owner_temporary_password' => $ownerPassword,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $existing = $this->findByKey($data['idempotency_key']);
            if ($existing) return $this->existingResult($existing);
            $this->auditFailure($e);
            if ($e instanceof DomainException || $e instanceof InvalidArgumentException) throw $e;
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                throw new DomainException('Ya existe una empresa, sede o identidad con esos datos.');
            }
            throw new RuntimeException('No se pudo completar el onboarding; no se guardó ningún alta parcial.', 0, $e);
        }
    }

    /** @return array<string,mixed> */
    public function activate(int $companyId): array
    {
        if ($companyId <= 0) throw new InvalidArgumentException('Empresa no válida.');
        $this->assertMigrationsCurrent();
        $tenantLifecycle = TenantLifecyclePolicy::acquirePlatformTransition($this->db, $companyId);
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare('SELECT * FROM empresa WHERE id_empresa=:id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $companyId]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$company) throw new DomainException('La empresa no existe.');
            if ($company['onboarding_state'] === 'ACTIVE' && $company['estado'] === 'activa') {
                $this->db->commit();
                return ['activated' => false, 'already_active' => true, 'company_id' => $companyId];
            }
            if ($company['onboarding_state'] !== 'READY_FOR_REVIEW') {
                throw new DomainException('El onboarding no está preparado para activarse.');
            }
            $this->assertReady($companyId, $company);
            $update = $this->db->prepare(
                "UPDATE empresa SET estado='activa', onboarding_state='ACTIVE', onboarding_updated_at=NOW()
                  WHERE id_empresa=:id AND estado='inactiva' AND onboarding_state='READY_FOR_REVIEW'"
            );
            $update->execute([':id' => $companyId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('La activación perdió su estado previo.');
            $this->audit(
                new LogModel($companyId, $this->db),
                'ONBOARDING_ACTIVATED', 'empresa', $companyId, 'READY_FOR_REVIEW', 'ACTIVE'
            );
            $this->db->commit();
            return ['activated' => true, 'already_active' => false, 'company_id' => $companyId];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Transición interna de plataforma. No tiene ruta pública en F22.1; sirve
     * para que cancelación y escrituras usen exactamente el mismo lock.
     *
     * @return array{cancelled:bool,already_cancelled:bool,company_id:int}
     */
    public function cancel(int $companyId): array
    {
        if ($companyId <= 0) throw new InvalidArgumentException('Empresa no válida.');
        $this->assertMigrationsCurrent();
        $tenantLifecycle = TenantLifecyclePolicy::acquirePlatformTransition($this->db, $companyId);
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare('SELECT * FROM empresa WHERE id_empresa=:id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id' => $companyId]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$company) throw new DomainException('La empresa no existe.');
            if ($company['onboarding_state'] === 'CANCELLED' && $company['estado'] === 'inactiva') {
                $this->db->commit();
                return ['cancelled' => false, 'already_cancelled' => true, 'company_id' => $companyId];
            }
            if (!in_array($company['onboarding_state'], ['READY_FOR_REVIEW', 'ACTIVE'], true)) {
                throw new DomainException('La empresa no se encuentra en un estado cancelable.');
            }
            $update = $this->db->prepare(
                "UPDATE empresa SET estado='inactiva', onboarding_state='CANCELLED', onboarding_updated_at=NOW()
                  WHERE id_empresa=:id AND onboarding_state=:previous"
            );
            $update->execute([':id' => $companyId, ':previous' => $company['onboarding_state']]);
            if ($update->rowCount() !== 1) throw new RuntimeException('La cancelación perdió su estado previo.');
            $this->audit(
                new LogModel($companyId, $this->db),
                'TENANT_CANCELLED', 'empresa', $companyId, (string) $company['onboarding_state'], 'CANCELLED'
            );
            $this->db->commit();
            return ['cancelled' => true, 'already_cancelled' => false, 'company_id' => $companyId];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function listCompanies(): array
    {
        return $this->db->query(
            "SELECT e.id_empresa,e.nombre,e.nombre_comercial,e.slug,e.email,e.telefono,e.estado,
                    e.onboarding_state,e.fecha_alta,e.onboarding_updated_at,
                    (SELECT COUNT(*) FROM gimnasio g WHERE g.id_empresa=e.id_empresa) AS sites,
                    (SELECT COUNT(*) FROM usuario u WHERE u.id_empresa=e.id_empresa AND u.rol='direccion' AND u.activo=1) AS owners,
                    (SELECT COUNT(*) FROM tipo_membresia t WHERE t.id_empresa=e.id_empresa) AS membership_types,
                    (SELECT COUNT(*) FROM categoria_producto c WHERE c.id_empresa=e.id_empresa) AS categories
               FROM empresa e ORDER BY e.fecha_alta DESC,e.id_empresa DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    private function validate(array $input): array
    {
        $text = static fn(string $key, int $max, bool $required = true): ?string
            => InputValidator::text($input[$key] ?? '', $max, $required);
        $companyName = $text('company_name', 150);
        $commercial = $text('commercial_name', 150);
        $siteName = $text('site_name', 120);
        $ownerName = $text('owner_name', 100);
        $ownerSurname = $text('owner_surname', 150);
        $ownerUsername = mb_strtolower((string) $text('owner_username', 60));
        $companyEmail = InputValidator::email($input['company_email'] ?? '');
        $ownerEmail = InputValidator::email($input['owner_email'] ?? '');
        $siteAccessEmail = InputValidator::email($input['site_access_email'] ?? '');
        $phone = InputValidator::phone($input['phone'] ?? '');
        $idempotencyKey = mb_strtolower(trim((string) ($input['idempotency_key'] ?? '')));
        if (!$companyName || !$commercial || !$siteName || !$ownerName || !$ownerSurname || !$ownerUsername
            || !$companyEmail || !$ownerEmail || !$siteAccessEmail || !$phone) {
            throw new InvalidArgumentException('Faltan datos obligatorios o su formato no es válido.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,59}$/', $ownerUsername)) {
            throw new InvalidArgumentException('El usuario de dirección debe tener entre 3 y 60 caracteres seguros.');
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $idempotencyKey)) {
            throw new InvalidArgumentException('La solicitud de onboarding no es válida.');
        }
        $primary = trim((string) ($input['primary_color'] ?? '#4f46e5'));
        $textColor = trim((string) ($input['text_color'] ?? '#ffffff'));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary) || !preg_match('/^#[0-9a-fA-F]{6}$/', $textColor)) {
            throw new InvalidArgumentException('Los colores de marca no son válidos.');
        }
        $timezone = trim((string) ($input['timezone'] ?? 'Europe/Madrid'));
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('La zona horaria no es válida.');
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'EUR')));
        if ($currency !== 'EUR') {
            throw new InvalidArgumentException('Esta versión solo admite EUR; no se simula soporte multimoneda.');
        }
        $categories = $this->validateCategories($input['categories'] ?? []);
        $membershipTypes = $this->validateMembershipTypes($input['membership_types'] ?? []);
        return [
            'company_name' => $companyName,
            'commercial_name' => $commercial,
            'company_email' => $companyEmail,
            'phone' => $phone,
            'site_name' => $siteName,
            'site_access_email' => $siteAccessEmail,
            'owner_name' => $ownerName,
            'owner_surname' => $ownerSurname,
            'owner_email' => $ownerEmail,
            'owner_username' => $ownerUsername,
            'primary_color' => strtolower($primary),
            'text_color' => strtolower($textColor),
            'timezone' => $timezone,
            'currency' => $currency,
            'idempotency_key' => $idempotencyKey,
            'categories' => $categories,
            'membership_types' => $membershipTypes,
        ];
    }

    /** @return list<string> */
    private function validateCategories(mixed $input): array
    {
        if (is_string($input)) $input = preg_split('/\r?\n/', $input) ?: [];
        if (!is_array($input)) throw new InvalidArgumentException('Las categorías no son válidas.');
        $result = [];
        foreach ($input as $value) {
            $name = InputValidator::text($value, 100, false);
            if ($name === null) throw new InvalidArgumentException('Una categoría contiene caracteres no válidos.');
            if ($name === '') continue;
            $result[mb_strtolower($name)] = $name;
            if (count($result) > 50) throw new InvalidArgumentException('Demasiadas categorías iniciales.');
        }
        return array_values($result);
    }

    /** @return list<array<string,mixed>> */
    private function validateMembershipTypes(mixed $input): array
    {
        if ($input === null || $input === '') return [];
        if (!is_array($input)) throw new InvalidArgumentException('Las tarifas no son válidas.');
        $result = [];
        foreach ($input as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Una tarifa no es válida.');
            $name = InputValidator::text($row['name'] ?? '', 100);
            $description = InputValidator::text($row['description'] ?? '', 255, false);
            $price = InputValidator::money($row['price'] ?? null);
            $duration = filter_var($row['duration_months'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
            $vat = InputValidator::money($row['vat'] ?? '21.00', 10000);
            if (!$name || $description === null || $price === null || $duration === false || $vat === null) {
                throw new InvalidArgumentException('Una tarifa inicial contiene datos no válidos.');
            }
            $key = mb_strtolower($name);
            if (isset($result[$key])) throw new InvalidArgumentException('Hay tarifas iniciales duplicadas.');
            $result[$key] = ['name' => $name, 'description' => $description, 'price' => $price, 'duration' => (int) $duration, 'vat' => $vat];
            if (count($result) > 25) throw new InvalidArgumentException('Demasiadas tarifas iniciales.');
        }
        return array_values($result);
    }

    private function insertCompany(array $data): int
    {
        $configuration = [
            'onboarding' => ['version' => 1, 'import_mode' => 'NONE', 'import_status' => 'SKIPPED'],
            'locale' => 'es_ES',
            'timezone' => $data['timezone'],
            'currency' => $data['currency'],
            'email' => ['enabled' => false],
            'access_control' => ['mode' => 'disabled'],
        ];
        $stmt = $this->db->prepare(
            "INSERT INTO empresa
             (nombre,nombre_comercial,slug,onboarding_key,onboarding_state,email,telefono,
              color_primario,color_texto,configuracion,estado,onboarding_updated_at)
             VALUES (:name,:commercial,:slug,:key,'CONFIGURING',:email,:phone,:primary,:text,:config,'inactiva',NOW())"
        );
        $stmt->execute([
            ':name' => $data['company_name'], ':commercial' => $data['commercial_name'],
            ':slug' => $this->uniqueCompanySlug($data['commercial_name']), ':key' => $data['idempotency_key'],
            ':email' => $data['company_email'], ':phone' => $data['phone'], ':primary' => $data['primary_color'],
            ':text' => $data['text_color'], ':config' => json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function insertFirstSite(int $companyId, array $data, string $password, int $cost): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO gimnasio
             (id_empresa,nombre,slug,email_acceso,contrasena_acceso,email,telefono,color_primario,color_texto,activo)
             VALUES (:company,:name,:slug,:access_email,:password,:email,:phone,:primary,:text,1)"
        );
        $stmt->execute([
            ':company' => $companyId, ':name' => $data['site_name'],
            ':slug' => $this->uniqueSiteSlug($data['site_name']), ':access_email' => $data['site_access_email'],
            ':password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]),
            ':email' => $data['company_email'], ':phone' => $data['phone'],
            ':primary' => $data['primary_color'], ':text' => $data['text_color'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function insertOwner(int $companyId, array $data, string $password, int $cost): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO usuario
             (id_empresa,id_gimnasio,nombre,apellidos,dni,telefono,email,nombre_usuario,contrasena,rol,activo)
             VALUES (:company,NULL,:name,:surname,NULL,:phone,:email,:username,:password,'direccion',1)"
        );
        $stmt->execute([
            ':company' => $companyId, ':name' => $data['owner_name'], ':surname' => $data['owner_surname'],
            ':phone' => $data['phone'], ':email' => $data['owner_email'], ':username' => $data['owner_username'],
            ':password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]),
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function insertCategories(int $companyId, array $categories): void
    {
        $stmt = $this->db->prepare('INSERT INTO categoria_producto (id_empresa,nombre_categoria) VALUES (:company,:name)');
        foreach ($categories as $name) $stmt->execute([':company' => $companyId, ':name' => $name]);
    }

    private function insertMembershipTypes(int $companyId, array $types): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO tipo_membresia
             (id_empresa,id_gimnasio,nombre,descripcion,precio,iva,duracion_meses,estado)
             VALUES (:company,NULL,:name,:description,:price,:vat,:duration,'activo')"
        );
        foreach ($types as $row) {
            $stmt->execute([
                ':company' => $companyId, ':name' => $row['name'], ':description' => $row['description'] ?: null,
                ':price' => $row['price'], ':vat' => $row['vat'], ':duration' => $row['duration'],
            ]);
        }
    }

    private function assertReady(int $companyId, array $company): void
    {
        $config = json_decode((string) ($company['configuracion'] ?? ''), true);
        if (!is_array($config)
            || ($config['currency'] ?? null) !== 'EUR'
            || empty($config['timezone'])
            || ($config['access_control']['mode'] ?? null) !== 'disabled'
            || !isset($config['email']['enabled'])
            || ($config['email']['enabled'] ?? true) !== false) {
            throw new DomainException('La configuración mínima del tenant no está completa o no es segura.');
        }
        $site = $this->db->prepare(
            'SELECT COUNT(*) FROM gimnasio WHERE id_empresa=:company AND activo=1 AND email_acceso IS NOT NULL AND contrasena_acceso IS NOT NULL'
        );
        $site->execute([':company' => $companyId]);
        $owner = $this->db->prepare(
            "SELECT COUNT(*) FROM usuario WHERE id_empresa=:company AND rol='direccion' AND id_gimnasio IS NULL AND activo=1"
        );
        $owner->execute([':company' => $companyId]);
        if ((int) $site->fetchColumn() < 1 || (int) $owner->fetchColumn() < 1) {
            throw new DomainException('Falta una sede operativa o una dirección nominal.');
        }
    }

    private function assertMigrationsCurrent(): void
    {
        $status = (new MigrationManager($this->db))->status();
        if ($status['pending'] !== [] || $status['checksum_mismatch'] !== [] || ($status['structural_mismatch'] ?? []) !== []) {
            throw new RuntimeException('No se puede activar un tenant con migraciones pendientes o incoherentes.');
        }
    }

    private function assertSuperadmin(): void
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM usuario
              WHERE id_usuario=:id AND rol='superadmin' AND activo=1 AND id_empresa IS NULL
              LIMIT 1"
        );
        $stmt->execute([':id' => $this->actorId]);
        if (!$stmt->fetchColumn()) throw new DomainException('Solo un superadministrador puede aprovisionar tenants.');
    }

    private function findByKey(string $key): ?array
    {
        if ($key === '') return null;
        $stmt = $this->db->prepare('SELECT * FROM empresa WHERE onboarding_key=:key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function existingResult(array $company): array
    {
        $stmt = $this->db->prepare('SELECT id_gimnasio FROM gimnasio WHERE id_empresa=:company ORDER BY id_gimnasio LIMIT 1');
        $stmt->execute([':company' => (int) $company['id_empresa']]);
        $siteId = (int) $stmt->fetchColumn() ?: null;
        $stmt = $this->db->prepare("SELECT id_usuario,nombre_usuario FROM usuario WHERE id_empresa=:company AND rol='direccion' ORDER BY id_usuario LIMIT 1");
        $stmt->execute([':company' => (int) $company['id_empresa']]);
        $owner = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'created' => false, 'company_id' => (int) $company['id_empresa'], 'site_id' => $siteId,
            'owner_id' => (int) ($owner['id_usuario'] ?? 0) ?: null, 'owner_username' => $owner['nombre_usuario'] ?? null,
            'state' => $company['onboarding_state'], 'credentials_available' => false,
        ];
    }

    private function uniqueCompanySlug(string $name): string
    {
        return $this->uniqueSlug('empresa', 'slug', $name, 'gym');
    }

    private function uniqueSiteSlug(string $name): string
    {
        return $this->uniqueSlug('gimnasio', 'slug', $name, 'sede');
    }

    private function uniqueSlug(string $table, string $column, string $name, string $fallback): string
    {
        $slug = mb_strtolower($name);
        $slug = strtr($slug, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c']);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-') ?: $fallback;
        $slug = mb_substr($slug, 0, 60);
        $candidate = $slug;
        for ($i = 2; ; $i++) {
            $stmt = $this->db->prepare("SELECT 1 FROM {$table} WHERE {$column}=:slug LIMIT 1");
            $stmt->execute([':slug' => $candidate]);
            if (!$stmt->fetchColumn()) return $candidate;
            $candidate = mb_substr($slug, 0, 54) . '-' . $i;
        }
    }

    private function temporaryPassword(): string
    {
        do {
            $password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        } while (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password));
        return $password;
    }

    private function inject(string $step): void
    {
        if ($this->faultInjector !== null) ($this->faultInjector)($step);
    }

    private function audit(
        LogModel $audit,
        string $action,
        string $entity,
        int $entityId,
        ?string $before,
        ?string $after,
        ?int $siteId = null
    ): void {
        $audit->registrarCambio(
            $this->actorId, $action, 'Provisioning SaaS', null, $entity, $entityId,
            $before, $after, $siteId, 'exito', 'PROVISIONING', [], 'usuario', 'WEB', AuditPolicy::REQUIRED
        );
    }

    private function auditFailure(Throwable $error): void
    {
        try {
            (new LogModel(null, $this->db))->registrarCambio(
                $this->actorId, 'ONBOARDING_FAILED', 'Provisioning rechazado sin altas parciales',
                null, 'empresa', null, null, null, null, 'fallo', 'PROVISIONING_ROLLBACK',
                ['error_type' => get_class($error)], 'usuario', 'WEB'
            );
        } catch (Throwable) {
            // La excepción original conserva prioridad; el canal técnico del
            // LogModel ya registra la indisponibilidad de auditoría.
        }
    }
}
