<?php
/**
 * MembresiaModel — acceso a las tablas `tipo_membresia` y `socio_membresia`.
 *
 * Secciones de este archivo (en orden):
 *   1. Constructor                     (__construct)
 *   2. Catálogo de tipos               (listarTipos, listarTiposActivos, buscarTipoPorId,
 *                                       crearTipo, actualizarTipo, toggleEstadoTipo)
 *   3. Contratación                    (contratar, vigenteDeSocio, historialDeSocio)
 *   4. Listados de socios              (listarSocios, listarProximasAVencer)
 *   5. Conteos para el panel           (contarActivas, contarVencidas, contarProximasAVencer,
 *                                       sumarIngresosDelMes)
 *
 * Una membresía NO guarda un campo "activa": se considera vigente mientras
 * `fecha_fin` sea hoy o posterior. Así el estado nunca queda desincronizado.
 */

require_once __DIR__ . '/../config/database.php';

class MembresiaModel
{
    private $db;
    private $tabla = 'socio_membresia';
    private $idGimnasio;

    private const METODOS_VALIDOS = ['efectivo', 'datafono', 'transferencia'];

    /** Días que dura el acceso de prueba antes de cerrarse solo. */
    public const DIAS_PRUEBA = 5;

    /** Ver ProductoModel::__construct para el criterio de aislamiento por sede. */
    public function __construct(?int $idGimnasio = null)
    {
        $this->db = Database::getInstance()->getConnection();
        $this->idGimnasio = $idGimnasio;
    }

    private function filtroSede(string $alias = 'sm'): string
    {
        if ($this->idGimnasio === null) return '';
        $prefijo = $alias === '' ? '' : $alias . '.';
        return ' AND ' . $prefijo . 'id_gimnasio = ' . (int) $this->idGimnasio;
    }

    /**
     * Los catálogos (cuotas y suplementos) admiten id_gimnasio NULL como
     * "común a todo el grupo", así que aquí el filtro es más permisivo: se ve
     * lo compartido más lo propio de la sede.
     */
    private function filtroCatalogo(string $alias = ''): string
    {
        if ($this->idGimnasio === null) return '';
        $prefijo = $alias === '' ? '' : $alias . '.';
        return ' AND (' . $prefijo . 'id_gimnasio IS NULL OR '
             . $prefijo . 'id_gimnasio = ' . (int) $this->idGimnasio . ')';
    }

    /* --- Suplementos (plus sobre la cuota base) --------------------------- */

    public function listarSuplementos(): array
    {
        try {
            return $this->db->query(
                "SELECT * FROM suplemento WHERE 1 = 1" . $this->filtroCatalogo() . " ORDER BY nombre ASC"
            )->fetchAll();
        } catch (\PDOException $e) {
            error_log('MembresiaModel::listarSuplementos error: ' . $e->getMessage());
            return [];
        }
    }

    public function listarSuplementosActivos(): array
    {
        try {
            return $this->db->query(
                "SELECT * FROM suplemento WHERE estado = 'activo'" . $this->filtroCatalogo() . " ORDER BY nombre ASC"
            )->fetchAll();
        } catch (\PDOException $e) {
            error_log('MembresiaModel::listarSuplementosActivos error: ' . $e->getMessage());
            return [];
        }
    }

    public function buscarSuplementoPorId(int $idSuplemento): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM suplemento WHERE id_suplemento = :id" . $this->filtroCatalogo() . " LIMIT 1"
        );
        $stmt->execute([':id' => $idSuplemento]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function crearSuplemento(string $nombre, ?string $descripcion, float $precioMensual, string $estado): bool
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO suplemento (nombre, descripcion, precio_mensual, estado, id_gimnasio)
                 VALUES (:nombre, :descripcion, :precio, :estado, :id_gimnasio)"
            );
            return $stmt->execute([
                ':nombre'      => $nombre,
                ':descripcion' => $descripcion,
                ':precio'      => $precioMensual,
                ':estado'      => $estado,
                ':id_gimnasio' => $this->idGimnasio,
            ]);
        } catch (\PDOException $e) {
            error_log('MembresiaModel::crearSuplemento error: ' . $e->getMessage());
            return false;
        }
    }

    public function actualizarSuplemento(int $idSuplemento, string $nombre, ?string $descripcion, float $precioMensual, string $estado): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE suplemento SET
                    nombre         = :nombre,
                    descripcion    = :descripcion,
                    precio_mensual = :precio,
                    estado         = :estado
                 WHERE id_suplemento = :id" . $this->filtroCatalogo()
            );
            return $stmt->execute([
                ':nombre'      => $nombre,
                ':descripcion' => $descripcion,
                ':precio'      => $precioMensual,
                ':estado'      => $estado,
                ':id'          => $idSuplemento,
            ]);
        } catch (\PDOException $e) {
            error_log('MembresiaModel::actualizarSuplemento error: ' . $e->getMessage());
            return false;
        }
    }

    public function toggleEstadoSuplemento(int $idSuplemento): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE suplemento
                 SET estado = IF(estado = 'activo', 'inactivo', 'activo')
                 WHERE id_suplemento = :id" . $this->filtroCatalogo()
            );
            return $stmt->execute([':id' => $idSuplemento]);
        } catch (\PDOException $e) {
            error_log('MembresiaModel::toggleEstadoSuplemento error: ' . $e->getMessage());
            return false;
        }
    }

    /* --- Catálogo de cuotas ----------------------------------------------- */

    public function listarTipos(): array
    {
        return $this->db->query(
            "SELECT * FROM tipo_membresia WHERE 1 = 1" . $this->filtroCatalogo() . " ORDER BY duracion_meses ASC, nombre ASC"
        )->fetchAll();
    }

    public function listarTiposActivos(): array
    {
        return $this->db->query(
            "SELECT * FROM tipo_membresia WHERE estado = 'activo'" . $this->filtroCatalogo() . "
             ORDER BY duracion_meses ASC, nombre ASC"
        )->fetchAll();
    }

    public function buscarTipoPorId(int $idTipo): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM tipo_membresia WHERE id_tipo_membresia = :id" . $this->filtroCatalogo() . " LIMIT 1"
        );
        $stmt->execute([':id' => $idTipo]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Alta de una cuota.
     *
     * Nace en la sede que la crea. Antes se guardaba siempre con id_gimnasio
     * NULL, que significa "común a toda la cadena", así que cualquier admin
     * cambiaba el precio a todas las sedes sin saberlo. Las cuotas comunes las
     * sigue creando la empresa, que trabaja sin sede fijada.
     *
     * $precio es lo que paga el socio, IVA incluido.
     */
    public function crearTipo(
        string $nombre,
        ?string $descripcion,
        float $precio,
        int $duracionMeses,
        string $estado,
        float $iva = 21.0
    ): bool {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO tipo_membresia (nombre, descripcion, precio, iva, duracion_meses, estado, id_gimnasio)
                 VALUES (:nombre, :descripcion, :precio, :iva, :duracion_meses, :estado, :id_gimnasio)"
            );
            return $stmt->execute([
                ':nombre'         => $nombre,
                ':descripcion'    => $descripcion,
                ':precio'         => $precio,
                ':iva'            => $iva,
                ':duracion_meses' => max(1, $duracionMeses),
                ':estado'         => $estado,
                ':id_gimnasio'    => $this->idGimnasio,
            ]);
        } catch (\PDOException $e) {
            error_log('MembresiaModel::crearTipo error: ' . $e->getMessage());
            return false;
        }
    }

    public function actualizarTipo(
        int $idTipo,
        string $nombre,
        ?string $descripcion,
        float $precio,
        int $duracionMeses,
        string $estado,
        float $iva = 21.0
    ): bool {
        try {
            // El filtro de catálogo deja tocar lo propio de la sede y lo común,
            // pero no la cuota exclusiva de otra sede.
            $stmt = $this->db->prepare(
                "UPDATE tipo_membresia SET
                    nombre         = :nombre,
                    descripcion    = :descripcion,
                    precio         = :precio,
                    iva            = :iva,
                    duracion_meses = :duracion_meses,
                    estado         = :estado
                 WHERE id_tipo_membresia = :id" . $this->filtroCatalogo()
            );
            return $stmt->execute([
                ':nombre'         => $nombre,
                ':descripcion'    => $descripcion,
                ':precio'         => $precio,
                ':iva'            => $iva,
                ':duracion_meses' => max(1, $duracionMeses),
                ':estado'         => $estado,
                ':id'             => $idTipo,
            ]);
        } catch (\PDOException $e) {
            error_log('MembresiaModel::actualizarTipo error: ' . $e->getMessage());
            return false;
        }
    }

    public function toggleEstadoTipo(int $idTipo): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE tipo_membresia
                 SET estado = IF(estado = 'activo', 'inactivo', 'activo')
                 WHERE id_tipo_membresia = :id" . $this->filtroCatalogo()
            );
            return $stmt->execute([':id' => $idTipo]);
        } catch (\PDOException $e) {
            error_log('MembresiaModel::toggleEstadoTipo error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Contrata (o renueva) una membresía para un socio.
     *
     * Si el socio ya tiene una membresía vigente, la nueva encadena a partir del
     * día siguiente a su vencimiento, para que renovar antes de tiempo no le
     * haga perder los días que le quedaban.
     *
     * Devuelve el id de la contratación, o null dejando el motivo en $error.
     */
    public function contratar(
        int $idSocio,
        int $idTipo,
        string $metodoPago,
        string &$error,
        ?int $idSuplemento = null,
        string $origen = 'mostrador'
    ): ?int {
        if (!in_array($metodoPago, self::METODOS_VALIDOS, true)) {
            $error = 'Método de pago no válido.';
            return null;
        }

        $tipo = $this->buscarTipoPorId($idTipo);
        if (!$tipo) {
            $error = 'El tipo de membresía seleccionado no existe.';
            return null;
        }

        // El plus se cobra por cada mes que dure la cuota base.
        $suplemento       = null;
        $precioSuplemento = 0.00;
        if ($idSuplemento) {
            $suplemento = $this->buscarSuplementoPorId($idSuplemento);
            if (!$suplemento) {
                $error = 'El suplemento seleccionado no existe.';
                return null;
            }
            $precioSuplemento = (float) $suplemento['precio_mensual'] * (int) $tipo['duracion_meses'];
        }

        $vigente = $this->vigenteDeSocio($idSocio);
        $prueba  = $this->pruebaVigenteDeSocio($idSocio);

        if ($vigente && empty($vigente['es_prueba'])) {
            // Renovación normal: encadena tras el vencimiento para no quitarle
            // al socio los días que le quedaban.
            $fechaInicio = date('Y-m-d', strtotime($vigente['fecha_fin'] . ' +1 day'));
        } else {
            // Alta nueva, o conversión de una prueba: empieza hoy. Encadenar
            // tras la prueba sería regalar esos días de cortesía.
            $fechaInicio = date('Y-m-d');
        }
        $fechaFin = date('Y-m-d', strtotime($fechaInicio . ' +' . (int) $tipo['duracion_meses'] . ' month -1 day'));

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO {$this->tabla}
                 (id_socio, id_gimnasio, id_tipo_membresia, id_suplemento, nombre_tipo, nombre_suplemento,
                  precio_pagado, precio_suplemento, iva, metodo_pago, fecha_inicio, fecha_fin,
                  renovar_auto, origen)
                 VALUES
                 (:id_socio, :id_gimnasio, :id_tipo, :id_suplemento, :nombre_tipo, :nombre_suplemento,
                  :precio, :precio_suplemento, :iva, :metodo_pago, :fecha_inicio, :fecha_fin,
                  :renovar_auto, :origen)"
            );
            $stmt->execute([
                ':id_socio'          => $idSocio,
                ':id_gimnasio'       => $this->idGimnasio,
                ':id_tipo'           => $idTipo,
                ':id_suplemento'     => $suplemento ? (int) $suplemento['id_suplemento'] : null,
                ':nombre_tipo'       => $tipo['nombre'],
                ':nombre_suplemento' => $suplemento ? $suplemento['nombre'] : null,
                ':precio'            => $tipo['precio'],
                ':precio_suplemento' => $precioSuplemento,
                ':iva'               => $tipo['iva'] ?? 21.00,
                ':metodo_pago'       => $metodoPago,
                ':fecha_inicio'      => $fechaInicio,
                ':fecha_fin'         => $fechaFin,
                // Solo se renueva sola la cuota domiciliada: las de mostrador
                // hay que cobrarlas en persona.
                ':renovar_auto'      => $metodoPago === 'transferencia' ? 1 : 0,
                ':origen'            => $origen,
            ]);
            $idContrato = (int) $this->db->lastInsertId();

            // La prueba queda cerrada y marcada como resuelta, para que deje de
            // aparecer como "pendiente de pagar".
            if ($prueba) {
                $cierre = $this->db->prepare(
                    "UPDATE {$this->tabla}
                     SET fecha_fin = DATE_SUB(CURDATE(), INTERVAL 1 DAY), estado_pago = 'pagado'
                     WHERE id_socio_membresia = :id"
                );
                $cierre->execute([':id' => (int) $prueba['id_socio_membresia']]);
            }

            return $idContrato;
        } catch (\PDOException $e) {
            error_log('MembresiaModel::contratar error: ' . $e->getMessage());
            $error = 'No se pudo registrar la membresía. Inténtalo de nuevo.';
            return null;
        }
    }

    /**
     * Abre el acceso de prueba a un socio: gratis, pendiente de pago y con
     * caducidad a los DIAS_PRUEBA días (hoy cuenta como el primero).
     *
     * No hay que cerrarla luego: al pasar `fecha_fin`, vigenteDeSocio() deja de
     * devolverla y el acceso queda cerrado sin intervención de nadie.
     *
     * Devuelve el id de la prueba, o null dejando el motivo en $error.
     */
    public function iniciarPrueba(int $idSocio, string &$error): ?int
    {
        if ($this->vigenteDeSocio($idSocio)) {
            $error = 'Este socio ya tiene el acceso abierto.';
            return null;
        }
        if ($this->tuvoPrueba($idSocio)) {
            $error = 'Este socio ya disfrutó de un periodo de prueba.';
            return null;
        }

        $fechaInicio = date('Y-m-d');
        $fechaFin    = date('Y-m-d', strtotime('+' . (self::DIAS_PRUEBA - 1) . ' days'));

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO {$this->tabla}
                 (id_socio, id_gimnasio, id_tipo_membresia, nombre_tipo, precio_pagado,
                  precio_suplemento, metodo_pago, fecha_inicio, fecha_fin, es_prueba, estado_pago)
                 VALUES
                 (:id_socio, :id_gimnasio, NULL, :nombre_tipo, 0.00,
                  0.00, 'efectivo', :fecha_inicio, :fecha_fin, 1, 'pendiente')"
            );
            $stmt->execute([
                ':id_socio'     => $idSocio,
                ':id_gimnasio'  => $this->idGimnasio,
                ':nombre_tipo'  => 'Prueba ' . self::DIAS_PRUEBA . ' días',
                ':fecha_inicio' => $fechaInicio,
                ':fecha_fin'    => $fechaFin,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            error_log('MembresiaModel::iniciarPrueba error: ' . $e->getMessage());
            $error = 'No se pudo abrir el acceso de prueba.';
            return null;
        }
    }

    /* --- Renovación automática -------------------------------------------- */

    /**
     * Cuotas que toca renovar hoy: las domiciliadas que vencen dentro de los
     * próximos $margen días y que nadie ha renovado ya a mano.
     *
     * El margen existe porque el recibo SEPA tarda unos días en cobrarse: si se
     * lanzara el mismo día del vencimiento, el socio se quedaría sin acceso
     * mientras el banco tramita. Con tres días de adelanto la cuota nueva entra
     * en vigor sin corte.
     *
     * Condiciones, una por una:
     *   - renovar_auto = 1     el socio no ha pedido que se le deje de cobrar
     *   - es_prueba = 0        una prueba no se renueva: se convierte o caduca
     *   - id_tipo_membresia    tiene que seguir existiendo la cuota que renovar
     *   - u.activo = 1         a un socio dado de baja no se le cobra
     *   - NOT EXISTS(...)      no hay ya otra cuota que empiece después: si la
     *                          renovaron en el mostrador, no se duplica
     */
    public function listarParaRenovar(int $margenDias = 3): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT sm.*, u.nombre, u.apellidos, u.email
                 FROM {$this->tabla} sm
                 INNER JOIN usuario u ON u.id_usuario = sm.id_socio
                 WHERE sm.renovar_auto = 1
                   AND sm.es_prueba = 0
                   AND sm.id_tipo_membresia IS NOT NULL
                   AND u.activo = 1
                   AND sm.fecha_fin >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                   AND sm.fecha_fin <= DATE_ADD(CURDATE(), INTERVAL :margen DAY)
                   AND NOT EXISTS (
                       SELECT 1 FROM {$this->tabla} s2
                       WHERE s2.id_socio = sm.id_socio
                         AND s2.fecha_inicio > sm.fecha_fin
                   )" . $this->filtroSede() . "
                 ORDER BY sm.fecha_fin ASC"
            );
            $stmt->bindValue(':margen', $margenDias, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log('MembresiaModel::listarParaRenovar error: ' . $e->getMessage());
            return [];
        }
    }

    /** El socio pide que dejen de renovarle la cuota sola. */
    public function desactivarRenovacion(int $idContrato): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE {$this->tabla} SET renovar_auto = 0
                  WHERE id_socio_membresia = :id" . $this->filtroSede('')
            );
            $stmt->execute([':id' => $idContrato]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log('MembresiaModel::desactivarRenovacion error: ' . $e->getMessage());
            return false;
        }
    }

    /** ¿Este socio ya tuvo una prueba alguna vez? Evita encadenar pruebas. */
    public function tuvoPrueba(int $idSocio): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM {$this->tabla} WHERE id_socio = :id AND es_prueba = 1 LIMIT 1"
        );
        $stmt->execute([':id' => $idSocio]);
        return (bool) $stmt->fetchColumn();
    }

    /** Prueba en curso de un socio, si la tiene. */
    public function pruebaVigenteDeSocio(int $idSocio): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->tabla}
             WHERE id_socio = :id AND es_prueba = 1 AND fecha_fin >= CURDATE()
             ORDER BY fecha_fin DESC LIMIT 1"
        );
        $stmt->execute([':id' => $idSocio]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Pruebas abiertas ahora mismo, pendientes de que alguien las confirme. */
    public function listarPruebasPendientes(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT sm.*, u.nombre, u.apellidos, u.email, u.telefono,
                        DATEDIFF(sm.fecha_fin, CURDATE()) AS dias_restantes
                 FROM {$this->tabla} sm
                 INNER JOIN usuario u ON u.id_usuario = sm.id_socio
                 WHERE sm.es_prueba = 1
                   AND sm.estado_pago = 'pendiente'
                   AND sm.fecha_fin >= CURDATE()" . $this->filtroSede() . "
                 ORDER BY sm.fecha_fin ASC"
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log('MembresiaModel::listarPruebasPendientes error: ' . $e->getMessage());
            return [];
        }
    }

    public function contarPruebasPendientes(): int
    {
        return count($this->listarPruebasPendientes());
    }

    /** Membresía en vigor de un socio (la de vencimiento más lejano), o null. */
    public function vigenteDeSocio(int $idSocio): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->tabla}
             WHERE id_socio = :id AND fecha_fin >= CURDATE()" . $this->filtroSede('') . "
             ORDER BY fecha_fin DESC
             LIMIT 1"
        );
        $stmt->execute([':id' => $idSocio]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function historialDeSocio(int $idSocio): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->tabla}
             WHERE id_socio = :id
             ORDER BY fecha_fin DESC"
        );
        $stmt->execute([':id' => $idSocio]);
        return $stmt->fetchAll();
    }

    /**
     * Lista los socios con su última membresía y el estado calculado
     * (activa / vencida / sin_membresia).
     */
    public function listarSocios(string $busqueda = ''): array
    {
        $sql =
            "SELECT u.id_usuario, u.nombre, u.apellidos, u.dni, u.email, u.telefono, u.iban,
                    u.foto, u.activo, u.created_at,
                    sm.nombre_tipo, sm.nombre_suplemento,
                    sm.precio_pagado, sm.precio_suplemento,
                    sm.fecha_inicio, sm.fecha_fin,
                    sm.es_prueba, sm.estado_pago,
                    CASE
                        WHEN sm.fecha_fin IS NULL                     THEN 'sin_membresia'
                        WHEN sm.fecha_fin >= CURDATE() AND sm.es_prueba = 1 THEN 'prueba'
                        WHEN sm.fecha_fin >= CURDATE()                THEN 'activa'
                        WHEN sm.es_prueba = 1                         THEN 'prueba_caducada'
                        ELSE 'vencida'
                    END AS estado_membresia,
                    DATEDIFF(sm.fecha_fin, CURDATE()) AS dias_restantes
             FROM usuario u
             LEFT JOIN {$this->tabla} sm ON sm.id_socio_membresia = (
                 SELECT s2.id_socio_membresia FROM {$this->tabla} s2
                 WHERE s2.id_socio = u.id_usuario
                 ORDER BY s2.fecha_fin DESC
                 LIMIT 1
             )
             WHERE u.rol = 'socio'" . $this->filtroSede('u');

        try {
            if ($busqueda !== '') {
                $b = '%' . $busqueda . '%';
                $sql .= " AND (u.nombre LIKE :b1 OR u.apellidos LIKE :b2
                               OR u.email LIKE :b3 OR u.dni LIKE :b4)";
                $sql .= " ORDER BY u.apellidos ASC, u.nombre ASC";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([':b1' => $b, ':b2' => $b, ':b3' => $b, ':b4' => $b]);
            } else {
                $sql .= " ORDER BY u.apellidos ASC, u.nombre ASC";
                $stmt = $this->db->prepare($sql);
                $stmt->execute();
            }
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log('MembresiaModel::listarSocios error: ' . $e->getMessage());
            return [];
        }
    }

    /** Socios cuya membresía vigente vence dentro de los próximos $dias días. */
    public function listarProximasAVencer(int $dias = 15): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT u.id_usuario, u.nombre, u.apellidos, u.email, u.telefono,
                        sm.nombre_tipo, sm.nombre_suplemento, sm.fecha_inicio, sm.fecha_fin,
                        DATEDIFF(sm.fecha_fin, CURDATE()) AS dias_restantes
                 FROM {$this->tabla} sm
                 INNER JOIN usuario u ON u.id_usuario = sm.id_socio
                 WHERE sm.id_socio_membresia = (
                           SELECT s2.id_socio_membresia FROM {$this->tabla} s2
                           WHERE s2.id_socio = sm.id_socio
                           ORDER BY s2.fecha_fin DESC
                           LIMIT 1
                       )
                   AND sm.fecha_fin >= CURDATE()
                   AND sm.fecha_fin <= DATE_ADD(CURDATE(), INTERVAL :dias DAY)" . $this->filtroSede() . "
                 ORDER BY sm.fecha_fin ASC"
            );
            $stmt->bindValue(':dias', $dias, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log('MembresiaModel::listarProximasAVencer error: ' . $e->getMessage());
            return [];
        }
    }

    public function contarActivas(): int
    {
        try {
            return (int) $this->db->query(
                "SELECT COUNT(DISTINCT id_socio) FROM {$this->tabla} WHERE fecha_fin >= CURDATE()" . $this->filtroSede('')
            )->fetchColumn();
        } catch (\PDOException $e) {
            error_log('MembresiaModel::contarActivas error: ' . $e->getMessage());
            return 0;
        }
    }

    /** Socios cuya última membresía ya venció (no cuenta a quien nunca tuvo). */
    public function contarVencidas(): int
    {
        try {
            return (int) $this->db->query(
                "SELECT COUNT(*) FROM usuario u
                 WHERE u.rol = 'socio'" . $this->filtroSede('u') . "
                   AND EXISTS (SELECT 1 FROM socio_membresia s WHERE s.id_socio = u.id_usuario)
                   AND NOT EXISTS (
                       SELECT 1 FROM socio_membresia s
                       WHERE s.id_socio = u.id_usuario AND s.fecha_fin >= CURDATE()
                   )"
            )->fetchColumn();
        } catch (\PDOException $e) {
            error_log('MembresiaModel::contarVencidas error: ' . $e->getMessage());
            return 0;
        }
    }

    public function contarProximasAVencer(int $dias = 15): int
    {
        return count($this->listarProximasAVencer($dias));
    }

    /** Ingresos por membresías contratadas en el mes en curso. */
    public function sumarIngresosDelMes(): float
    {
        try {
            return (float) $this->db->query(
                "SELECT COALESCE(SUM(precio_pagado + precio_suplemento), 0) FROM {$this->tabla}
                 WHERE YEAR(created_at) = YEAR(CURDATE())
                   AND MONTH(created_at) = MONTH(CURDATE())" . $this->filtroSede('')
            )->fetchColumn();
        } catch (\PDOException $e) {
            error_log('MembresiaModel::sumarIngresosDelMes error: ' . $e->getMessage());
            return 0.0;
        }
    }
}
