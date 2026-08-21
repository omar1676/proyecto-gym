<?php
require_once dirname(__DIR__) . '/helpers/Money.php';
require_once dirname(__DIR__) . '/services/CashMovementRecorder.php';
/**
 * VentaModel — acceso a las tablas `venta` y `venta_linea`.
 *
 * Secciones de este archivo (en orden):
 *   1. Constructor                  (__construct)
 *   2. Registro de ventas           (registrar)
 *   3. Listados                     (listarPorRango, listarDelDia, listarLineas, buscarPorId)
 *   4. Totales para reportes        (sumarDelDia, sumarDelMes, contarDelDia, sumarPorMetodoPago)
 *   5. Ranking de productos         (topProductos)
 *   6. Anulación                    (anular)
 *
 * El descuento de stock ocurre dentro de registrar(), en la misma transacción
 * que la venta: o se guardan venta + líneas + stock, o no se guarda nada.
 */

require_once __DIR__ . '/../config/database.php';

class VentaModel
{
    private $db;
    private $tabla = 'venta';
    private $idGimnasio;
    private $idEmpresa;

    private const METODOS_VALIDOS = ['efectivo', 'datafono', 'transferencia'];

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

    /** Serie de facturación. Una sola por ahora; la columna admite más. */
    private const SERIE = 'A';

    private function filtroSede(string $alias = 'v'): string
    {
        $prefijo = $alias === '' ? '' : $alias . '.';
        if ($this->idGimnasio !== null) return ' AND ' . $prefijo . 'id_gimnasio = ' . (int) $this->idGimnasio;
        if ($this->idEmpresa !== null) {
            return ' AND ' . $prefijo . 'id_gimnasio IN (SELECT id_gimnasio FROM gimnasio WHERE id_empresa = '
                . (int) $this->idEmpresa . ')';
        }
        return '';
    }

    /**
     * Las ventas anuladas siguen existiendo (no se borran nunca), pero no
     * suman: ni en la caja del día, ni en los informes, ni en el ranking de
     * productos. Este filtro es el que las deja fuera de las cuentas.
     */
    private function soloActivas(string $alias = 'v'): string
    {
        $prefijo = $alias === '' ? '' : $alias . '.';
        return " AND " . $prefijo . "estado = 'activa'";
    }

    private function usuarioEnAmbito(int $idUsuario, ?string $rol = null): bool
    {
        $sql = 'SELECT 1 FROM usuario WHERE id_usuario = :id';
        if ($rol !== null) $sql .= ' AND rol = :rol';
        $sql .= $this->filtroSede('') . ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $params = [':id' => $idUsuario];
        if ($rol !== null) $params[':rol'] = $rol;
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Registra una venta completa y descuenta el stock de cada producto.
     *
     * $lineas es un array de ['id_producto' => int, 'cantidad' => int]. El precio
     * NO se toma del formulario sino de la base de datos, y se congela en la
     * línea para que los reportes históricos no cambien si luego sube el precio.
     *
     * Devuelve el id de la venta creada, o null dejando el motivo en $error.
     */
    public function registrar(
        array $lineas,
        ?int $idSocio,
        string $metodoPago,
        ?int $idUsuarioRegistro,
        string &$error,
        ?string $idempotencyKey = null
    ): ?int {
        if (!in_array($metodoPago, self::METODOS_VALIDOS, true)) {
            $error = 'Método de pago no válido.';
            return null;
        }

        // Normaliza y agrupa: si el mismo producto llega dos veces, se suman las
        // cantidades para no lanzar dos descuentos de stock sobre la misma fila.
        $items = [];
        foreach ($lineas as $linea) {
            $idProducto = (int) ($linea['id_producto'] ?? 0);
            $cantidad   = (int) ($linea['cantidad']    ?? 0);
            if ($idProducto <= 0 || $cantidad <= 0) continue;
            $items[$idProducto] = ($items[$idProducto] ?? 0) + $cantidad;
        }

        if (empty($items)) {
            $error = 'Añade al menos un producto con cantidad mayor que 0.';
            return null;
        }
        if ($idempotencyKey !== null) {
            $stmt = $this->db->prepare("SELECT id_venta FROM venta WHERE id_gimnasio = :sede AND idempotency_key = :clave LIMIT 1");
            $stmt->execute([':sede' => $this->idGimnasio, ':clave' => $idempotencyKey]);
            $existente = (int) $stmt->fetchColumn();
            if ($existente > 0) return $existente;
        }

        if ($idSocio && !$this->usuarioEnAmbito($idSocio, 'socio')) {
            $error = 'El socio no pertenece al ámbito activo.';
            return null;
        }
        if ($idUsuarioRegistro && $this->idEmpresa !== null) {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM usuario WHERE id_usuario = :id
                 AND (rol = 'superadmin' OR id_empresa = :empresa) LIMIT 1"
            );
            $stmt->execute([':id' => $idUsuarioRegistro, ':empresa' => $this->idEmpresa]);
            if (!$stmt->fetchColumn()) {
                $error = 'El usuario que registra la venta no pertenece al ámbito activo.';
                return null;
            }
        }

        try {
            $this->db->beginTransaction();

            // Número de ticket: correlativo por sede, serie y año. Se calcula
            // dentro de la transacción y con FOR UPDATE para que dos cajas
            // vendiendo a la vez no se lleven el mismo número.
            $ejercicio = (int) date('Y');
            $stmtNumero = $this->db->prepare(
                "SELECT COALESCE(MAX(numero), 0) + 1
                 FROM {$this->tabla}
                 WHERE id_gimnasio <=> :sede AND serie = :serie AND ejercicio = :ejercicio
                 FOR UPDATE"
            );
            $stmtNumero->execute([
                ':sede'      => $this->idGimnasio,
                ':serie'     => self::SERIE,
                ':ejercicio' => $ejercicio,
            ]);
            $numero = (int) $stmtNumero->fetchColumn();

            $stmtVenta = $this->db->prepare(
                "INSERT INTO {$this->tabla}
                 (id_socio, id_usuario_registro, metodo_pago, total, id_gimnasio, serie, ejercicio, numero, idempotency_key)
                 VALUES
                 (:id_socio, :id_usuario_registro, :metodo_pago, 0.00, :id_gimnasio, :serie, :ejercicio, :numero, :idempotency_key)"
            );
            $stmtVenta->execute([
                ':id_socio'            => $idSocio ?: null,
                ':id_usuario_registro' => $idUsuarioRegistro ?: null,
                ':metodo_pago'         => $metodoPago,
                ':id_gimnasio'         => $this->idGimnasio,
                ':serie'               => self::SERIE,
                ':ejercicio'           => $ejercicio,
                ':numero'              => $numero,
                ':idempotency_key'     => $idempotencyKey,
            ]);
            $idVenta = (int) $this->db->lastInsertId();

            // El filtro de sede impide vender stock de otro gimnasio aunque
            // llegue un id_producto manipulado desde el formulario.
            $stmtProducto = $this->db->prepare(
                "SELECT id_producto, nombre, precio, iva, stock, estado
                 FROM producto WHERE id_producto = :id" . $this->filtroSede('') . " LIMIT 1"
            );
            $stmtDescuento = $this->db->prepare(
                "UPDATE producto SET stock = stock - :cantidad
                 WHERE id_producto = :id AND stock >= :minimo" . $this->filtroSede('')
            );
            $stmtLinea = $this->db->prepare(
                "INSERT INTO venta_linea
                 (id_venta, id_producto, nombre_producto, cantidad, precio_unitario, iva,
                  subtotal, base_linea, cuota_iva)
                 VALUES
                 (:id_venta, :id_producto, :nombre_producto, :cantidad, :precio_unitario, :iva,
                  :subtotal, :base_linea, :cuota_iva)"
            );

            $totalCents = 0;
            $totalBaseCents = 0;
            $totalIvaCents  = 0;

            foreach ($items as $idProducto => $cantidad) {
                $stmtProducto->execute([':id' => $idProducto]);
                $producto = $stmtProducto->fetch();

                if (!$producto) {
                    $this->db->rollBack();
                    $error = 'Uno de los productos ya no existe.';
                    return null;
                }
                if ($producto['estado'] !== 'activo') {
                    $this->db->rollBack();
                    $error = 'El producto "' . $producto['nombre'] . '" está inactivo y no se puede vender.';
                    return null;
                }

                // El WHERE stock >= :minimo es la garantía real contra el stock
                // negativo: si otra caja vendió las últimas unidades entre la
                // lectura y esta línea, no afecta a ninguna fila y abortamos.
                $stmtDescuento->execute([
                    ':cantidad' => $cantidad,
                    ':id'       => $idProducto,
                    ':minimo'   => $cantidad,
                ]);
                if ($stmtDescuento->rowCount() === 0) {
                    $this->db->rollBack();
                    $error = 'Stock insuficiente de "' . $producto['nombre'] . '" (quedan ' . (int) $producto['stock'] . ').';
                    return null;
                }

                // El precio guardado es PVP con IVA incluido: es lo que se
                // teclea en el mostrador y lo que paga el cliente. La base se
                // saca hacia atrás, así el desglose nunca altera el cobro.
                $precioCents   = Money::cents($producto['precio']);
                $ivaBasis      = Money::cents($producto['iva']);
                $subtotalCents = $precioCents * $cantidad;
                $divisor       = 10000 + $ivaBasis;
                $baseCents     = intdiv(($subtotalCents * 10000) + intdiv($divisor, 2), $divisor);
                $cuotaCents    = $subtotalCents - $baseCents;
                $precio = Money::decimal($precioCents);
                $iva = $producto['iva'];
                $subtotal = Money::decimal($subtotalCents);
                $base = Money::decimal($baseCents);
                $cuota = Money::decimal($cuotaCents);

                $totalCents     += $subtotalCents;
                $totalBaseCents += $baseCents;
                $totalIvaCents  += $cuotaCents;

                $stmtLinea->execute([
                    ':id_venta'        => $idVenta,
                    ':id_producto'     => $idProducto,
                    ':nombre_producto' => $producto['nombre'],
                    ':cantidad'        => $cantidad,
                    ':precio_unitario' => $precio,
                    ':iva'             => $iva,
                    ':subtotal'        => $subtotal,
                    ':base_linea'      => $base,
                    ':cuota_iva'       => $cuota,
                ]);
            }

            $stmtTotal = $this->db->prepare(
                "UPDATE {$this->tabla}
                    SET total = :total, base_imponible = :base, total_iva = :iva
                  WHERE id_venta = :id"
            );
            $stmtTotal->execute([
                ':total' => Money::decimal($totalCents),
                ':base'  => Money::decimal($totalBaseCents),
                ':iva'   => Money::decimal($totalIvaCents),
                ':id'    => $idVenta,
            ]);

            if ($this->idEmpresa !== null && $this->idGimnasio !== null) {
                (new CashMovementRecorder((int) $this->idEmpresa, (int) $this->idGimnasio, $this->db))
                    ->registrar(
                        'venta', $metodoPago, $totalCents, $idVenta, null,
                        $idUsuarioRegistro, 'Venta ' . self::SERIE . '-' . $ejercicio . '-' . str_pad((string) $numero, 6, '0', STR_PAD_LEFT),
                        null, 'venta-' . $idVenta
                    );
            }

            $this->db->commit();
            return $idVenta;

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('VentaModel::registrar error: ' . $e->getMessage());
            $error = 'No se pudo registrar la venta. Inténtalo de nuevo.';
            return null;
        }
    }

    public function buscarPorId(int $idVenta): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT v.*, u.nombre AS nombre_socio, u.apellidos AS apellidos_socio
             FROM {$this->tabla} v
             LEFT JOIN usuario u ON v.id_socio = u.id_usuario
             WHERE v.id_venta = :id" . $this->filtroSede() . " LIMIT 1"
        );
        $stmt->execute([':id' => $idVenta]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listarLineas(int $idVenta): array
    {
        $stmt = $this->db->prepare(
            "SELECT vl.* FROM venta_linea vl
             INNER JOIN venta v ON v.id_venta = vl.id_venta
             WHERE vl.id_venta = :id" . $this->filtroSede('v') . " ORDER BY vl.id_linea ASC"
        );
        $stmt->execute([':id' => $idVenta]);
        return $stmt->fetchAll();
    }

    /** Ventas entre dos fechas (formato Y-m-d), ambas incluidas. */
    public function listarPorRango(string $desde, string $hasta): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT v.*, u.nombre AS nombre_socio, u.apellidos AS apellidos_socio,
                        (SELECT GROUP_CONCAT(CONCAT(l.cantidad, '× ', l.nombre_producto) SEPARATOR ', ')
                         FROM venta_linea l WHERE l.id_venta = v.id_venta) AS detalle
                 FROM {$this->tabla} v
                 LEFT JOIN usuario u ON v.id_socio = u.id_usuario
                 WHERE DATE(v.fecha) BETWEEN :desde AND :hasta" . $this->filtroSede() . "
                 ORDER BY v.fecha DESC"
            );
            $stmt->execute([':desde' => $desde, ':hasta' => $hasta]);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log('VentaModel::listarPorRango error: ' . $e->getMessage());
            return [];
        }
    }

    public function listarDelDia(): array
    {
        $hoy = date('Y-m-d');
        return $this->listarPorRango($hoy, $hoy);
    }

    public function sumarDelDia(): float
    {
        try {
            return (float) $this->db->query(
                "SELECT COALESCE(SUM(total), 0) FROM {$this->tabla} WHERE DATE(fecha) = CURDATE()" . $this->filtroSede('') . $this->soloActivas('')
            )->fetchColumn();
        } catch (\PDOException $e) {
            error_log('VentaModel::sumarDelDia error: ' . $e->getMessage());
            return 0.0;
        }
    }

    public function sumarDelMes(): float
    {
        try {
            return (float) $this->db->query(
                "SELECT COALESCE(SUM(total), 0) FROM {$this->tabla}
                 WHERE YEAR(fecha) = YEAR(CURDATE()) AND MONTH(fecha) = MONTH(CURDATE())" . $this->filtroSede('') . $this->soloActivas('')
            )->fetchColumn();
        } catch (\PDOException $e) {
            error_log('VentaModel::sumarDelMes error: ' . $e->getMessage());
            return 0.0;
        }
    }

    public function contarDelDia(): int
    {
        try {
            return (int) $this->db->query(
                "SELECT COUNT(*) FROM {$this->tabla} WHERE DATE(fecha) = CURDATE()" . $this->filtroSede('') . $this->soloActivas('')
            )->fetchColumn();
        } catch (\PDOException $e) {
            error_log('VentaModel::contarDelDia error: ' . $e->getMessage());
            return 0;
        }
    }

    /** Desglose del día por método de pago, para cuadrar la caja. */
    public function sumarPorMetodoPago(string $desde, string $hasta): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT metodo_pago, COUNT(*) AS num_ventas, COALESCE(SUM(total), 0) AS importe
                 FROM {$this->tabla}
                 WHERE DATE(fecha) BETWEEN :desde AND :hasta" . $this->filtroSede('') . $this->soloActivas('') . "
                 GROUP BY metodo_pago
                 ORDER BY importe DESC"
            );
            $stmt->execute([':desde' => $desde, ':hasta' => $hasta]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log('VentaModel::sumarPorMetodoPago error: ' . $e->getMessage());
            return [];
        }
    }

    /** Productos más vendidos en un rango de fechas. */
    public function topProductos(string $desde, string $hasta, int $limite = 5): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT l.nombre_producto,
                        SUM(l.cantidad) AS unidades,
                        SUM(l.subtotal) AS importe
                 FROM venta_linea l
                 INNER JOIN {$this->tabla} v ON v.id_venta = l.id_venta
                 WHERE DATE(v.fecha) BETWEEN :desde AND :hasta" . $this->filtroSede() . $this->soloActivas() . "
                 GROUP BY l.nombre_producto
                 ORDER BY unidades DESC
                 LIMIT :limite"
            );
            $stmt->bindValue(':desde',  $desde);
            $stmt->bindValue(':hasta',  $hasta);
            $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log('VentaModel::topProductos error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Anula una venta: devuelve el stock y la marca como anulada.
     *
     * NO se borra. Un registro de cobro que desaparece es un agujero en la
     * caja y, desde la ley antifraude, algo que el software directamente no
     * debe permitir. La venta se queda con su número, su fecha y su importe;
     * lo que cambia es el estado, y a partir de ahí no suma en ningún total.
     *
     * Devuelve false si la venta no es de esta sede o ya estaba anulada.
     */
    public function anular(int $idVenta, ?int $idUsuario = null, string $motivo = ''): bool
    {
        $venta = $this->buscarPorId($idVenta);
        if ($venta === null || ($venta['estado'] ?? 'activa') !== 'activa') {
            return false;
        }
        if ($idUsuario && $this->idEmpresa !== null) {
            $stmtUsuario = $this->db->prepare(
                "SELECT 1 FROM usuario WHERE id_usuario = :id
                 AND (rol = 'superadmin' OR id_empresa = :empresa) LIMIT 1"
            );
            $stmtUsuario->execute([':id' => $idUsuario, ':empresa' => $this->idEmpresa]);
            if (!$stmtUsuario->fetchColumn()) return false;
        }

        try {
            $this->db->beginTransaction();

            $lineas = $this->listarLineas($idVenta);
            $stmtDevolver = $this->db->prepare(
                "UPDATE producto SET stock = stock + :cantidad WHERE id_producto = :id" . $this->filtroSede('')
            );
            foreach ($lineas as $linea) {
                if (empty($linea['id_producto'])) continue;
                $stmtDevolver->execute([
                    ':cantidad' => (int) $linea['cantidad'],
                    ':id'       => (int) $linea['id_producto'],
                ]);
            }

            // El WHERE repite estado = 'activa' a propósito: si dos personas
            // pulsan anular a la vez, solo una de las dos devuelve el stock.
            $stmt = $this->db->prepare(
                "UPDATE {$this->tabla}
                    SET estado = 'anulada',
                        anulada_en = NOW(),
                        id_usuario_anulacion = :usuario,
                        motivo_anulacion = :motivo
                  WHERE id_venta = :id AND estado = 'activa'" . $this->filtroSede('')
            );
            $stmt->execute([
                ':usuario' => $idUsuario ?: null,
                ':motivo'  => $motivo !== '' ? mb_substr($motivo, 0, 255) : null,
                ':id'      => $idVenta,
            ]);
            $ok = $stmt->rowCount() > 0;

            if ($ok) {
                if ($this->idEmpresa !== null && $this->idGimnasio !== null) {
                    (new CashMovementRecorder((int) $this->idEmpresa, (int) $this->idGimnasio, $this->db))
                        ->registrar(
                            'anulacion_venta', (string) $venta['metodo_pago'], -Money::cents($venta['total']),
                            $idVenta, null, $idUsuario, 'Anulación ' . self::referencia($venta),
                            $motivo !== '' ? $motivo : null, 'anulacion-venta-' . $idVenta
                        );
                }
                $this->db->commit();
                return true;
            }
            $this->db->rollBack();
            return false;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('VentaModel::anular error: ' . $e->getMessage());
            return false;
        }
    }

    /** Número de ticket legible: A-2026-000042. */
    public static function referencia(array $venta): string
    {
        if (empty($venta['numero'])) {
            return '#' . (int) ($venta['id_venta'] ?? 0);
        }
        return ($venta['serie'] ?? 'A') . '-' . (int) $venta['ejercicio']
             . '-' . str_pad((string) (int) $venta['numero'], 6, '0', STR_PAD_LEFT);
    }
}
