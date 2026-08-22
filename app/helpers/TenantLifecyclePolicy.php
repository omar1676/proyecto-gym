<?php

/**
 * Política única del ciclo de vida de una empresa.
 *
 * Las lecturas de soporte/auditoría se autorizan en su capa correspondiente;
 * este componente impide que una escritura de negocio alcance un tenant que
 * no está operativo. El lock es por empresa, por lo que no serializa tenants
 * distintos.
 */
final class TenantLifecyclePolicy
{
    public const READ = 'READ';
    public const WRITE = 'WRITE';
    public const LOGIN = 'LOGIN';
    public const ACTIVATION = 'ACTIVATION';
    public const SYSTEM_TASK = 'SYSTEM_TASK';

    /** @return array<string,array<string,bool>> */
    public static function matrix(): array
    {
        return [
            'CONFIGURING' => [self::READ => true, self::WRITE => false, self::LOGIN => false, self::ACTIVATION => false, self::SYSTEM_TASK => true],
            'READY_FOR_REVIEW' => [self::READ => true, self::WRITE => false, self::LOGIN => false, self::ACTIVATION => true, self::SYSTEM_TASK => true],
            'ACTIVE' => [self::READ => true, self::WRITE => true, self::LOGIN => true, self::ACTIVATION => true, self::SYSTEM_TASK => true],
            'CANCELLED' => [self::READ => true, self::WRITE => false, self::LOGIN => false, self::ACTIVATION => false, self::SYSTEM_TASK => true],
        ];
    }

    public static function acquireBusinessWrite(PDO $db, ?int $companyId): TenantLifecycleLease
    {
        if ($companyId === null || $companyId <= 0) {
            throw new DomainException('La escritura de negocio requiere una empresa válida.');
        }

        $lease = self::acquireLifecycleLock($db, $companyId);
        try {
            $company = self::companyState($db, $companyId);
            $allowed = $company !== null
                && $company['estado'] === 'activa'
                && $company['onboarding_state'] === 'ACTIVE';
            if (!$allowed) {
                throw new DomainException('La empresa no está operativa para realizar escrituras de negocio.');
            }
            return $lease;
        } catch (Throwable $error) {
            $lease->release();
            throw $error;
        }
    }

    /**
     * Lock reservado para transiciones PLATFORM. La autorización del actor se
     * comprueba antes en TenantProvisioningService; no es un bypass de negocio.
     */
    public static function acquirePlatformTransition(PDO $db, int $companyId): TenantLifecycleLease
    {
        if ($companyId <= 0) throw new InvalidArgumentException('Empresa no válida.');
        return self::acquireLifecycleLock($db, $companyId);
    }

    /** @return array{estado:string,onboarding_state:string}|null */
    public static function companyState(PDO $db, int $companyId): ?array
    {
        $stmt = $db->prepare(
            'SELECT estado,onboarding_state FROM empresa WHERE id_empresa=:company LIMIT 1'
        );
        $stmt->execute([':company' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function allows(array $company, string $capability): bool
    {
        $state = (string) ($company['onboarding_state'] ?? '');
        $businessActive = ($company['estado'] ?? '') === 'activa';
        $allowed = self::matrix()[$state][$capability] ?? false;
        if (in_array($capability, [self::WRITE, self::LOGIN], true)) {
            return $allowed && $businessActive;
        }
        return $allowed;
    }

    private static function acquireLifecycleLock(PDO $db, int $companyId): TenantLifecycleLease
    {
        $name = 'gimnera:tenant:' . $companyId . ':lifecycle';
        $stmt = $db->prepare('SELECT GET_LOCK(:name, 10)');
        $stmt->execute([':name' => $name]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('No se pudo bloquear el ciclo de vida de la empresa.');
        }
        return new TenantLifecycleLease($db, $name);
    }
}

final class TenantLifecycleLease
{
    private bool $active = true;

    public function __construct(private PDO $db, private string $name)
    {
    }

    public function release(): void
    {
        if (!$this->active) return;
        $this->active = false;
        try {
            $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute([':name' => $this->name]);
        } catch (Throwable) {
            // La conexión libera el advisory lock al cerrarse. Nunca ocultamos
            // la excepción principal por un fallo secundario de RELEASE_LOCK.
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
