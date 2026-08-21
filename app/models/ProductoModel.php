<?php
/**
 * ProductoModel — acceso a la tabla `producto`.
 *
 * Secciones de este archivo (en orden):
 *   1. Constructor                  (__construct)
 *   2. Alta y edición               (crear, actualizar, actualizarImagen)
 *   3. Búsqueda y listados          (buscarPorId, listarTodos, listarActivos, listarCategorias)
 *   4. Conteos y estadísticas       (contarTodos, contarActivos, valorInventario)
 *   5. Estado activo/inactivo       (cambiarEstado, toggleEstado)
 *   6. Control de stock             (actualizarStock, listarBajoStock, contarBajoStock)
 *
 * El descuento de stock por venta NO está aquí: vive en VentaModel::registrar(),
 * dentro de la misma transacción que la venta.
 */

require_once __DIR__ . '/../config/database.php';

class ProductoModel
{
    private $db;
    private $tabla = 'producto';
    private $idGimnasio;
    private $idEmpresa;

    /**
     * $idGimnasio limita todo el modelo a una sede. null solo lo usa el rol
     * la empresa para ver el conjunto: cualquier otro rol debe pasar siempre
     * su gimnasio, porque el filtro se aplica aquí y no en cada consulta.
     */
    public function __construct(?int $idGimnasio = null, ?int $idEmpresa = null)
    {
        $this->db = Database::getInstance()->getConnection();
        $this->idGimnasio = $idGimnasio;
        $this->idEmpresa = $idEmpresa;
    }

    /**
     * Cláusula de sede lista para concatenar. El id va casteado a int.
     * Con $alias vacío sirve para los UPDATE, que no llevan alias de tabla.
     *
     * Se aplica también a las escrituras: sin ella, manipulando el id de un
     * formulario se podría modificar un producto de otra sede.
     */
    private function filtroSede(string $alias = 'p'): string
    {
        $prefijo = $alias === '' ? '' : $alias . '.';
        if ($this->idGimnasio !== null) {
            return ' AND ' . $prefijo . 'id_gimnasio = ' . (int) $this->idGimnasio;
        }
        if ($this->idEmpresa !== null) {
            return ' AND ' . $prefijo . 'id_gimnasio IN (SELECT id_gimnasio FROM gimnasio WHERE id_empresa = '
                . (int) $this->idEmpresa . ')';
        }
        return '';
    }

    /**
     * $precio es el PVP, con el IVA ya incluido: es lo que se teclea en la
     * etiqueta y lo que paga el cliente. El desglose lo calcula la venta.
     */
    public function crear(
        string $nombre,
        ?string $descripcion,
        string $precio,
        int $stock,
        int $stockMinimo,
        string $estado,
        ?int $idCategoria,
        float $iva = 21.0
    ): bool {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO {$this->tabla}
                 (nombre, descripcion, precio, iva, stock, stock_minimo, estado, id_categoria, id_gimnasio)
                 VALUES
                 (:nombre, :descripcion, :precio, :iva, :stock, :stock_minimo, :estado, :id_categoria, :id_gimnasio)"
            );
            return $stmt->execute([
                ':nombre'       => $nombre,
                ':descripcion'  => $descripcion,
                ':precio'       => $precio,
                ':iva'          => $iva,
                ':stock'        => $stock,
                ':stock_minimo' => $stockMinimo,
                ':estado'       => $estado,
                ':id_categoria' => $idCategoria,
                ':id_gimnasio'  => $this->idGimnasio,
            ]);
        } catch (\PDOException $e) {
            error_log('ProductoModel::crear error: ' . $e->getMessage());
            return false;
        }
    }

    public function actualizar(
        int $idProducto,
        string $nombre,
        ?string $descripcion,
        string $precio,
        int $stockMinimo,
        string $estado,
        ?int $idCategoria,
        float $iva = 21.0
    ): bool {
        try {
            $stmt = $this->db->prepare(
                "UPDATE {$this->tabla} SET
                    nombre       = :nombre,
                    descripcion  = :descripcion,
                    precio       = :precio,
                    iva          = :iva,
                    stock_minimo = :stock_minimo,
                    estado       = :estado,
                    id_categoria = :id_categoria
                 WHERE id_producto = :id" . $this->filtroSede('')
            );
            $ok = $stmt->execute([
                ':nombre'       => $nombre,
                ':descripcion'  => $descripcion,
                ':precio'       => $precio,
                ':iva'          => $iva,
                ':stock_minimo' => $stockMinimo,
                ':estado'       => $estado,
                ':id_categoria' => $idCategoria,
                ':id'           => $idProducto,
            ]);
            if (!$ok) return false;
            $verificar = $this->db->prepare("SELECT 1 FROM {$this->tabla} WHERE id_producto = :id" . $this->filtroSede('') . ' LIMIT 1');
            $verificar->execute([':id' => $idProducto]);
            return (bool) $verificar->fetchColumn();
        } catch (\PDOException $e) {
            error_log('ProductoModel::actualizar error: ' . $e->getMessage());
            return false;
        }
    }

    public function actualizarImagen(int $idProducto, ?string $nombreArchivo): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE {$this->tabla} SET imagen = :img WHERE id_producto = :id" . $this->filtroSede('')
            );
            return $stmt->execute([':img' => $nombreArchivo, ':id' => $idProducto]);
        } catch (\PDOException $e) {
            error_log('ProductoModel::actualizarImagen error: ' . $e->getMessage());
            return false;
        }
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT p.*, c.nombre_categoria
             FROM {$this->tabla} p
             LEFT JOIN categoria_producto c ON p.id_categoria = c.id_categoria
             WHERE p.id_producto = :id" . $this->filtroSede() . " LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listarTodos(string $busqueda = ''): array
    {
        if ($busqueda !== '') {
            $b = '%' . $busqueda . '%';
            $stmt = $this->db->prepare(
                "SELECT p.*, c.nombre_categoria
                 FROM {$this->tabla} p
                 LEFT JOIN categoria_producto c ON p.id_categoria = c.id_categoria
                 WHERE (p.nombre LIKE :b1
                    OR p.descripcion LIKE :b2
                    OR c.nombre_categoria LIKE :b3)" . $this->filtroSede() . "
                 ORDER BY p.nombre ASC"
            );
            $stmt->execute([':b1' => $b, ':b2' => $b, ':b3' => $b]);
            return $stmt->fetchAll();
        }
        return $this->db->query(
            "SELECT p.*, c.nombre_categoria
             FROM {$this->tabla} p
             LEFT JOIN categoria_producto c ON p.id_categoria = c.id_categoria
             WHERE 1 = 1" . $this->filtroSede() . "
             ORDER BY p.nombre ASC"
        )->fetchAll();
    }

    /** Productos vendibles: activos y con existencias. Alimenta el desplegable de venta rápida. */
    public function listarActivos(): array
    {
        return $this->db->query(
            "SELECT p.*, c.nombre_categoria
             FROM {$this->tabla} p
             LEFT JOIN categoria_producto c ON p.id_categoria = c.id_categoria
             WHERE p.estado = 'activo' AND p.stock > 0" . $this->filtroSede() . "
             ORDER BY p.nombre ASC"
        )->fetchAll();
    }

    public function listarCategorias(): array
    {
        return $this->db->query(
            "SELECT id_categoria, nombre_categoria
             FROM categoria_producto
             ORDER BY nombre_categoria ASC"
        )->fetchAll();
    }

    public function contarTodos(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM {$this->tabla} p WHERE 1 = 1" . $this->filtroSede()
        )->fetchColumn();
    }

    public function contarActivos(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM {$this->tabla} p WHERE p.estado = 'activo'" . $this->filtroSede()
        )->fetchColumn();
    }

    /** Valor del inventario a precio de venta. */
    public function valorInventario(): float
    {
        try {
            return (float) $this->db->query(
                "SELECT COALESCE(SUM(p.precio * p.stock), 0) FROM {$this->tabla} p
                 WHERE p.estado = 'activo'" . $this->filtroSede()
            )->fetchColumn();
        } catch (\PDOException $e) {
            error_log('ProductoModel::valorInventario error: ' . $e->getMessage());
            return 0.0;
        }
    }

    public function cambiarEstado(int $idProducto, string $nuevoEstado): bool
    {
        if (!in_array($nuevoEstado, ['activo', 'inactivo'], true)) {
            return false;
        }
        try {
            $stmt = $this->db->prepare(
                "UPDATE {$this->tabla} SET estado = :estado WHERE id_producto = :id" . $this->filtroSede('')
            );
            return $stmt->execute([':estado' => $nuevoEstado, ':id' => $idProducto]);
        } catch (\PDOException $e) {
            error_log('ProductoModel::cambiarEstado error: ' . $e->getMessage());
            return false;
        }
    }

    public function toggleEstado(int $idProducto): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE {$this->tabla}
                 SET estado = IF(estado = 'activo', 'inactivo', 'activo')
                 WHERE id_producto = :id" . $this->filtroSede('')
            );
            $stmt->execute([':id' => $idProducto]);
            return $stmt->rowCount() === 1;
        } catch (\PDOException $e) {
            error_log('ProductoModel::toggleEstado error: ' . $e->getMessage());
            return false;
        }
    }

    /** Fija el stock a un valor absoluto (reposición manual desde el panel). */
    public function actualizarStock(int $idProducto, int $stock): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE {$this->tabla} SET stock = :stock WHERE id_producto = :id" . $this->filtroSede('')
            );
            if (!$stmt->execute([':stock' => max(0, $stock), ':id' => $idProducto])) return false;
            $verificar = $this->db->prepare("SELECT stock FROM {$this->tabla} WHERE id_producto = :id" . $this->filtroSede('') . ' LIMIT 1');
            $verificar->execute([':id' => $idProducto]);
            $guardado = $verificar->fetchColumn();
            return $guardado !== false && (int) $guardado === max(0, $stock);
        } catch (\PDOException $e) {
            error_log('ProductoModel::actualizarStock error: ' . $e->getMessage());
            return false;
        }
    }

    /** Productos activos cuyo stock ha caído a su umbral o por debajo. */
    public function listarBajoStock(): array
    {
        try {
            return $this->db->query(
                "SELECT p.*, c.nombre_categoria
                 FROM {$this->tabla} p
                 LEFT JOIN categoria_producto c ON p.id_categoria = c.id_categoria
                 WHERE p.estado = 'activo' AND p.stock <= p.stock_minimo" . $this->filtroSede() . "
                 ORDER BY p.stock ASC, p.nombre ASC"
            )->fetchAll();
        } catch (\PDOException $e) {
            error_log('ProductoModel::listarBajoStock error: ' . $e->getMessage());
            return [];
        }
    }

    public function contarBajoStock(): int
    {
        try {
            return (int) $this->db->query(
                "SELECT COUNT(*) FROM {$this->tabla} p
                 WHERE p.estado = 'activo' AND p.stock <= p.stock_minimo" . $this->filtroSede()
            )->fetchColumn();
        } catch (\PDOException $e) {
            error_log('ProductoModel::contarBajoStock error: ' . $e->getMessage());
            return 0;
        }
    }
}
