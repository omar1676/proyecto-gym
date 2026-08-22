<?php
/**
 * UserModel — acceso a la tabla `usuario`.
 *
 * Secciones de este archivo (en orden):
 *   1. Constructor                     (__construct)
 *   2. Alta de usuarios                (crear, crearEmpleado)
 *   3. Búsqueda                        (buscarPorId, buscarPorUsuario, buscarPorCorreo, buscarPorTokenReset)
 *   4. Listado y conteo                (listarPorRol, contarPorRol)
 *   5. Validaciones de unicidad        (usuarioExiste, correoExiste, dniExiste, correoExisteOtroUsuario)
 *   6. Estado activo/inactivo          (getActivo, toggleActivo)
 *   7. Edición de perfil               (actualizarPerfil, cambiarContrasena, actualizarFoto)
 *   8. Tokens de reset de contraseña   (guardarTokenReset, limpiarTokenReset)
 *   9. Eliminación de usuario          (eliminarUsuario)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/PrivatePhotoStorage.php';
require_once __DIR__ . '/../helpers/TenantLifecyclePolicy.php';
require_once __DIR__ . '/../helpers/SafeException.php';

class UserModel {
    private $db;
    private $idGimnasio;
    private $idEmpresa;

    /**
     * $idGimnasio limita los listados y altas a una sede (null = todas, solo
     * para el rol empresa).
     *
     * El primer nivel de login ya identifica la empresa de la sede. Por eso
     * usuario y correo se filtran también en el segundo nivel: dos clientes
     * pueden tener una persona con el mismo identificador sin mezclarse.
     */
    public function __construct(?int $idGimnasio = null, ?int $idEmpresa = null) {
        $this->db = Database::getInstance()->getConnection();
        $this->idGimnasio = $idGimnasio;
        $this->idEmpresa = $idEmpresa;
        if ($this->idEmpresa === null && $this->idGimnasio !== null) {
            $stmt = $this->db->prepare('SELECT id_empresa FROM gimnasio WHERE id_gimnasio = :id');
            $stmt->execute([':id' => $this->idGimnasio]);
            $this->idEmpresa = (int) $stmt->fetchColumn() ?: null;
        }
    }

    private function filtroSede(string $alias = ''): string {
        $prefijo = $alias === '' ? '' : $alias . '.';
        if ($this->idGimnasio !== null) return ' AND ' . $prefijo . 'id_gimnasio = ' . (int) $this->idGimnasio;
        if ($this->idEmpresa !== null) return ' AND ' . $prefijo . 'id_empresa = ' . (int) $this->idEmpresa;
        return '';
    }

    /**
     * Las identidades humanas son únicas por empresa, no por sede. Un modelo
     * limitado a una sede conserva ese límite para leer recursos, pero debe
     * comprobar duplicados contra todas las sedes de su empresa.
     */
    private function filtroIdentidadEmpresa(string $alias = ''): string {
        $prefijo = $alias === '' ? '' : $alias . '.';
        if ($this->idEmpresa !== null) {
            return ' AND ' . $prefijo . 'id_empresa = ' . (int) $this->idEmpresa;
        }
        return '';
    }

    private function acquireBusinessWrite(): ?TenantLifecycleLease {
        if ($this->idEmpresa === null) return null; // identidad global de plataforma
        return TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->idEmpresa);
    }

    /**
     * Alta de socio. La sede sale del propio modelo, así que un socio siempre
     * nace en el gimnasio desde el que se le da de alta.
     */
    public function crear(
        string $nombre,
        string $apellidos,
        string $dni,
        ?string $telefono,
        string $correo,
        string $usuario,
        string $contrasena,
        ?string $iban = null,
        ?string $foto = null
    ): bool {
        $tenantLifecycle = $this->acquireBusinessWrite();
        $hash = password_hash($contrasena, PASSWORD_BCRYPT, ['cost' => 12]);
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO usuario
                 (id_empresa, nombre, apellidos, dni, telefono, iban, email, nombre_usuario, contrasena, foto, id_gimnasio)
                 VALUES
                 (:id_empresa, :nombre, :apellidos, :dni, :telefono, :iban, :email, :usuario, :contrasena, :foto, :id_gimnasio)'
            );
            return $stmt->execute([
                ':id_empresa'  => $this->idEmpresa,
                ':nombre'      => $nombre,
                ':apellidos'   => $apellidos,
                ':dni'         => $dni,
                ':telefono'    => $telefono,
                ':iban'        => $iban,
                ':email'       => $correo,
                ':usuario'     => $usuario,
                ':contrasena'  => $hash,
                ':foto'        => $foto,
                ':id_gimnasio' => $this->idGimnasio,
            ]);
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.crear');
            return false;
        }
    }

    public function buscarPorUsuario(string $usuario) {
        $stmt = $this->db->prepare(
            'SELECT * FROM usuario WHERE nombre_usuario = :nombre_usuario' . $this->filtroSede() . ' LIMIT 1'
        );
        $stmt->execute([':nombre_usuario' => $usuario]);
        return $stmt->fetch();
    }

    /** Cuenta interna global; solo se usa como fallback explícito del login. */
    public function buscarSuperadminPorUsuario(string $usuario): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM usuario WHERE nombre_usuario=:usuario AND rol='superadmin' AND id_empresa IS NULL LIMIT 1"
        );
        $stmt->execute([':usuario' => $usuario]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Cuenta interna global por correo; solo para recuperación de acceso. */
    public function buscarSuperadminPorCorreo(string $correo): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM usuario WHERE email=:email AND rol='superadmin' AND id_empresa IS NULL LIMIT 1"
        );
        $stmt->execute([':email' => $correo]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function buscarPorCorreo(string $correo) {
        $stmt = $this->db->prepare('SELECT * FROM usuario WHERE email = :email' . $this->filtroSede() . ' LIMIT 1');
        $stmt->execute([':email' => $correo]);
        return $stmt->fetch();
    }

    /**
     * Búsqueda por id CON filtro de sede.
     *
     * Es la comprobación de permisos de casi todas las acciones del panel
     * ("¿existe este socio/empleado?"), así que tiene que ser también la que
     * decide "¿es de mi gimnasio?". Sin el filtro, un id tecleado a mano en la
     * petición alcanzaba a gente de otra sede.
     *
     * El login y el perfil usan un UserModel sin sede (null), donde el filtro
     * no se aplica: ahí todavía no se sabe de qué gimnasio es nadie.
     */
    public function buscarPorId(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT * FROM usuario WHERE id_usuario = :id' . $this->filtroSede() . ' LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function buscarPorTokenReset(string $token): ?array {
        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM usuario
                 WHERE reset_token = :token
                   AND reset_expira IS NOT NULL
                   AND reset_expira > NOW()
                   AND activo = 1
                 LIMIT 1'
            );
            $stmt->execute([':token' => hash('sha256', $token)]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.buscarPorTokenReset');
            return null;
        }
    }

    public function listarPorRol(string $rol): array {
        $stmt = $this->db->prepare(
            'SELECT id_usuario, nombre, apellidos, dni, email, nombre_usuario, foto, activo, id_gimnasio
             FROM usuario
             WHERE rol = :rol' . $this->filtroSede() . '
             ORDER BY apellidos ASC, nombre ASC'
        );
        $stmt->execute([':rol' => $rol]);
        return $stmt->fetchAll();
    }

    public function contarPorRol(string $rol): int {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM usuario WHERE rol = :rol' . $this->filtroSede());
        $stmt->execute([':rol' => $rol]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Edición de los datos de contacto de un socio desde el panel.
     *
     * No toca contraseña, rol ni gimnasio: para eso hacen falta acciones
     * propias con sus permisos. El filtro de sede impide editar a alguien de
     * otro gimnasio aunque llegue un id manipulado.
     */
    public function actualizarDatosSocio(
        int $id,
        string $nombre,
        string $apellidos,
        ?string $telefono,
        string $correo,
        ?string $iban
    ): bool {
        $tenantLifecycle = $this->acquireBusinessWrite();
        try {
            $stmt = $this->db->prepare(
                'UPDATE usuario SET
                    nombre    = :nombre,
                    apellidos = :apellidos,
                    telefono  = :telefono,
                    email     = :email,
                    iban      = :iban
                 WHERE id_usuario = :id' . $this->filtroSede()
            );
            return $stmt->execute([
                ':nombre'    => $nombre,
                ':apellidos' => $apellidos,
                ':telefono'  => $telefono,
                ':email'     => $correo,
                ':iban'      => $iban,
                ':id'        => $id,
            ]);
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.actualizarDatosSocio');
            return false;
        }
    }

    public function actualizarIban(int $id, ?string $iban): bool {
        $tenantLifecycle = $this->acquireBusinessWrite();
        try {
            $stmt = $this->db->prepare(
                'UPDATE usuario SET iban = :iban WHERE id_usuario = :id' . $this->filtroSede()
            );
            return $stmt->execute([':iban' => $iban, ':id' => $id]);
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.actualizarIban');
            return false;
        }
    }

    /* --- Empleados (admin y recepción) ------------------------------------ */

    /**
     * Alta de personal. El rol y la sede llegan explícitos porque el que da de
     * alta no siempre trabaja donde el nuevo empleado: la empresa puede
     * crear personal de cualquier sede.
     */
    public function crearEmpleado(
        string $nombre,
        string $apellidos,
        string $dni,
        string $email,
        ?string $telefono,
        string $usuario,
        string $contrasena,
        string $rol,
        ?int $idGimnasio
    ): ?int {
        $tenantLifecycle = $this->acquireBusinessWrite();
        if (!in_array($rol, ['admin', 'recepcion'], true)) {
            return null;
        }
        $hash = password_hash($contrasena, PASSWORD_BCRYPT, ['cost' => 12]);
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO usuario
                 (id_empresa, nombre, apellidos, dni, telefono, email, nombre_usuario, contrasena, rol, id_gimnasio)
                 VALUES
                 (:id_empresa, :nombre, :apellidos, :dni, :telefono, :email, :usuario, :contrasena, :rol, :id_gimnasio)'
            );
            $stmt->execute([
                ':id_empresa'  => $this->idEmpresa,
                ':nombre'      => $nombre,
                ':apellidos'   => $apellidos,
                ':dni'         => $dni,
                ':telefono'    => $telefono,
                ':email'       => $email,
                ':usuario'     => $usuario,
                ':contrasena'  => $hash,
                ':rol'         => $rol,
                ':id_gimnasio' => $idGimnasio,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.crearEmpleado');
            return null;
        }
    }

    /** Personal de gestión, con el nombre de su sede. */
    public function listarEmpleados(string $busqueda = ''): array {
        $sql =
            "SELECT u.id_usuario, u.nombre, u.apellidos, u.dni, u.email, u.telefono,
                    u.nombre_usuario, u.rol, u.activo, u.foto, u.id_gimnasio, u.created_at,
                    g.nombre AS gimnasio_nombre
             FROM usuario u
             LEFT JOIN gimnasio g ON g.id_gimnasio = u.id_gimnasio
             WHERE u.rol IN ('superadmin','direccion','admin','recepcion')" . $this->filtroSede('u');
        $params = [];

        if ($busqueda !== '') {
            $sql .= " AND (u.nombre LIKE :b1 OR u.apellidos LIKE :b2
                           OR u.email LIKE :b3 OR u.nombre_usuario LIKE :b4)";
            $b = '%' . $busqueda . '%';
            $params = [':b1' => $b, ':b2' => $b, ':b3' => $b, ':b4' => $b];
        }
        $sql .= " ORDER BY FIELD(u.rol,'superadmin','direccion','admin','recepcion'), u.apellidos ASC";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.listarEmpleados');
            return [];
        }
    }

    /**
     * Edita los datos de un empleado, incluidos rol y sede.
     * Quién puede cambiar qué se decide en el controlador, no aquí.
     */
    public function actualizarEmpleado(
        int $id,
        string $nombre,
        string $apellidos,
        string $email,
        ?string $telefono,
        string $rol,
        ?int $idGimnasio
    ): bool {
        $tenantLifecycle = $this->acquireBusinessWrite();
        if (!in_array($rol, ['direccion', 'admin', 'recepcion'], true)) {
            return false;
        }
        try {
            $stmt = $this->db->prepare(
                'UPDATE usuario SET
                    sesiones_desde = CASE
                        WHEN rol <> :rol_check OR NOT (id_gimnasio <=> :id_gimnasio_check)
                            THEN DATE_ADD(NOW(), INTERVAL 1 SECOND)
                        ELSE sesiones_desde
                    END,
                    nombre      = :nombre,
                    apellidos   = :apellidos,
                    email       = :email,
                    telefono    = :telefono,
                    rol         = :rol,
                    id_empresa  = :id_empresa,
                    id_gimnasio = :id_gimnasio
                 WHERE id_usuario = :id
                   AND rol IN (\'direccion\',\'admin\',\'recepcion\')' . $this->filtroSede()
            );
            return $stmt->execute([
                ':nombre'      => $nombre,
                ':apellidos'   => $apellidos,
                ':email'       => $email,
                ':telefono'    => $telefono,
                ':rol_check'   => $rol,
                ':id_gimnasio_check' => $rol === 'direccion' ? null : $idGimnasio,
                ':rol'         => $rol,
                ':id_empresa'  => $this->idEmpresa,
                ':id_gimnasio' => $rol === 'direccion' ? null : $idGimnasio,
                ':id'          => $id,
            ]);
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.actualizarEmpleado');
            return false;
        }
    }

    /** Cuántas personas quedan con permisos de gestión, para no quedarse sin ninguna. */
    public function contarGestoresActivos(int $excluirId = 0): int {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM usuario
                 WHERE rol IN ('superadmin','direccion','admin') AND activo = 1 AND id_usuario <> :id" . $this->filtroSede()
            );
            $stmt->execute([':id' => $excluirId]);
            return (int) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.contarGestoresActivos');
            return 0;
        }
    }

    public function usuarioExiste(string $usuario): bool {
        $stmt = $this->db->prepare('SELECT id_usuario FROM usuario WHERE nombre_usuario = :u' . $this->filtroIdentidadEmpresa() . ' LIMIT 1');
        $stmt->execute([':u' => $usuario]);
        return (bool) $stmt->fetch();
    }

    public function correoExiste(string $correo): bool {
        $stmt = $this->db->prepare('SELECT id_usuario FROM usuario WHERE email = :e' . $this->filtroIdentidadEmpresa() . ' LIMIT 1');
        $stmt->execute([':e' => $correo]);
        return (bool) $stmt->fetch();
    }

    public function dniExiste(string $dni): bool {
        $stmt = $this->db->prepare('SELECT id_usuario FROM usuario WHERE dni = :d' . $this->filtroIdentidadEmpresa() . ' LIMIT 1');
        $stmt->execute([':d' => $dni]);
        return (bool) $stmt->fetch();
    }

    public function correoExisteOtroUsuario(string $correo, int $idUsuario): bool {
        $stmt = $this->db->prepare(
            'SELECT id_usuario FROM usuario WHERE email = :email AND id_usuario <> :id' . $this->filtroIdentidadEmpresa() . ' LIMIT 1'
        );
        $stmt->execute([':email' => $correo, ':id' => $idUsuario]);
        return (bool) $stmt->fetch();
    }

    /** Unicidad de las cuentas internas de plataforma (id_empresa NULL). */
    public function correoExisteOtroUsuarioPlataforma(string $correo, int $idUsuario): bool {
        $stmt = $this->db->prepare(
            'SELECT id_usuario FROM usuario
              WHERE email = :email AND id_usuario <> :id AND id_empresa IS NULL
              LIMIT 1'
        );
        $stmt->execute([':email' => $correo, ':id' => $idUsuario]);
        return (bool) $stmt->fetch();
    }

    /** Devuelve false si el usuario no es de esta sede y no se ha tocado nada. */
    public function toggleActivo(int $id): bool {
        $tenantLifecycle = $this->acquireBusinessWrite();
        $stmt = $this->db->prepare(
            'UPDATE usuario SET
                sesiones_desde = CASE
                    WHEN activo = 1 THEN DATE_ADD(NOW(), INTERVAL 1 SECOND)
                    ELSE DATE_SUB(NOW(), INTERVAL 1 SECOND)
                END,
                activo = NOT activo
             WHERE id_usuario = :id' . $this->filtroSede()
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function getActivo(int $id): bool {
        $stmt = $this->db->prepare('SELECT activo FROM usuario WHERE id_usuario = :id');
        $stmt->execute([':id' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    /** Datos que cada persona puede cambiar de sí misma desde "Mi perfil". */
    public function actualizarPerfil(
        int $id,
        string $nombre,
        string $apellidos,
        ?string $telefono,
        string $correo,
        ?string $foto = null
    ): bool {
        $tenantLifecycle = $this->acquireBusinessWrite();
        $sql = 'UPDATE usuario SET
                    nombre    = :nombre,
                    apellidos = :apellidos,
                    telefono  = :telefono,
                    email     = :email';
        $params = [
            ':nombre'    => $nombre,
            ':apellidos' => $apellidos,
            ':telefono'  => $telefono,
            ':email'     => $correo,
            ':id'        => $id,
        ];
        if ($foto !== null) {
            $sql .= ', foto = :foto';
            $params[':foto'] = $foto;
        }
        $sql .= ' WHERE id_usuario = :id';
        try {
            return $this->db->prepare($sql)->execute($params);
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.actualizarPerfil');
            return false;
        }
    }

    /**
     * Cambia la contraseña y anula las sesiones abiertas.
     *
     * `sesiones_desde` marca el momento a partir del cual una sesión vale. Todo
     * lo abierto antes deja de servir en la siguiente petición, que es lo que
     * uno espera al cambiar la clave porque cree que alguien ha entrado.
     */
    public function cambiarContrasena(int $id, string $nuevaContrasena): bool {
        $tenantLifecycle = $this->acquireBusinessWrite();
        $hash = password_hash($nuevaContrasena, PASSWORD_BCRYPT, ['cost' => 12]);
        try {
            $stmt = $this->db->prepare(
                'UPDATE usuario SET contrasena = :hash, sesiones_desde = NOW() WHERE id_usuario = :id'
            );
            return $stmt->execute([':hash' => $hash, ':id' => $id]);
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.cambiarContrasena');
            return false;
        }
    }

    public function actualizarFoto(int $id, string $foto): bool {
        $tenantLifecycle = $this->acquireBusinessWrite();
        try {
            $stmt = $this->db->prepare('UPDATE usuario SET foto = :foto WHERE id_usuario = :id');
            return $stmt->execute([':foto' => $foto, ':id' => $id]);
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.actualizarFoto');
            return false;
        }
    }

    public function guardarTokenReset(int $idUsuario, string $token, string $expiraDateTime): bool {
        $tenantLifecycle = $this->acquireBusinessWrite();
        try {
            $stmt = $this->db->prepare(
                'UPDATE usuario SET reset_token = :token, reset_expira = :expira WHERE id_usuario = :id'
            );
            return $stmt->execute([
                ':token'  => hash('sha256', $token),
                ':expira' => $expiraDateTime,
                ':id'     => $idUsuario,
            ]);
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.guardarTokenReset');
            return false;
        }
    }

    public function limpiarTokenReset(int $idUsuario): void {
        $tenantLifecycle = $this->acquireBusinessWrite();
        try {
            $stmt = $this->db->prepare(
                'UPDATE usuario SET reset_token = NULL, reset_expira = NULL WHERE id_usuario = :id'
            );
            $stmt->execute([':id' => $idUsuario]);
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.limpiarTokenReset');
        }
    }

    /** Invalida solo el token que originó el intento; no pisa uno posterior. */
    public function invalidarTokenReset(int $idUsuario, string $token): bool
    {
        $tenantLifecycle = $this->acquireBusinessWrite();
        $stmt = $this->db->prepare(
            'UPDATE usuario
                SET reset_token = NULL, reset_expira = NULL
              WHERE id_usuario = :id AND reset_token = :token'
        );
        $stmt->execute([':id' => $idUsuario, ':token' => hash('sha256', $token)]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Consume un token y cambia la contraseña como una sola operación.
     *
     * El bloqueo de fila hace que dos POST simultáneos no puedan validar el
     * mismo token y escribir dos contraseñas diferentes. Solo el ganador
     * modifica la cuenta; el segundo observa el token ya consumido.
     *
     * @return array<string,mixed>|null usuario actualizado o null si el token
     *         ya no era válido al adquirir el lock.
     */
    public function consumirTokenReset(string $token, string $nuevaContrasena): ?array
    {
        $tenantLifecycle = $this->acquireBusinessWrite();
        if ($token === '') {
            return null;
        }
        $hashToken = hash('sha256', $token);
        $hashPassword = password_hash($nuevaContrasena, PASSWORD_BCRYPT, ['cost' => 12]);
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $select = $this->db->prepare(
                'SELECT * FROM usuario
                  WHERE reset_token = :token
                    AND reset_expira IS NOT NULL
                    AND reset_expira > NOW()
                    AND activo = 1
                  LIMIT 1
                  FOR UPDATE'
            );
            $select->execute([':token' => $hashToken]);
            $user = $select->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                if ($ownTransaction) {
                    $this->db->rollBack();
                }
                return null;
            }

            $update = $this->db->prepare(
                'UPDATE usuario
                    SET contrasena = :password,
                        sesiones_desde = NOW(),
                        reset_token = NULL,
                        reset_expira = NULL
                  WHERE id_usuario = :id
                    AND reset_token = :token
                    AND reset_expira > NOW()
                    AND activo = 1'
            );
            $update->execute([
                ':password' => $hashPassword,
                ':id' => (int) $user['id_usuario'],
                ':token' => $hashToken,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('El token dejó de ser válido durante el consumo.');
            }
            if ($ownTransaction) {
                $this->db->commit();
            }
            return $user;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /* --- Bajas de socios (derecho al olvido) ------------------------------ */

    /**
     * Marca un socio para baja. Reversible: solo lo apunta en una lista, no
     * borra nada. El filtro de sede impide marcar a un socio de otro gimnasio.
     */
    public function marcarBaja(int $id, int $idAdmin): bool {
        $tenantLifecycle = $this->acquireBusinessWrite();
        try {
            $stmt = $this->db->prepare(
                "UPDATE usuario
                    SET baja_pendiente = 1, baja_marcada_en = NOW(), baja_marcada_por = :admin
                  WHERE id_usuario = :id AND rol = 'socio' AND anonimizado_en IS NULL"
                . $this->filtroSede()
            );
            $stmt->execute([':admin' => $idAdmin, ':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.marcarBaja');
            return false;
        }
    }

    /** Quita la marca de baja: el socio se queda como estaba. */
    public function desmarcarBaja(int $id): bool {
        $tenantLifecycle = $this->acquireBusinessWrite();
        try {
            $stmt = $this->db->prepare(
                "UPDATE usuario
                    SET baja_pendiente = 0, baja_marcada_en = NULL, baja_marcada_por = NULL
                  WHERE id_usuario = :id" . $this->filtroSede()
            );
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.desmarcarBaja');
            return false;
        }
    }

    /** Socios marcados para baja y aún no anonimizados, con quién los marcó. */
    public function listarBajasPendientes(): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT u.id_usuario, u.nombre, u.apellidos, u.dni, u.email, u.baja_marcada_en,
                        a.nombre AS admin_nombre, a.apellidos AS admin_apellidos
                 FROM usuario u
                 LEFT JOIN usuario a ON a.id_usuario = u.baja_marcada_por
                 WHERE u.rol = 'socio' AND u.baja_pendiente = 1 AND u.anonimizado_en IS NULL"
                . $this->filtroSede('u') . "
                 ORDER BY u.baja_marcada_en ASC"
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.listarBajasPendientes');
            return [];
        }
    }

    /**
     * Borra los datos personales de un socio dejando su histórico contable.
     *
     * Esto es lo que cumple el RGPD sin saltarse a Hacienda: se limpian nombre,
     * DNI, email, teléfono, IBAN y foto, pero la fila se queda como una cáscara
     * ("Cliente dado de baja #N") a la que siguen colgando sus ventas y cuotas,
     * ya sin nombre. No se borra la fila: eso arrastraría las membresías en
     * cascada, que no se pueden perder.
     *
     * El usuario y el correo se sustituyen por valores únicos e inertes porque
     * son columnas UNIQUE: no pueden quedar en blanco si hay más de una baja.
     *
     * Devuelve el nombre que tenía (para el log) o null si no se pudo.
     */
    public function anonimizar(int $id): ?string {
        $tenantLifecycle = $this->acquireBusinessWrite();
        $socio = $this->buscarPorId($id);
        if (!$socio || ($socio['rol'] ?? '') !== 'socio' || !empty($socio['anonimizado_en'])) {
            return null;
        }
        $nombrePrevio = trim(($socio['nombre'] ?? '') . ' ' . ($socio['apellidos'] ?? ''));

        try {
            $foto = $socio['foto'] ?? null;
            $stmt = $this->db->prepare(
                "UPDATE usuario SET
                    nombre         = 'Cliente dado de baja',
                    apellidos      = '',
                    dni            = CONCAT('BAJA-', id_usuario),
                    telefono       = NULL,
                    iban           = NULL,
                    email          = CONCAT('baja-', id_usuario, '@anonimo.local'),
                    nombre_usuario = CONCAT('baja_', id_usuario),
                    foto           = NULL,
                    contrasena     = '',
                    reset_token    = NULL,
                    reset_expira   = NULL,
                    activo         = 0,
                    baja_pendiente = 0,
                    anonimizado_en = NOW()
                 WHERE id_usuario = :id AND rol = 'socio' AND anonimizado_en IS NULL"
                . $this->filtroSede()
            );
            $stmt->execute([':id' => $id]);
            if ($stmt->rowCount() === 0) {
                return null;
            }

            // La foto era el único dato personal fuera de la base: se borra del disco.
            if ($foto) {
                PrivatePhotoStorage::delete((string) $foto);
            }
            return $nombrePrevio;
        } catch (\PDOException $e) {
            SafeException::log('user_model_failed', $e, 'UserModel.anonimizar');
            return null;
        }
    }

    public function eliminarUsuario(int $id): bool {
        $tenantLifecycle = $this->acquireBusinessWrite();
        $usuario = $this->buscarPorId($id);
        if (!$usuario) return false;
        try {
            $foto = $usuario['foto'] ?? null;

            $this->db->beginTransaction();

            foreach (['personas', 'usuario_curso', 'log_actividad'] as $tabla) {
                try {
                    $stmt = $this->db->prepare("DELETE FROM {$tabla} WHERE id_usuario = :id");
                    $stmt->execute([':id' => $id]);
                } catch (\PDOException $e) {
                    SafeException::log('user_model_legacy_cleanup_failed', $e, 'UserModel.eliminarUsuario.' . $tabla);
                }
            }

            try {
                $stmt = $this->db->prepare('UPDATE curso SET id_profesor = NULL WHERE id_profesor = :id');
                $stmt->execute([':id' => $id]);
            } catch (\PDOException $e) {
                SafeException::log('user_model_legacy_cleanup_failed', $e, 'UserModel.eliminarUsuario.curso');
            }

            $stmt = $this->db->prepare('DELETE FROM usuario WHERE id_usuario = :id');
            $ok = $stmt->execute([':id' => $id]);

            if ($ok) {
                $this->db->commit();
                if ($foto) {
                    PrivatePhotoStorage::delete((string) $foto);
                }
                return true;
            }
            $this->db->rollBack();
            return false;
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            SafeException::log('user_model_failed', $e, 'UserModel.eliminarUsuario');
            return false;
        }
    }

}
