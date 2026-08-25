<?php

final class AccessControlTestFactory
{
    public static function cleanup(PDO $db): void
    {
        $ids = $db->query("SELECT id_empresa FROM empresa WHERE nombre LIKE 'TEST ACCESS %'")->fetchAll(PDO::FETCH_COLUMN);
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return;
        $in = implode(',', $ids);
        $db->exec("DELETE FROM access_policy_event WHERE id_empresa IN ({$in})");
        $db->exec("DELETE FROM access_policy WHERE id_empresa IN ({$in})");
        $db->exec("DELETE FROM access_control_audit WHERE id_empresa IN ({$in})");
        $db->exec("DELETE FROM access_sync_job WHERE id_empresa IN ({$in})");
        $db->exec("DELETE FROM access_identity_map WHERE id_empresa IN ({$in})");
        $db->exec("DELETE FROM log_actividad WHERE id_empresa IN ({$in})");
        $db->exec("DELETE FROM usuario WHERE id_empresa IN ({$in})");
        $db->exec("DELETE FROM gimnasio WHERE id_empresa IN ({$in})");
        $db->exec("DELETE FROM empresa WHERE id_empresa IN ({$in})");
    }

    public static function createTenant(PDO $db, string $code): array
    {
        $safe = strtolower(preg_replace('/[^a-z0-9]/i', '', $code));
        $stmt = $db->prepare('INSERT INTO empresa (nombre,nombre_comercial,slug,estado) VALUES (?,?,?,\'activa\')');
        $stmt->execute(['TEST ACCESS ' . strtoupper($safe), 'Test Access ' . strtoupper($safe), 'test-access-' . $safe]);
        $empresa = (int) $db->lastInsertId();
        $sede = self::createSite($db, $empresa, $safe . '1');
        $actor = self::createUser($db, $empresa, null, 'direccion', $safe . '_dir');
        $member = self::createUser($db, $empresa, $sede, 'socio', $safe . '_member');
        return ['empresa'=>$empresa, 'sede'=>$sede, 'actor'=>$actor, 'member'=>$member];
    }

    public static function createSite(PDO $db, int $empresaId, string $code): int
    {
        $safe = strtolower(preg_replace('/[^a-z0-9]/i', '', $code));
        $stmt = $db->prepare(
            'INSERT INTO gimnasio (id_empresa,nombre,slug,email_acceso,activo)
             VALUES (:empresa,:nombre,:slug,:email,1)'
        );
        $stmt->execute([
            ':empresa'=>$empresaId,
            ':nombre'=>'TEST ACCESS SITE ' . strtoupper($safe),
            ':slug'=>'test-access-' . $safe,
            ':email'=>'test-access-' . $safe . '@example.invalid',
        ]);
        return (int) $db->lastInsertId();
    }

    public static function createMember(PDO $db, int $empresaId, int $sedeId, string $code): int
    {
        return self::createUser($db, $empresaId, $sedeId, 'socio', $code);
    }

    public static function createActor(PDO $db, int $empresaId, ?int $sedeId, string $role, string $code): int
    {
        return self::createUser($db, $empresaId, $sedeId, $role, $code);
    }

    private static function createUser(PDO $db, int $empresaId, ?int $sedeId, string $role, string $code): int
    {
        $safe = strtolower(preg_replace('/[^a-z0-9_]/i', '', $code));
        $stmt = $db->prepare(
            'INSERT INTO usuario
             (nombre,apellidos,dni,telefono,email,nombre_usuario,contrasena,activo,rol,id_empresa,id_gimnasio)
             VALUES
             (:nombre,:apellidos,:dni,:telefono,:email,:usuario,:pass,1,:rol,:empresa,:sede)'
        );
        $stmt->execute([
            ':nombre'=>'Test', ':apellidos'=>'Access ' . strtoupper($safe),
            ':dni'=>'TACC-' . strtoupper($safe), ':telefono'=>'600000000',
            ':email'=>$safe . '@example.invalid', ':usuario'=>'test_access_' . $safe,
            ':pass'=>password_hash('synthetic-only', PASSWORD_DEFAULT), ':rol'=>$role,
            ':empresa'=>$empresaId, ':sede'=>$sedeId,
        ]);
        return (int) $db->lastInsertId();
    }
}
