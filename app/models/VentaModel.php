<?php
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

    private const METODOS_VALIDOS = ['efectivo', 'datafono', 'transferencia'];

    /** Ver ProductoModel::__construct para el criterio de aislamiento por sede. */
    public function __construct(?int $idGimnasio = null)
    {
        $this->db = Database::getInstance()->getConnection();
        $this->idGimnasio = $idGimnasio;
    }

    /** Serie de facturación. Una sola por ahora; la columna admite más. */
    private const SERIE = 'A';

    private function filtroSede(string $alias = 'v'): string
    {
        if ($this->idGimnasio === null) return '';
        $prefijo = $alias === '' ? '' : $alias . '.';
        return ' AND ' . $prefijo . 'id_gimnasio = ' . (int) $this->idGimnasio;
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
        string &$error
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
                 (id_socio, id_usuario_registro, metodo_pago, total, id_gimnasio, serie, ejercicio, numero)
                 VALUES
                 (:id_socio, :id_usuario_registro, :metodo_pago, 0.00, :id_gimnasio, :serie, :ejercicio, :numero)"
            );
            $stmtVenta->execute([
                ':id_socio'            => $idSocio ?: null,
                ':id_usuario_registro' => $idUsuarioRegistro ?: null,
                ':metodo_pago'         => $metodoPago,
                ':id_gimnasio'         => $this->idGimnasio,
                ':serie'               => self::SERIE,
                ':ejercicio'           => $ejercicio,
                ':numero'              => $numero,
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

            $total = 0.00;
            $totalBase = 0.00;
            $totalIva  = 0.00;

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
                $precio   = (float) $producto['precio'];
                $iva      = (float) $producto['iva'];
                $subtotal = round($precio * $cantidad, 2);
                $base     = round($subtotal / (1 + $iva / 100), 2);
                $cuota    = round($subtotal - $base, 2);

                $total     += $subtotal;
                $totalBase += $base;
                $totalIva  += $cuota;

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
                ':total' => $total,
                ':base'  => $totalBase,
                ':iva'   => $totalIva,
                ':id'    => $idVenta,
            ]);

            $this->db->commit();
            return $idVenta;

        } catch (\PDOException $e) {
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
            "SELECT * FROM venta_linea WHERE id_venta = :id ORDER BY id_linea ASC"
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
        } catch (\PDOException $e) {
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

        try {
            $this->db->beginTransaction();

            $lineas = $this->listarLineas($idVenta);
            $stmtDevolver = $this->db->prepare(
                "UPDATE producto SET stock = stock + :cantidad WHERE id_producto = :id"
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
                $this->db->commit();
                return true;
            }
            $this->db->rollBack();
            return false;
        } catch (\PDOException $e) {
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
