<?php
/**
 * GimnasioModel — alta y gestión de las sedes.
 *
 * A diferencia del resto de modelos, este NO se filtra por sede: gestionar
 * gimnasios es competencia de la empresa, que los ve todos. El control de
 * quién entra aquí lo hace el guardia del controlador.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Iban.php';

class GimnasioModel
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Sedes con el recuento de socios, empleados y productos de cada una. */
    public function listar(): array
    {
        try {
            return $this->db->query(
                "SELECT g.*,
                        (SELECT COUNT(*) FROM usuario u
                          WHERE u.id_gimnasio = g.id_gimnasio AND u.rol = 'socio')     AS num_socios,
                        (SELECT COUNT(*) FROM usuario u
                          WHERE u.id_gimnasio = g.id_gimnasio
                            AND u.rol IN ('admin','recepcion'))                        AS num_empleados,
                        (SELECT COUNT(*) FROM producto p
                          WHERE p.id_gimnasio = g.id_gimnasio)                         AS num_productos
                 FROM gimnasio g
                 ORDER BY g.activo DESC, g.nombre ASC"
            )->fetchAll();
        } catch (\PDOException $e) {
            error_log('GimnasioModel::listar error: ' . $e->getMessage());
            return [];
        }
    }

    /** Solo las sedes abiertas: alimenta los desplegables y la pantalla de acceso. */
    public function listarActivas(): array
    {
        try {
            return $this->db->query(
                "SELECT id_gimnasio, nombre, slug, logo, color_primario, color_texto
                 FROM gimnasio WHERE activo = 1 ORDER BY nombre ASC"
            )->fetchAll();
        } catch (\PDOException $e) {
            error_log('GimnasioModel::listarActivas error: ' . $e->getMessage());
            return [];
        }
    }

    /* --- Credenciales de acceso del gimnasio ------------------------------ */

    public function buscarPorEmailAcceso(string $email): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM gimnasio WHERE email_acceso = :email LIMIT 1"
        );
        $stmt->execute([':email' => strtolower(trim($email))]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function emailAccesoExiste(string $email, int $excluirId = 0): bool
    {
        $stmt = $this->db->prepare(
            "SELECT id_gimnasio FROM gimnasio
             WHERE email_acceso = :email AND id_gimnasio <> :id LIMIT 1"
        );
        $stmt->execute([':email' => strtolower(trim($email)), ':id' => $excluirId]);
        return (bool) $stmt->fetch();
    }

    /**
     * Fija el email y, si se indica, la contraseña del gimnasio.
     * Con $contrasena vacía se conserva la que hubiera: así se puede corregir
     * el email sin obligar a teclear de nuevo la clave.
     */
    public function guardarCredenciales(int $id, string $email, string $contrasena = ''): bool
    {
        try {
            if ($contrasena !== '') {
                $stmt = $this->db->prepare(
                    "UPDATE gimnasio SET email_acceso = :email, contrasena_acceso = :clave
                     WHERE id_gimnasio = :id"
                );
                return $stmt->execute([
                    ':email' => strtolower(trim($email)),
                    ':clave' => password_hash($contrasena, PASSWORD_BCRYPT, ['cost' => 12]),
                    ':id'    => $id,
                ]);
            }
            $stmt = $this->db->prepare(
                "UPDATE gimnasio SET email_acceso = :email WHERE id_gimnasio = :id"
            );
            return $stmt->execute([':email' => strtolower(trim($email)), ':id' => $id]);
        } catch (\PDOException $e) {
            error_log('GimnasioModel::guardarCredenciales error: ' . $e->getMessage());
            return false;
        }
    }

    /** Comprueba email y contraseña. Devuelve el gimnasio o null. */
    public function autenticar(string $email, string $contrasena): ?array
    {
        $gimnasio = $this->buscarPorEmailAcceso($email);

        if (!$gimnasio || empty($gimnasio['contrasena_acceso'])) {
            // Se compara igualmente contra un hash de descarte para que un
            // email inexistente tarde lo mismo que uno real y no se pueda
            // deducir cuáles están dados de alta por el tiempo de respuesta.
            password_verify($contrasena, '$2y$12$usuarioinexistenteusuarioinexistenteusuarioinexistente12');
            return null;
        }
        if ((int) $gimnasio['activo'] !== 1) {
            return null;
        }
        if (!password_verify($contrasena, $gimnasio['contrasena_acceso'])) {
            return null;
        }
        return $gimnasio;
    }

    /** ¿Tiene ya credenciales configuradas? */
    public function tieneAcceso(array $gimnasio): bool
    {
        return !empty($gimnasio['email_acceso']) && !empty($gimnasio['contrasena_acceso']);
    }

    public function buscarPorSlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM gimnasio WHERE slug = :slug LIMIT 1");
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Convierte un nombre en identificador de URL: "Sede Centro" → "sede-centro".
     * Si ya existe, se le añade un sufijo numérico.
     */
    public function generarSlug(string $nombre, int $excluirId = 0): string
    {
        $base = strtolower(strtr(trim($nombre), [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c',
            'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n',
        ]));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base);
        $base = trim($base, '-') ?: 'sede';

        $slug = $base;
        $i = 2;
        while ($this->slugExiste($slug, $excluirId)) {
            $slug = $base . '-' . $i++;
        }
        return mb_substr($slug, 0, 60);
    }

    private function slugExiste(string $slug, int $excluirId = 0): bool
    {
        $stmt = $this->db->prepare(
            "SELECT id_gimnasio FROM gimnasio WHERE slug = :slug AND id_gimnasio <> :id LIMIT 1"
        );
        $stmt->execute([':slug' => $slug, ':id' => $excluirId]);
        return (bool) $stmt->fetch();
    }

    /** Guarda el logo y los colores de la marca de una sede. */
    public function actualizarMarca(int $id, ?string $logo, string $colorPrimario, string $colorTexto): bool
    {
        // Solo se aceptan colores en formato #rrggbb: van directos a un atributo
        // style, así que no puede colarse nada más.
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colorPrimario)) $colorPrimario = '#4f46e5';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colorTexto))    $colorTexto    = '#ffffff';

        try {
            if ($logo === null) {
                $stmt = $this->db->prepare(
                    "UPDATE gimnasio SET color_primario = :cp, color_texto = :ct WHERE id_gimnasio = :id"
                );
                return $stmt->execute([':cp' => $colorPrimario, ':ct' => $colorTexto, ':id' => $id]);
            }
            $stmt = $this->db->prepare(
                "UPDATE gimnasio SET logo = :logo, color_primario = :cp, color_texto = :ct WHERE id_gimnasio = :id"
            );
            return $stmt->execute([':logo' => $logo, ':cp' => $colorPrimario, ':ct' => $colorTexto, ':id' => $id]);
        } catch (\PDOException $e) {
            error_log('GimnasioModel::actualizarMarca error: ' . $e->getMessage());
            return false;
        }
    }

    public function quitarLogo(int $id): bool
    {
        try {
            return $this->db->prepare("UPDATE gimnasio SET logo = NULL WHERE id_gimnasio = :id")
                            ->execute([':id' => $id]);
        } catch (\PDOException $e) {
            error_log('GimnasioModel::quitarLogo error: ' . $e->getMessage());
            return false;
        }
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM gimnasio WHERE id_gimnasio = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function nombreExiste(string $nombre, int $excluirId = 0): bool
    {
        $stmt = $this->db->prepare(
            "SELECT id_gimnasio FROM gimnasio WHERE nombre = :nombre AND id_gimnasio <> :id LIMIT 1"
        );
        $stmt->execute([':nombre' => $nombre, ':id' => $excluirId]);
        return (bool) $stmt->fetch();
    }

    public function crear(array $datos): ?int
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO gimnasio (nombre, slug, razon_social, cif, direccion, telefono, email)
                 VALUES (:nombre, :slug, :razon_social, :cif, :direccion, :telefono, :email)"
            );
            $stmt->execute([
                ':nombre'       => $datos['nombre'],
                ':slug'         => $this->generarSlug($datos['nombre']),
                ':razon_social' => $datos['razon_social'] ?: null,
                ':cif'          => $datos['cif']          ?: null,
                ':direccion'    => $datos['direccion']    ?: null,
                ':telefono'     => $datos['telefono']     ?: null,
                ':email'        => $datos['email']        ?: null,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            error_log('GimnasioModel::crear error: ' . $e->getMessage());
            return null;
        }
    }

    public function actualizar(int $id, array $datos): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE gimnasio SET
                    nombre       = :nombre,
                    slug         = :slug,
                    razon_social = :razon_social,
                    cif          = :cif,
                    direccion    = :direccion,
                    telefono     = :telefono,
                    email        = :email
                 WHERE id_gimnasio = :id"
            );
            return $stmt->execute([
                ':nombre'       => $datos['nombre'],
                ':slug'         => $this->generarSlug($datos['nombre'], $id),
                ':razon_social' => $datos['razon_social'] ?: null,
                ':cif'          => $datos['cif']          ?: null,
                ':direccion'    => $datos['direccion']    ?: null,
                ':telefono'     => $datos['telefono']     ?: null,
                ':email'        => $datos['email']        ?: null,
                ':id'           => $id,
            ]);
        } catch (\PDOException $e) {
            error_log('GimnasioModel::actualizar error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cierra o reabre una sede. No se borra nunca: sus ventas, socios y
     * membresías tienen que seguir existiendo para la contabilidad.
     */
    public function toggleActivo(int $id): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE gimnasio SET activo = NOT activo WHERE id_gimnasio = :id"
            );
            return $stmt->execute([':id' => $id]);
        } catch (\PDOException $e) {
            error_log('GimnasioModel::toggleActivo error: ' . $e->getMessage());
            return false;
        }
    }

    public function contarActivas(): int
    {
        try {
            return (int) $this->db->query("SELECT COUNT(*) FROM gimnasio WHERE activo = 1")->fetchColumn();
        } catch (\PDOException $e) {
            error_log('GimnasioModel::contarActivas error: ' . $e->getMessage());
            return 0;
        }
    }
}
