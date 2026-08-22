<?php

require_once dirname(__DIR__) . '/helpers/InputValidator.php';
require_once dirname(__DIR__) . '/helpers/AuditPolicy.php';
require_once dirname(__DIR__) . '/models/LogModel.php';

/** Bootstrap de un solo uso para la primera identidad humana de plataforma. */
final class PlatformAdminBootstrapService
{
    private const LOCK_NAME = 'gimnera:platform-admin-bootstrap';

    public function __construct(private PDO $db)
    {
    }

    /** @return array{created:bool,user_id:int} */
    public function bootstrap(array $input): array
    {
        $data = $this->validate($input);
        $lock = (int) $this->db->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 10)")->fetchColumn();
        if ($lock !== 1) throw new RuntimeException('No se pudo adquirir el bloqueo de bootstrap.');

        try {
            $this->db->beginTransaction();
            $existing = (int) $this->db->query(
                "SELECT COUNT(*) FROM usuario WHERE rol='superadmin' AND id_empresa IS NULL"
            )->fetchColumn();
            if ($existing !== 0) throw new DomainException('La plataforma ya tiene una identidad superadmin.');

            $stmt = $this->db->prepare(
                "INSERT INTO usuario
                 (id_empresa,id_gimnasio,nombre,apellidos,dni,telefono,email,nombre_usuario,contrasena,rol,activo)
                 VALUES (NULL,NULL,:name,:surname,NULL,NULL,:email,:username,:password,'superadmin',1)"
            );
            $stmt->execute([
                ':name' => $data['name'], ':surname' => $data['surname'],
                ':email' => $data['email'], ':username' => $data['username'],
                ':password' => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => APP_ENV === 'test' ? 4 : 12]),
            ]);
            $userId = (int) $this->db->lastInsertId();
            (new LogModel(null, $this->db))->registrarCambio(
                $userId, 'PLATFORM_ADMIN_BOOTSTRAPPED', 'Primera identidad nominal de plataforma',
                $userId, 'usuario', $userId, null, 'active', null, 'exito',
                'PLATFORM_BOOTSTRAP', [], 'usuario', 'SYSTEM', AuditPolicy::REQUIRED
            );
            $this->db->commit();
            return ['created' => true, 'user_id' => $userId];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    /** @return array{name:string,surname:string,email:string,username:string,password:string} */
    private function validate(array $input): array
    {
        $name = InputValidator::text($input['name'] ?? '', 100);
        $surname = InputValidator::text($input['surname'] ?? '', 150);
        $email = InputValidator::email($input['email'] ?? '');
        $username = mb_strtolower((string) InputValidator::text($input['username'] ?? '', 60));
        $password = (string) ($input['password'] ?? '');
        if (!$name || !$surname || !$email || !preg_match('/^[a-z0-9][a-z0-9._-]{2,59}$/', $username)) {
            throw new InvalidArgumentException('La identidad de plataforma no es válida.');
        }
        if (strlen($password) < 16 || !preg_match('/[A-Z]/', $password)
            || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)
            || !preg_match('/[^A-Za-z0-9]/', $password)) {
            throw new InvalidArgumentException('La contraseña temporal no cumple el mínimo de seguridad.');
        }
        return compact('name', 'surname', 'email', 'username', 'password');
    }
}
