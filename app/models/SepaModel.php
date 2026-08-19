<?php
require_once dirname(__DIR__) . '/helpers/Money.php';
/**
 * SepaModel — mandatos de domiciliación y remesas de adeudos.
 *
 * Secciones de este archivo (en orden):
 *   1. Constructor y filtro de sede
 *   2. Datos del acreedor            (acreedor, guardarAcreedor, acreedorCompleto)
 *   3. Mandatos                      (crearMandato, mandatoActivo, revocarMandato,
 *                                     listarMandatos, generarReferencia)
 *   4. Preparación de la remesa      (listarDomiciliablesPendientes)
 *   5. Remesas                       (crearRemesa, listarRemesas, buscarRemesa,
 *                                     listarRecibos, marcarEnviada, marcarCobrada)
 *   6. Devoluciones                  (marcarDevuelto)
 *
 * El importe y el IBAN se copian al recibo en el momento de crear la remesa:
 * lo enviado al banco no debe cambiar porque luego se edite la ficha del socio.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Iban.php';

class SepaModel
{
    private $db;
    private $idGimnasio;
    private $idEmpresa;

    public function __construct(?int $idGimnasio = null, ?int $idEmpresa = null)
    {
        $this->db = Database::getInstance()->getConnection();
        $this->idGimnasio = $idGimnasio;
        $this->idEmpresa = $idEmpresa;
    }

    private function filtroSede(string $alias = ''): string
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

    /* --- Datos del acreedor ---------------------------------------------- */

    /**
     * Datos bancarios de la sede que cobra.
     *
     * Sin sede fijada devuelve null a propósito. Antes caía al primer gimnasio
     * de la tabla, y eso significaba generar un fichero con el IBAN y el
     * identificador de acreedor de OTRA sede cobrando a estos socios: el banco
     * lo acepta y el dinero acaba donde no debe.
     */
    public function acreedor(): ?array
    {
        $id = $this->idGimnasio;
        if ($id === null) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM gimnasio WHERE id_gimnasio = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** El banco rechaza la remesa si falta cualquiera de estos tres datos. */
    public function acreedorCompleto(): bool
    {
        $a = $this->acreedor();
        return $a
            && !empty($a['iban'])
            && !empty($a['identificador_acreedor'])
            && !empty($a['razon_social'] ?: $a['nombre']);
    }

    public function guardarAcreedor(int $idGimnasio, array $datos): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE gimnasio SET
                    razon_social           = :razon_social,
                    cif                    = :cif,
                    iban                   = :iban,
                    bic                    = :bic,
                    identificador_acreedor = :id_acreedor
                 WHERE id_gimnasio = :id" . $this->filtroSede()
            );
            return $stmt->execute([
                ':razon_social' => $datos['razon_social'] ?: null,
                ':cif'          => $datos['cif'] ?: null,
                ':iban'         => $datos['iban'] ? Iban::normalizar($datos['iban']) : null,
                ':bic'          => $datos['bic'] ? strtoupper(trim($datos['bic'])) : null,
                ':id_acreedor'  => $datos['identificador_acreedor'] ?: null,
                ':id'           => $idGimnasio,
            ]);
        } catch (\PDOException $e) {
            error_log('SepaModel::guardarAcreedor error: ' . $e->getMessage());
            return false;
        }
    }

    /* --- Mandatos --------------------------------------------------------- */

    /**
     * Referencia definitiva del mandato: la que viaja al banco en cada adeudo.
     *
     * Se construye con el id del propio mandato, no con la fecha: un socio que
     * cambia de banco firma un mandato nuevo el mismo día que revoca el
     * anterior, y una referencia por fecha colisionaría. Debe ser única y no
     * cambiar nunca una vez enviada al banco.
     */
    public function generarReferencia(int $idSocio, int $idMandato): string
    {
        return 'MND-' . str_pad((string) $idSocio, 6, '0', STR_PAD_LEFT)
             . '-' . str_pad((string) $idMandato, 6, '0', STR_PAD_LEFT);
    }

    public function crearMandato(
        int $idSocio,
        string $iban,
        string $fechaFirma,
        string &$error,
        string $tipo = 'recurrente'
    ): ?int {
        $iban = Iban::normalizar($iban);
        if (!Iban::esValido($iban)) {
            $error = 'El IBAN del mandato no es válido.';
            return null;
        }
        if (!in_array($tipo, ['recurrente', 'unico'], true)) {
            $tipo = 'recurrente';
        }
        if (!$this->socioEnAmbito($idSocio)) {
            $error = 'El socio no pertenece al ámbito activo.';
            return null;
        }

        try {
            $this->db->beginTransaction();

            // Un socio solo puede tener un mandato activo: firmar uno nuevo
            // revoca el anterior, que es lo que ocurre al cambiar de banco.
            $this->db->prepare(
                "UPDATE mandato_sepa SET estado = 'revocado' WHERE id_socio = :id AND estado = 'activo'" . $this->filtroSede()
            )->execute([':id' => $idSocio]);

            // La referencia definitiva necesita el id, que aún no existe: se
            // inserta con una provisional y se fija justo después.
            $provisional = 'TMP-' . uniqid('', true);

            $stmt = $this->db->prepare(
                "INSERT INTO mandato_sepa (id_socio, id_gimnasio, referencia, iban, fecha_firma, tipo)
                 VALUES (:id_socio, :id_gimnasio, :referencia, :iban, :fecha_firma, :tipo)"
            );
            $stmt->execute([
                ':id_socio'    => $idSocio,
                ':id_gimnasio' => $this->idGimnasio,
                ':referencia'  => mb_substr($provisional, 0, 35),
                ':iban'        => $iban,
                ':fecha_firma' => $fechaFirma,
                ':tipo'        => $tipo,
            ]);
            $idMandato = (int) $this->db->lastInsertId();

            $this->db->prepare(
                "UPDATE mandato_sepa SET referencia = :referencia WHERE id_mandato = :id"
            )->execute([
                ':referencia' => $this->generarReferencia($idSocio, $idMandato),
                ':id'         => $idMandato,
            ]);

            $this->db->commit();
            return $idMandato;
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('SepaModel::crearMandato error: ' . $e->getMessage());
            $error = 'No se pudo registrar el mandato.';
            return null;
        }
    }

    public function mandatoActivo(int $idSocio): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM mandato_sepa
             WHERE id_socio = :id AND estado = 'activo'" . $this->filtroSede() . "
             ORDER BY fecha_firma DESC LIMIT 1"
        );
        $stmt->execute([':id' => $idSocio]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function revocarMandato(int $idMandato): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE mandato_sepa SET estado = 'revocado' WHERE id_mandato = :id" . $this->filtroSede()
            );
            return $stmt->execute([':id' => $idMandato]);
        } catch (\PDOException $e) {
            error_log('SepaModel::revocarMandato error: ' . $e->getMessage());
            return false;
        }
    }

    public function listarMandatos(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT m.*, u.nombre, u.apellidos, u.email
                 FROM mandato_sepa m
                 INNER JOIN usuario u ON u.id_usuario = m.id_socio
                 WHERE 1 = 1" . $this->filtroSede('m') . "
                 ORDER BY m.estado ASC, u.apellidos ASC"
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log('SepaModel::listarMandatos error: ' . $e->getMessage());
            return [];
        }
    }

    /* --- Preparación de la remesa ----------------------------------------- */

    /**
     * Socios con mandato activo cuya última membresía se contrató por
     * transferencia y todavía no se ha incluido en ninguna remesa.
     */
    public function listarDomiciliablesPendientes(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT sm.id_socio_membresia, sm.id_socio, sm.nombre_tipo, sm.nombre_suplemento,
                        (sm.precio_pagado + sm.precio_suplemento) AS importe,
                        sm.fecha_inicio, sm.fecha_fin,
                        u.nombre, u.apellidos,
                        m.id_mandato, m.referencia, m.iban, m.fecha_firma, m.primer_cobro_hecho
                 FROM socio_membresia sm
                 INNER JOIN usuario u      ON u.id_usuario = sm.id_socio
                 INNER JOIN mandato_sepa m ON m.id_socio   = sm.id_socio AND m.estado = 'activo'
                 WHERE sm.metodo_pago = 'transferencia'
                   AND sm.es_prueba = 0
                   AND (sm.precio_pagado + sm.precio_suplemento) > 0" . $this->filtroSede('sm') . "
                   AND NOT EXISTS (
                       SELECT 1 FROM remesa_recibo rr
                       WHERE rr.id_socio_membresia = sm.id_socio_membresia
                         AND rr.estado <> 'devuelto'
                   )
                 ORDER BY u.apellidos ASC, u.nombre ASC"
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log('SepaModel::listarDomiciliablesPendientes error: ' . $e->getMessage());
            return [];
        }
    }

    /* --- Remesas ---------------------------------------------------------- */

    /**
     * Crea la remesa con los cobros indicados. Todo dentro de una transacción:
     * una remesa a medias enviada al banco sería un problema serio.
     *
     * Devuelve el id de la remesa, o null dejando el motivo en $error.
     */
    public function crearRemesa(
        array $idsMembresia,
        string $concepto,
        string $fechaCobro,
        ?int $idUsuarioCreador,
        string &$error,
        ?string $idempotencyKey = null
    ): ?int {
        if ($idempotencyKey !== null) {
            $stmt = $this->db->prepare('SELECT id_remesa FROM remesa WHERE id_gimnasio = :sede AND idempotency_key = :clave LIMIT 1');
            $stmt->execute([':sede' => $this->idGimnasio, ':clave' => $idempotencyKey]);
            $existente = (int) $stmt->fetchColumn();
            if ($existente > 0) return $existente;
        }
        $candidatos = [];
        foreach ($this->listarDomiciliablesPendientes() as $fila) {
            $candidatos[(int) $fila['id_socio_membresia']] = $fila;
        }

        $seleccion = [];
        foreach ($idsMembresia as $id) {
            $id = (int) $id;
            if (isset($candidatos[$id])) $seleccion[] = $candidatos[$id];
        }

        if (empty($seleccion)) {
            $error = 'No hay cobros seleccionados que se puedan domiciliar.';
            return null;
        }

        try {
            $this->db->beginTransaction();

            $stmtRemesa = $this->db->prepare(
                "INSERT INTO remesa (id_gimnasio, concepto, fecha_cobro, id_usuario_creador, idempotency_key)
                 VALUES (:id_gimnasio, :concepto, :fecha_cobro, :creador, :idempotency_key)"
            );
            $stmtRemesa->execute([
                ':id_gimnasio' => $this->idGimnasio,
                ':concepto'    => $concepto,
                ':fecha_cobro' => $fechaCobro,
                ':creador'     => $idUsuarioCreador ?: null,
                ':idempotency_key' => $idempotencyKey,
            ]);
            $idRemesa = (int) $this->db->lastInsertId();

            $stmtRecibo = $this->db->prepare(
                "INSERT INTO remesa_recibo
                 (id_remesa, id_socio, id_socio_membresia, nombre_socio, referencia_mandato,
                  fecha_firma_mandato, iban, importe, concepto, secuencia)
                 VALUES
                 (:id_remesa, :id_socio, :id_membresia, :nombre, :referencia,
                  :firma, :iban, :importe, :concepto, :secuencia)"
            );

            $totalCents = 0;
            foreach ($seleccion as $s) {
                // Primer adeudo del mandato → FRST; los siguientes → RCUR.
                $secuencia = empty($s['primer_cobro_hecho']) ? 'FRST' : 'RCUR';
                $importeCents = Money::cents($s['importe']);
                $importe = Money::decimal($importeCents);
                $totalCents += $importeCents;

                $detalle = $s['nombre_tipo']
                    . (!empty($s['nombre_suplemento']) ? ' + ' . $s['nombre_suplemento'] : '');

                $stmtRecibo->execute([
                    ':id_remesa'    => $idRemesa,
                    ':id_socio'     => (int) $s['id_socio'],
                    ':id_membresia' => (int) $s['id_socio_membresia'],
                    ':nombre'       => trim($s['nombre'] . ' ' . $s['apellidos']),
                    ':referencia'   => $s['referencia'],
                    ':firma'        => $s['fecha_firma'],
                    ':iban'         => $s['iban'],
                    ':importe'      => $importe,
                    ':concepto'     => $concepto . ' - ' . $detalle,
                    ':secuencia'    => $secuencia,
                ]);
            }

            $this->db->prepare(
                "UPDATE remesa SET importe_total = :total, num_recibos = :num WHERE id_remesa = :id"
            )->execute([':total' => Money::decimal($totalCents), ':num' => count($seleccion), ':id' => $idRemesa]);

            $this->db->commit();
            return $idRemesa;

        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('SepaModel::crearRemesa error: ' . $e->getMessage());
            $error = 'No se pudo crear la remesa.';
            return null;
        }
    }

    public function listarRemesas(int $limite = 50): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT r.*, u.nombre AS creador_nombre, u.apellidos AS creador_apellidos
                 FROM remesa r
                 LEFT JOIN usuario u ON u.id_usuario = r.id_usuario_creador
                 WHERE 1 = 1" . $this->filtroSede('r') . "
                 ORDER BY r.created_at DESC
                 LIMIT :limite"
            );
            $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log('SepaModel::listarRemesas error: ' . $e->getMessage());
            return [];
        }
    }

    public function buscarRemesa(int $idRemesa): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM remesa WHERE id_remesa = :id" . $this->filtroSede() . " LIMIT 1"
        );
        $stmt->execute([':id' => $idRemesa]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listarRecibos(int $idRemesa): array
    {
        $stmt = $this->db->prepare(
            "SELECT rr.* FROM remesa_recibo rr
             INNER JOIN remesa r ON r.id_remesa = rr.id_remesa
             WHERE rr.id_remesa = :id" . $this->filtroSede('r') . " ORDER BY rr.nombre_socio ASC"
        );
        $stmt->execute([':id' => $idRemesa]);
        return $stmt->fetchAll();
    }

    /**
     * Marca la remesa como enviada al banco y da por hecho el primer adeudo de
     * cada mandato, para que los siguientes viajen como RCUR.
     */
    public function marcarEnviada(int $idRemesa): bool
    {
        try {
            $this->db->beginTransaction();

            $stmtRemesa = $this->db->prepare(
                "UPDATE remesa SET estado = 'enviada' WHERE id_remesa = :id AND estado = 'borrador'" . $this->filtroSede()
            );
            $stmtRemesa->execute([':id' => $idRemesa]);
            if ($stmtRemesa->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $this->db->prepare(
                "UPDATE mandato_sepa m
                 INNER JOIN remesa_recibo rr ON rr.referencia_mandato = m.referencia
                 SET m.primer_cobro_hecho = 1
                 WHERE rr.id_remesa = :id"
            )->execute([':id' => $idRemesa]);

            $this->db->commit();
            return true;
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('SepaModel::marcarEnviada error: ' . $e->getMessage());
            return false;
        }
    }

    public function marcarCobrada(int $idRemesa): bool
    {
        try {
            $this->db->beginTransaction();
            $stmtRemesa = $this->db->prepare(
                "UPDATE remesa SET estado = 'cobrada' WHERE id_remesa = :id AND estado = 'enviada'" . $this->filtroSede()
            );
            $stmtRemesa->execute([':id' => $idRemesa]);
            if ($stmtRemesa->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }
            // Solo pasan a cobrados los que no se hayan devuelto.
            $this->db->prepare(
                "UPDATE remesa_recibo SET estado = 'cobrado', fecha_estado = NOW()
                 WHERE id_remesa = :id AND estado = 'pendiente'"
            )->execute([':id' => $idRemesa]);
            $this->db->commit();
            return true;
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('SepaModel::marcarCobrada error: ' . $e->getMessage());
            return false;
        }
    }

    /* --- Devoluciones ------------------------------------------------------ */

    /** Un recibo devuelto vuelve a quedar pendiente de cobro para la próxima remesa. */
    public function marcarDevuelto(int $idRecibo, string $motivo): bool
    {
        try {
            // El recibo no lleva sede: la hereda de su remesa, y por ahí se
            // comprueba que sea de este gimnasio antes de tocarlo.
            $stmt = $this->db->prepare(
                "UPDATE remesa_recibo rr
                 INNER JOIN remesa r ON r.id_remesa = rr.id_remesa
                 SET rr.estado = 'devuelto', rr.motivo_devolucion = :motivo, rr.fecha_estado = NOW()
                 WHERE rr.id_recibo = :id" . $this->filtroSede('r')
            );
            $stmt->execute([':motivo' => mb_substr($motivo, 0, 255), ':id' => $idRecibo]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log('SepaModel::marcarDevuelto error: ' . $e->getMessage());
            return false;
        }
    }

    public function listarDevueltos(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT rr.*, r.fecha_cobro, r.concepto AS concepto_remesa
                 FROM remesa_recibo rr
                 INNER JOIN remesa r ON r.id_remesa = rr.id_remesa
                 WHERE rr.estado = 'devuelto'" . $this->filtroSede('r') . "
                 ORDER BY rr.fecha_estado DESC"
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log('SepaModel::listarDevueltos error: ' . $e->getMessage());
            return [];
        }
    }
}
