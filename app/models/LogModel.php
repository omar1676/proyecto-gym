<?php
/**
 * LogModel — historial de actividad (auditoría).
 *
 * Responde a "quién hizo qué, sobre quién y cuándo". Cada entrada guarda:
 *   - el usuario que actúa (id_usuario)
 *   - el usuario afectado, si la acción va sobre una persona (id_usuario_afectado)
 *   - la entidad tocada y su id (producto #4, venta #12, socio #7…)
 *   - el valor anterior y el nuevo, cuando se trata de una modificación
 *   - la IP y el gimnasio desde el que se hizo
 *
 * El método registrar() mantiene su firma original, así que las llamadas ya
 * escritas siguen funcionando; para las nuevas se usa registrarCambio().
 */

require_once __DIR__ . '/../config/database.php';

class LogModel {
    private $db;
    private $idEmpresa;

    public function __construct(?int $idEmpresa = null) {
        $this->db = Database::getInstance()->getConnection();
        $this->idEmpresa = $idEmpresa;
    }

    /**
     * Registro simple: quién, qué y detalle en texto.
     * Se mantiene por compatibilidad con las llamadas ya existentes.
     */
    public function registrar(int $idUsuario = null, string $accion = '', string $detalle = ''): void {
        $this->registrarCambio($idUsuario, $accion, $detalle);
    }

    /**
     * Registro completo. Todo lo que va más allá de los tres primeros campos es
     * opcional, así que sirve igual para un alta que para un cambio de valor.
     *
     * Ejemplo — Dani cambia el vencimiento de Omar:
     *   $log->registrarCambio(
     *       $idDani, 'Cambio de vencimiento', 'Membresía de Omar Pérez',
     *       $idOmar, 'socio', $idOmar, '2026-08-30', '2026-09-30'
     *   );
     */
    public function registrarCambio(
        ?int    $idUsuario,
        string  $accion,
        string  $detalle = '',
        ?int    $idUsuarioAfectado = null,
        ?string $entidad = null,
        ?int    $idEntidad = null,
        ?string $valorAnterior = null,
        ?string $valorNuevo = null,
        ?int    $idGimnasio = null
    ): void {
        try {
            $empresa = $this->idEmpresa;
            if ($empresa === null && $idGimnasio !== null) {
                $q = $this->db->prepare('SELECT id_empresa FROM gimnasio WHERE id_gimnasio = :id');
                $q->execute([':id' => $idGimnasio]);
                $empresa = (int) $q->fetchColumn() ?: null;
            }
            $stmt = $this->db->prepare(
                "INSERT INTO log_actividad
                 (id_usuario, id_usuario_afectado, accion, entidad, id_entidad,
                  detalle, valor_anterior, valor_nuevo, ip, id_gimnasio, id_empresa, fecha)
                 VALUES
                 (:id_usuario, :id_afectado, :accion, :entidad, :id_entidad,
                  :detalle, :valor_anterior, :valor_nuevo, :ip, :id_gimnasio, :id_empresa, NOW())"
            );
            $stmt->execute([
                ':id_usuario'     => $idUsuario ?: null,
                ':id_afectado'    => $idUsuarioAfectado ?: null,
                ':accion'         => $accion,
                ':entidad'        => $entidad,
                ':id_entidad'     => $idEntidad ?: null,
                ':detalle'        => mb_substr($detalle, 0, 500),
                ':valor_anterior' => $valorAnterior !== null ? mb_substr($valorAnterior, 0, 255) : null,
                ':valor_nuevo'    => $valorNuevo    !== null ? mb_substr($valorNuevo, 0, 255)    : null,
                ':ip'             => $_SERVER['REMOTE_ADDR'] ?? null,
                ':id_gimnasio'    => $idGimnasio ?: null,
                ':id_empresa'     => $empresa,
            ]);
        } catch (\PDOException $e) {
            // El log nunca debe tumbar la operación que lo genera.
            error_log('LogModel::registrarCambio error: ' . $e->getMessage());
        }
    }

    /**
     * Historial con el nombre de quien actúa y de quien resulta afectado.
     *
     * $idGimnasio limita el listado a una sede; null lo muestra todo
     * (reservado al rol empresa).
     */
    public function listar(int $limit = 50, ?int $idGimnasio = null, array $filtros = []): array {
        $sql =
            "SELECT l.*,
                    u.nombre         AS autor_nombre,
                    u.apellidos      AS autor_apellidos,
                    u.nombre_usuario AS autor_usuario,
                    u.rol            AS autor_rol,
                    a.nombre         AS afectado_nombre,
                    a.apellidos      AS afectado_apellidos,
                    g.nombre         AS gimnasio_nombre
             FROM log_actividad l
             LEFT JOIN usuario  u ON u.id_usuario  = l.id_usuario
             LEFT JOIN usuario  a ON a.id_usuario  = l.id_usuario_afectado
             LEFT JOIN gimnasio g ON g.id_gimnasio = l.id_gimnasio
             WHERE 1 = 1";
        $params = [];

        if ($idGimnasio !== null) {
            $sql .= " AND l.id_gimnasio = :id_gimnasio";
            $params[':id_gimnasio'] = $idGimnasio;
        }
        if ($this->idEmpresa !== null) {
            $sql .= " AND l.id_empresa = :id_empresa";
            $params[':id_empresa'] = $this->idEmpresa;
        }
        if (!empty($filtros['id_usuario'])) {
            $sql .= " AND l.id_usuario = :id_autor";
            $params[':id_autor'] = (int) $filtros['id_usuario'];
        }
        if (!empty($filtros['id_afectado'])) {
            $sql .= " AND l.id_usuario_afectado = :id_afectado";
            $params[':id_afectado'] = (int) $filtros['id_afectado'];
        }
        if (!empty($filtros['desde'])) {
            $sql .= " AND DATE(l.fecha) >= :desde";
            $params[':desde'] = $filtros['desde'];
        }
        if (!empty($filtros['hasta'])) {
            $sql .= " AND DATE(l.fecha) <= :hasta";
            $params[':hasta'] = $filtros['hasta'];
        }
        if (!empty($filtros['buscar'])) {
            $sql .= " AND (l.accion LIKE :b1 OR l.detalle LIKE :b2
                           OR u.nombre LIKE :b3 OR a.nombre LIKE :b4
                           OR a.apellidos LIKE :b5)";
            $b = '%' . $filtros['buscar'] . '%';
            $params[':b1'] = $b; $params[':b2'] = $b; $params[':b3'] = $b;
            $params[':b4'] = $b; $params[':b5'] = $b;
        }

        $sql .= " ORDER BY l.fecha DESC LIMIT :limit";

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $clave => $valor) {
                $stmt->bindValue($clave, $valor);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('LogModel::listar error: ' . $e->getMessage());
            return [];
        }
    }

    /** Historial de un socio concreto: todo lo que se ha hecho sobre él. */
    public function listarPorAfectado(int $idAfectado, int $limit = 50): array {
        return $this->listar($limit, null, ['id_afectado' => $idAfectado]);
    }

    /** Empleados con actividad registrada, para el desplegable de filtros. */
    public function listarAutores(?int $idGimnasio = null): array {
        $sql =
            "SELECT DISTINCT u.id_usuario, u.nombre, u.apellidos, u.nombre_usuario
             FROM log_actividad l
             INNER JOIN usuario u ON u.id_usuario = l.id_usuario
             WHERE u.rol <> 'socio'";
        $params = [];
        if ($idGimnasio !== null) {
            $sql .= " AND l.id_gimnasio = :id_gimnasio";
            $params[':id_gimnasio'] = $idGimnasio;
        }
        if ($this->idEmpresa !== null) {
            $sql .= " AND l.id_empresa = :id_empresa";
            $params[':id_empresa'] = $this->idEmpresa;
        }
        $sql .= " ORDER BY u.nombre ASC";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('LogModel::listarAutores error: ' . $e->getMessage());
            return [];
        }
    }
}
