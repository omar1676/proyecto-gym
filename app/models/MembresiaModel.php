<?php
require_once dirname(__DIR__) . '/helpers/Money.php';
require_once __DIR__ . '/FinancialModel.php';
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
    private $idEmpresa;

    private const METODOS_VALIDOS = ['efectivo', 'datafono', 'transferencia'];

    /** Días que dura el acceso de prueba antes de cerrarse solo. */
    public const DIAS_PRUEBA = 5;

    /** Ver ProductoModel::__construct para el criterio de aislamiento por sede. */
    public function __construct(?int $idGimnasio = null, ?int $idEmpresa = null)
    {
        $this->db = Database::getInstance()->getConnection();
        $this->idGimnasio = $idGimnasio;
        $this->idEmpresa = $idEmpresa;
        if ($this->idEmpresa === null && $this->idGimnasio !== null) {
            $stmt = $this->db->prepare('SELECT id_empresa FROM gimnasio WHERE id_gimnasio = :id');
            $stmt->execute([':id' => $this->idGimnasio]);
            $this->idEmpresa = (int) $stmt->fetchColumn() ?: null;
        }
    }

    private function filtroSede(string $alias = 'sm'): string
    {
        $prefijo = $alias === '' ? '' : $alias . '.';
        if ($this->idGimnasio !== null) return ' AND ' . $prefijo . 'id_gimnasio = ' . (int) $this->idGimnasio;
        if ($this->idEmpresa !== null) {
            return ' AND ' . $prefijo . 'id_gimnasio IN (SELECT id_gimnasio FROM gimnasio WHERE id_empresa = '
                . (int) $this->idEmpresa . ')';
        }
        return '';
    }

    private function socioEnAmbito(int $idSocio): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM usuario WHERE id_usuario = :id AND rol = 'socio'" . $this->filtroSede('') . ' LIMIT 1'
        );
        $stmt->execute([':id' => $idSocio]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Los catálogos (cuotas y suplementos) admiten id_gimnasio NULL como
     * "común a todo el grupo", así que aquí el filtro es más permisivo: se ve
     * lo compartido más lo propio de la sede.
     */
    private function filtroCatalogo(string $alias = ''): string
    {
        $prefijo = $alias === '' ? '' : $alias . '.';
        if ($this->idEmpresa === null && $this->idGimnasio === null) return '';
        $empresa = $this->idEmpresa;
        if ($empresa === null && $this->idGimnasio !== null) {
            $stmt = $this->db->prepare('SELECT id_empresa FROM gimnasio WHERE id_gimnasio = :id');
            $stmt->execute([':id' => $this->idGimnasio]);
            $empresa = (int) $stmt->fetchColumn();
        }
        $sql = ' AND ' . $prefijo . 'id_empresa = ' . (int) $empresa;
        if ($this->idGimnasio !== null) {
            $sql .= ' AND (' . $prefijo . 'id_gimnasio IS NULL OR '
                 . $prefijo . 'id_gimnasio = ' . (int) $this->idGimnasio . ')';
        }
        return $sql;
    }

    /* --- Suplementos (plus sobre la cuota base) --------------------------- */

    public function listarSuplementos(): array
    {
        try {
            return $this->db->query(
                "SELECT * FROM suplemento WHERE 1 = 1" . $this->filtroCatalogo() . " ORDER BY nombre ASC"
            )->fetchAll();
        } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
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
                "INSERT INTO suplemento (id_empresa, nombre, descripcion, precio_mensual, estado, id_gimnasio)
                 VALUES (:id_empresa, :nombre, :descripcion, :precio, :estado, :id_gimnasio)"
            );
            return $stmt->execute([
                ':id_empresa'  => $this->idEmpresa,
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
                "INSERT INTO tipo_membresia (id_empresa, nombre, descripcion, precio, iva, duracion_meses, estado, id_gimnasio)
                 VALUES (:id_empresa, :nombre, :descripcion, :precio, :iva, :duracion_meses, :estado, :id_gimnasio)"
            );
            return $stmt->execute([
                ':id_empresa'     => $this->idEmpresa,
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
        string $origen = 'mostrador',
        ?string $idempotencyKey = null,
        ?int $idUsuario = null
    ): ?int {
        if (!in_array($metodoPago, self::METODOS_VALIDOS, true)) {
            $error = 'Método de pago no válido.';
            return null;
        }
        if (!$this->socioEnAmbito($idSocio)) {
            $error = 'El socio no pertenece al ámbito activo.';
            return null;
        }
        $sedeOperacion = $this->idGimnasio;
        $empresaOperacion = $this->idEmpresa;
        if ($sedeOperacion === null || $empresaOperacion === null) {
            $contexto = $this->db->prepare(
                'SELECT u.id_gimnasio, COALESCE(u.id_empresa, g.id_empresa) AS id_empresa
                 FROM usuario u LEFT JOIN gimnasio g ON g.id_gimnasio = u.id_gimnasio
                 WHERE u.id_usuario = :id LIMIT 1'
            );
            $contexto->execute([':id' => $idSocio]);
            $ctx = $contexto->fetch();
            $sedeOperacion = (int) ($ctx['id_gimnasio'] ?? 0) ?: null;
            $empresaOperacion = (int) ($ctx['id_empresa'] ?? 0) ?: null;
        }
        if ($idempotencyKey !== null) {
            $stmt = $this->db->prepare("SELECT id_socio_membresia FROM {$this->tabla} WHERE id_gimnasio = :sede AND idempotency_key = :clave LIMIT 1");
            $stmt->execute([':sede' => $sedeOperacion, ':clave' => $idempotencyKey]);
            $existente = (int) $stmt->fetchColumn();
            if ($existente > 0) return $existente;
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
            $precioSuplemento = Money::multiply($suplemento['precio_mensual'], (int) $tipo['duracion_meses']);
        }

        try {
            $this->db->beginTransaction();
            $lock = $this->db->prepare('SELECT id_usuario FROM usuario WHERE id_usuario = :id FOR UPDATE');
            $lock->execute([':id' => $idSocio]);

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

            $stmt = $this->db->prepare(
                "INSERT INTO {$this->tabla}
                 (id_socio, id_gimnasio, id_tipo_membresia, id_suplemento, nombre_tipo, nombre_suplemento,
                  precio_pagado, precio_suplemento, iva, metodo_pago, fecha_inicio, fecha_fin,
                   renovar_auto, origen, idempotency_key)
                 VALUES
                 (:id_socio, :id_gimnasio, :id_tipo, :id_suplemento, :nombre_tipo, :nombre_suplemento,
                  :precio, :precio_suplemento, :iva, :metodo_pago, :fecha_inicio, :fecha_fin,
                   :renovar_auto, :origen, :idempotency_key)"
            );
            $stmt->execute([
                ':id_socio'          => $idSocio,
                ':id_gimnasio'       => $sedeOperacion,
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
                ':idempotency_key'   => $idempotencyKey,
            ]);
            $idContrato = (int) $this->db->lastInsertId();

            // Contrato, obligación y cobro inmediato forman una única unidad:
            // si falla cualquiera, no queda una renovación a medias.
            if ($empresaOperacion !== null && $sedeOperacion !== null) {
                (new FinancialModel((int) $empresaOperacion, (int) $sedeOperacion, $this->db))
                    ->registrarMembresia($idContrato, $idUsuario);
            }

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

            $this->db->commit();
            return $idContrato;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
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
    public function iniciarPrueba(int $idSocio, string &$error, ?int $idUsuario = null): ?int
    {
        if (!$this->socioEnAmbito($idSocio)) {
            $error = 'El socio no pertenece al ámbito activo.';
            return null;
        }
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
        $sedeOperacion = $this->idGimnasio;
        $empresaOperacion = $this->idEmpresa;
        if ($sedeOperacion === null || $empresaOperacion === null) {
            $contexto = $this->db->prepare(
                'SELECT u.id_gimnasio, COALESCE(u.id_empresa, g.id_empresa) AS id_empresa
                 FROM usuario u LEFT JOIN gimnasio g ON g.id_gimnasio = u.id_gimnasio
                 WHERE u.id_usuario = :id LIMIT 1'
            );
            $contexto->execute([':id' => $idSocio]);
            $ctx = $contexto->fetch();
            $sedeOperacion = (int) ($ctx['id_gimnasio'] ?? 0) ?: null;
            $empresaOperacion = (int) ($ctx['id_empresa'] ?? 0) ?: null;
        }

        try {
            $this->db->beginTransaction();
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
                ':id_gimnasio'  => $sedeOperacion,
                ':nombre_tipo'  => 'Prueba ' . self::DIAS_PRUEBA . ' días',
                ':fecha_inicio' => $fechaInicio,
                ':fecha_fin'    => $fechaFin,
            ]);
            $idPrueba = (int) $this->db->lastInsertId();
            if ($empresaOperacion !== null && $sedeOperacion !== null) {
                (new FinancialModel((int) $empresaOperacion, (int) $sedeOperacion, $this->db))
                    ->registrarMembresia($idPrueba, $idUsuario);
            }
            $this->db->commit();
            return $idPrueba;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
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
            "SELECT 1 FROM {$this->tabla} WHERE id_socio = :id AND es_prueba = 1" . $this->filtroSede('') . " LIMIT 1"
        );
        $stmt->execute([':id' => $idSocio]);
        return (bool) $stmt->fetchColumn();
    }

    /** Prueba en curso de un socio, si la tiene. */
    public function pruebaVigenteDeSocio(int $idSocio): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->tabla}
             WHERE id_socio = :id AND es_prueba = 1 AND fecha_fin >= CURDATE()" . $this->filtroSede('') . "
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
             WHERE id_socio = :id" . $this->filtroSede('') . "
             ORDER BY fecha_fin DESC"
        );
        $stmt->execute([':id' => $idSocio]);
        return $stmt->fetchAll();
    }

    /** Condiciones preparadas de búsqueda; el ámbito sigue saliendo del modelo. */
    private function filtroBusquedaSocios(string $busqueda, array &$parametros): string
    {
        if ($busqueda === '') return '';

        // % y _ se tratan como texto, no como comodines aportados por el usuario.
        $texto = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $busqueda);
        $coincidencia = '%' . $texto . '%';
        foreach ([':b1', ':b2', ':b3', ':b4', ':b5'] as $nombre) {
            $parametros[$nombre] = $coincidencia;
        }

        $sql = " AND (u.nombre LIKE :b1 OR u.apellidos LIKE :b2
                       OR u.email LIKE :b3 OR u.dni LIKE :b4 OR u.telefono LIKE :b5";

        // Para buscar 600123456 aunque el teléfono se guardase como
        // "+34 600-123-456". El valor original no se modifica.
        $telefono = preg_replace('/\D+/u', '', $busqueda);
        if ($telefono !== '') {
            $parametros[':telefono'] = '%' . $telefono . '%';
            $sql .= " OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                        u.telefono, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')
                      LIKE :telefono";
        }

        return $sql . ')';
    }

    /** Número de socios que cumplen búsqueda y TenantContext. */
    public function contarSocios(string $busqueda = ''): int
    {
        $parametros = [];
        $sql = "SELECT COUNT(*) FROM usuario u
                WHERE u.rol = 'socio'" . $this->filtroSede('u')
             . $this->filtroBusquedaSocios($busqueda, $parametros);
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($parametros);
            return (int) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log('MembresiaModel::contarSocios error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Lista los socios con su última membresía y el estado calculado.
     *
     * $limite null conserva compatibilidad con procesos internos existentes.
     * La pantalla operativa siempre aporta límite y offset, por lo que MySQL
     * pagina antes de transferir o renderizar filas.
     */
    public function listarSocios(string $busqueda = '', ?int $limite = null, int $offset = 0): array
    {
        try {
            $parametros = [];
            $where = " WHERE u.rol = 'socio'" . $this->filtroSede('u')
                   . $this->filtroBusquedaSocios($busqueda, $parametros);

            // Con paginación, primero se materializan exclusivamente los 50
            // usuarios de la página. Solo después se busca su última cuota.
            // Evita ejecutar la subconsulta de membresía para 5.000 socios que
            // finalmente no iban a enviarse al navegador.
            if ($limite !== null) {
                $limite = max(1, min(100, $limite));
                $offset = max(0, $offset);
                $origenUsuarios = "(
                    SELECT u.id_usuario, u.nombre, u.apellidos, u.dni, u.email,
                           u.telefono, u.iban, u.foto, u.activo, u.created_at
                    FROM usuario u{$where}
                    ORDER BY u.apellidos ASC, u.nombre ASC, u.id_usuario ASC
                    LIMIT :limite OFFSET :offset
                ) u";
            } else {
                $origenUsuarios = 'usuario u';
            }

            $sql =
                "SELECT u.id_usuario, u.nombre, u.apellidos, u.dni, u.email, u.telefono, u.iban,
                        u.foto, u.activo, u.created_at,
                        sm.nombre_tipo, sm.nombre_suplemento,
                        sm.precio_pagado, sm.precio_suplemento,
                        sm.fecha_inicio, sm.fecha_fin,
                        sm.es_prueba, sm.estado_pago,
                        CASE
                            WHEN sm.fecha_fin IS NULL                          THEN 'sin_membresia'
                            WHEN sm.fecha_fin >= CURDATE() AND sm.es_prueba = 1 THEN 'prueba'
                            WHEN sm.fecha_fin >= CURDATE()                     THEN 'activa'
                            WHEN sm.es_prueba = 1                              THEN 'prueba_caducada'
                            ELSE 'vencida'
                        END AS estado_membresia,
                        DATEDIFF(sm.fecha_fin, CURDATE()) AS dias_restantes
                 FROM {$origenUsuarios}
                 LEFT JOIN {$this->tabla} sm ON sm.id_socio_membresia = (
                     SELECT s2.id_socio_membresia FROM {$this->tabla} s2
                     WHERE s2.id_socio = u.id_usuario
                     ORDER BY s2.fecha_fin DESC, s2.id_socio_membresia DESC
                     LIMIT 1
                 )";

            if ($limite === null) $sql .= $where;
            $sql .= " ORDER BY u.apellidos ASC, u.nombre ASC, u.id_usuario ASC";

            $stmt = $this->db->prepare($sql);
            foreach ($parametros as $nombre => $valor) {
                $stmt->bindValue($nombre, $valor, PDO::PARAM_STR);
            }
            if ($limite !== null) {
                $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            }
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log('MembresiaModel::listarSocios error: ' . $e->getMessage());
            return [];
        }
    }

    /** Resultado completo de una página, incluido total y límites seguros. */
    public function paginarSocios(string $busqueda = '', int $pagina = 1, int $porPagina = 50): array
    {
        $porPagina = max(1, min(100, $porPagina));
        $total = $this->contarSocios($busqueda);
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $offset = ($pagina - 1) * $porPagina;

        return [
            'items' => $this->listarSocios($busqueda, $porPagina, $offset),
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'paginas' => $paginas,
        ];
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

    /** Cobros de membresía realmente confirmados en el mes en curso. */
    public function sumarIngresosDelMes(): float
    {
        try {
            return (float) $this->db->query(
                "SELECT COALESCE(SUM(c.importe), 0) FROM cobro c
                 WHERE c.estado = 'confirmado'
                   AND c.id_socio_membresia IS NOT NULL
                   AND YEAR(c.fecha_estado) = YEAR(CURDATE())
                   AND MONTH(c.fecha_estado) = MONTH(CURDATE())" . $this->filtroSede('c')
            )->fetchColumn();
        } catch (\PDOException $e) {
            error_log('MembresiaModel::sumarIngresosDelMes error: ' . $e->getMessage());
            return 0.0;
        }
    }
}
