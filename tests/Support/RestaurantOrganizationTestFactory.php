<?php

require_once dirname(__DIR__, 2) . '/app/domains/restaurants/RestaurantOrganizationService.php';

final class RestaurantOrganizationTestFactory
{
    public static function actor(PDO $db): int
    {
        return (int) $db->query(
            "SELECT id_usuario FROM usuario
              WHERE rol='superadmin' AND activo=1 AND id_empresa IS NULL AND id_gimnasio IS NULL
              ORDER BY id_usuario LIMIT 1"
        )->fetchColumn();
    }

    public static function createCompany(PDO $db, string $suffix, bool $active = true): int
    {
        $safe = mb_strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $suffix));
        $safe = trim($safe, '-') ?: bin2hex(random_bytes(4));
        $nonce = substr(hash('sha256', $safe . microtime(true) . random_bytes(4)), 0, 10);
        $stmt = $db->prepare(
            "INSERT INTO empresa
             (nombre,nombre_comercial,slug,estado,onboarding_state,onboarding_updated_at)
             VALUES (:name,:commercial,:slug,:status,:lifecycle,NOW())"
        );
        $stmt->execute([
            ':name' => 'TEST Restaurants ' . $safe . ' ' . $nonce,
            ':commercial' => 'Restaurant Synthetic ' . $safe,
            ':slug' => 'test-restaurants-' . mb_substr($safe, 0, 40) . '-' . $nonce,
            ':status' => $active ? 'activa' : 'inactiva',
            ':lifecycle' => $active ? 'ACTIVE' : 'CANCELLED',
        ]);
        return (int) $db->lastInsertId();
    }

    public static function input(int $companyId, string $suffix, array $overrides = []): array
    {
        $hash = hash('sha256', $suffix . ':' . $companyId);
        $uuid = substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-4' . substr($hash, 13, 3)
            . '-8' . substr($hash, 17, 3) . '-' . substr($hash, 20, 12);
        return array_replace([
            'company_id' => $companyId,
            'idempotency_key' => $uuid,
            'brand_name' => 'Marca Sintética ' . $suffix,
            'legal_entity_name' => 'Entidad Sintética ' . $suffix,
            'location_name' => 'Local Sintético ' . $suffix,
            'timezone' => 'Europe/Madrid',
        ], $overrides);
    }

    public static function createTenantActor(PDO $db, int $companyId, string $role = 'direccion'): int
    {
        $suffix = substr(hash('sha256', $companyId . ':' . $role . ':' . microtime(true)), 0, 12);
        $stmt = $db->prepare(
            'INSERT INTO usuario
             (id_empresa,id_gimnasio,nombre,apellidos,dni,email,nombre_usuario,contrasena,rol,activo)
             VALUES (:company,NULL,:name,:surname,NULL,:email,:username,:password,:role,1)'
        );
        $stmt->execute([
            ':company' => $companyId,
            ':name' => 'Operador',
            ':surname' => 'Sintético',
            ':email' => 'restaurant-' . $suffix . '@test.invalid',
            ':username' => 'restaurant_' . $suffix,
            ':password' => password_hash('synthetic-only', PASSWORD_BCRYPT, ['cost' => 4]),
            ':role' => $role,
        ]);
        return (int) $db->lastInsertId();
    }

    /** @param list<int> $companyIds */
    public static function cleanup(PDO $db, array $companyIds): void
    {
        foreach (array_reverse(array_values(array_unique(array_map('intval', $companyIds)))) as $companyId) {
            if ($companyId <= 0) {
                continue;
            }
            foreach ([
                'restaurant_location', 'restaurant_legal_entity',
                'restaurant_brand', 'restaurant_account',
            ] as $table) {
                $stmt = $db->prepare("DELETE FROM {$table} WHERE id_empresa=:company");
                $stmt->execute([':company' => $companyId]);
            }
            $stmt = $db->prepare('DELETE FROM log_actividad WHERE id_empresa=:company');
            $stmt->execute([':company' => $companyId]);
            $stmt = $db->prepare('DELETE FROM usuario WHERE id_empresa=:company');
            $stmt->execute([':company' => $companyId]);
            $stmt = $db->prepare("DELETE FROM empresa WHERE id_empresa=:company AND nombre LIKE 'TEST Restaurants %'");
            $stmt->execute([':company' => $companyId]);
        }
    }
}
