<?php

require_once dirname(__DIR__, 2) . '/app/services/MigrationStorage.php';

final class MigrationTestFactory
{
    public static function tenant(PDO $db, string $suffix): array
    {
        $suffix = preg_replace('/[^a-z0-9]/i', '', $suffix) ?: bin2hex(random_bytes(3));
        $stmt = $db->prepare("INSERT INTO empresa (nombre,nombre_comercial,slug,email,estado) VALUES (:n,:c,:s,:e,'activa')");
        $stmt->execute([':n'=>'Empresa Migración '.$suffix,':c'=>'Migración '.$suffix,':s'=>'migration-'.strtolower($suffix),':e'=>'empresa.'.$suffix.'@example.invalid']);
        $company = (int)$db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO gimnasio (id_empresa,nombre,slug,email_acceso,activo) VALUES (:e,:n,:s,:m,1)");
        $stmt->execute([':e'=>$company,':n'=>'Sede '.$suffix,':s'=>'mig-'.strtolower($suffix),':m'=>'sede.'.$suffix.'@example.invalid']);
        $site = (int)$db->lastInsertId();
        $stmt = $db->prepare(
            "INSERT INTO usuario (id_empresa,id_gimnasio,nombre,apellidos,dni,email,nombre_usuario,contrasena,rol,activo)
             VALUES (:e,NULL,'Dirección','Pruebas',:d,:m,:u,:p,'direccion',1)"
        );
        $numeric = (int)(hexdec(substr(hash('sha256',$suffix),0,7)) % 90000000) + 10000000;
        $stmt->execute([':e'=>$company,':d'=>'MIG'.$numeric,':m'=>'direccion.'.$suffix.'@example.invalid',
            ':u'=>'dir_'.strtolower($suffix),':p'=>password_hash('test-password',PASSWORD_BCRYPT)]);
        return ['company'=>$company,'site'=>$site,'user'=>(int)$db->lastInsertId()];
    }

    public static function service(PDO $db, array $tenant): MigrationService
    {
        return new MigrationService(
            $tenant['company'], $tenant['site'], $tenant['user'], $db, null,
            static fn() => ['reference'=>'test://verified-backup','verified_at'=>date('Y-m-d H:i:s')]
        );
    }

    public static function fixture(string $name): string
    {
        return dirname(__DIR__, 2) . '/pruebas/fixtures/importaciones/' . basename($name);
    }

    public static function cleanup(PDO $db, array $tenant): void
    {
        $stmt = $db->prepare('SELECT storage_key FROM migration_batch WHERE id_empresa=:e AND storage_key IS NOT NULL');
        $stmt->execute([':e'=>$tenant['company']]);
        $storage = new MigrationStorage();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) $storage->delete($key);
        $db->prepare('DELETE FROM migration_entity_map WHERE id_empresa=:e')->execute([':e'=>$tenant['company']]);
        $db->prepare('DELETE FROM migration_batch WHERE id_empresa=:e')->execute([':e'=>$tenant['company']]);
        $db->prepare('DELETE FROM log_actividad WHERE id_empresa=:e')->execute([':e'=>$tenant['company']]);
        $db->prepare('DELETE FROM producto WHERE id_gimnasio=:s')->execute([':s'=>$tenant['site']]);
        $db->prepare('DELETE FROM tipo_membresia WHERE id_empresa=:e')->execute([':e'=>$tenant['company']]);
        $db->prepare('DELETE FROM categoria_producto WHERE id_empresa=:e')->execute([':e'=>$tenant['company']]);
        $db->prepare('DELETE FROM usuario WHERE id_empresa=:e')->execute([':e'=>$tenant['company']]);
        $db->prepare('DELETE FROM gimnasio WHERE id_empresa=:e')->execute([':e'=>$tenant['company']]);
        $db->prepare('DELETE FROM empresa WHERE id_empresa=:e')->execute([':e'=>$tenant['company']]);
    }
}
