<?php

require_once dirname(__DIR__, 2) . '/app/models/GimnasioModel.php';
require_once dirname(__DIR__, 2) . '/app/models/UserModel.php';
require_once dirname(__DIR__, 2) . '/app/models/MembresiaModel.php';
require_once dirname(__DIR__, 2) . '/app/models/ProductoModel.php';
require_once dirname(__DIR__, 2) . '/app/models/CashModel.php';
require_once dirname(__DIR__, 2) . '/app/models/VentaModel.php';

/**
 * Fixture efímera para demostrar que un segundo cliente puede operar sin
 * reutilizar ids, nombres ni datos de Cleto. Solo se carga con APP_ENV=test.
 *
 * La empresa y su primer usuario de dirección se insertan aquí porque la
 * aplicación todavía no tiene un servicio de aprovisionamiento de tenants.
 * El resto se crea a través de los modelos productivos existentes.
 */
final class DemoGymFactory
{
    private const COMPANY_NAME = 'TEST F12 Gimnasio Demo Norte';

    public static function cleanup(PDO $db): void
    {
        $stmt = $db->prepare('SELECT id_empresa FROM empresa WHERE nombre = :nombre');
        $stmt->execute([':nombre' => self::COMPANY_NAME]);
        $empresaIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($empresaIds as $empresaId) {
            $db->exec("DELETE FROM caja_movimiento WHERE id_empresa = {$empresaId}");
            $db->exec(
                "DELETE vl FROM venta_linea vl INNER JOIN venta v ON v.id_venta = vl.id_venta "
                . "INNER JOIN gimnasio g ON g.id_gimnasio = v.id_gimnasio WHERE g.id_empresa = {$empresaId}"
            );
            $db->exec(
                "DELETE v FROM venta v INNER JOIN gimnasio g ON g.id_gimnasio = v.id_gimnasio "
                . "WHERE g.id_empresa = {$empresaId}"
            );
            $db->exec("DELETE FROM caja_sesion WHERE id_empresa = {$empresaId}");
            $db->exec(
                "DELETE p FROM producto p INNER JOIN gimnasio g ON g.id_gimnasio = p.id_gimnasio "
                . "WHERE g.id_empresa = {$empresaId}"
            );
            $db->exec("DELETE FROM tipo_membresia WHERE id_empresa = {$empresaId}");
            $db->exec("DELETE FROM log_actividad WHERE id_empresa = {$empresaId}");
            $db->exec("DELETE FROM usuario WHERE id_empresa = {$empresaId}");
            $db->exec("DELETE FROM gimnasio WHERE id_empresa = {$empresaId}");
            $db->exec("DELETE FROM empresa WHERE id_empresa = {$empresaId}");
        }
    }

    public static function create(PDO $db): array
    {
        if (APP_ENV !== 'test') {
            throw new RuntimeException('Gimnasio Demo Norte solo puede crearse en APP_ENV=test.');
        }
        self::cleanup($db);

        $stmtEmpresa = $db->prepare(
            "INSERT INTO empresa (nombre, nombre_comercial, email, telefono, estado, configuracion)\n"
            . "VALUES (:nombre, :comercial, :email, :telefono, 'activa', :configuracion)"
        );
        $stmtEmpresa->execute([
            ':nombre' => self::COMPANY_NAME,
            ':comercial' => 'Gimnasio Demo Norte',
            ':email' => 'direccion.demo.norte@example.invalid',
            ':telefono' => '600000120',
            ':configuracion' => json_encode(['fixture' => 'fase12'], JSON_THROW_ON_ERROR),
        ]);
        $empresaId = (int) $db->lastInsertId();

        $gimnasios = new GimnasioModel($empresaId);
        $sedeCentro = $gimnasios->crear([
            'nombre' => 'Demo Norte Centro',
            'razon_social' => 'Gimnasio Demo Norte SL',
            'cif' => 'B12000001',
            'direccion' => 'Calle Sintética 1',
            'telefono' => '600000121',
            'email' => 'centro.demo.norte@example.invalid',
        ]);
        $sedeRio = $gimnasios->crear([
            'nombre' => 'Demo Norte Río',
            'razon_social' => 'Gimnasio Demo Norte SL',
            'cif' => 'B12000001',
            'direccion' => 'Calle Sintética 2',
            'telefono' => '600000122',
            'email' => 'rio.demo.norte@example.invalid',
        ]);
        if (!$sedeCentro || !$sedeRio) {
            throw new RuntimeException('No se pudieron crear las dos sedes sintéticas.');
        }

        $stmtDireccion = $db->prepare(
            "INSERT INTO usuario\n"
            . "(id_empresa, id_gimnasio, nombre, apellidos, dni, telefono, email, nombre_usuario, contrasena, rol, activo)\n"
            . "VALUES (:empresa, NULL, 'Dirección', 'Demo Norte', 'F12DIR0001', '600000123',\n"
            . "'dir.demo.norte@example.invalid', 'f12_demo_norte_dir', :clave, 'direccion', 1)"
        );
        $stmtDireccion->execute([
            ':empresa' => $empresaId,
            ':clave' => password_hash('synthetic-only', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $direccionId = (int) $db->lastInsertId();

        $usuarios = new UserModel($sedeCentro, $empresaId);
        $recepcionId = $usuarios->crearEmpleado(
            'Recepción', 'Demo Norte', 'F12REC0001',
            'recepcion.demo.norte@example.invalid', '600000124',
            'f12_demo_norte_recepcion', 'synthetic-only', 'recepcion', $sedeCentro
        );
        $socioUnoCreado = $usuarios->crear(
            'Socio', 'Demo Uno', 'F12SOC0001', '600000125',
            'socio.uno.demo.norte@example.invalid', 'f12_demo_norte_socio_1', 'synthetic-only'
        );
        $socioDosCreado = $usuarios->crear(
            'Socia', 'Demo Dos', 'F12SOC0002', '600000126',
            'socio.dos.demo.norte@example.invalid', 'f12_demo_norte_socio_2', 'synthetic-only'
        );
        if (!$recepcionId || !$socioUnoCreado || !$socioDosCreado) {
            throw new RuntimeException('No se pudieron crear los usuarios sintéticos.');
        }
        $socioUno = (int) $db->query("SELECT id_usuario FROM usuario WHERE nombre_usuario = 'f12_demo_norte_socio_1'")->fetchColumn();
        $socioDos = (int) $db->query("SELECT id_usuario FROM usuario WHERE nombre_usuario = 'f12_demo_norte_socio_2'")->fetchColumn();

        $membresias = new MembresiaModel($sedeCentro, $empresaId);
        if (!$membresias->crearTipo('Mensual Demo Norte', 'Tarifa sintética F12', 39.95, 1, 'activo')) {
            throw new RuntimeException('No se pudo crear la tarifa sintética.');
        }
        $tarifaId = (int) $db->query(
            "SELECT id_tipo_membresia FROM tipo_membresia WHERE id_empresa = {$empresaId} "
            . "AND nombre = 'Mensual Demo Norte'"
        )->fetchColumn();

        $productos = new ProductoModel($sedeCentro, $empresaId);
        if (!$productos->crear('Agua Demo Norte', 'Producto sintético F12', 1.50, 10, 2, 'activo', null, 10.0)) {
            throw new RuntimeException('No se pudo crear el producto sintético.');
        }
        $productoId = (int) $db->query(
            "SELECT id_producto FROM producto WHERE id_gimnasio = {$sedeCentro} AND nombre = 'Agua Demo Norte'"
        )->fetchColumn();

        $caja = new CashModel($sedeCentro, $empresaId);
        $error = '';
        $sesionCajaId = $caja->abrir('50.00', (int) $recepcionId, $error);
        if (!$sesionCajaId) {
            throw new RuntimeException('No se pudo abrir la caja sintética: ' . $error);
        }

        $ventas = new VentaModel($sedeCentro, $empresaId);
        $ventaId = $ventas->registrar(
            [['id_producto' => $productoId, 'cantidad' => 2]],
            $socioUno,
            'efectivo',
            (int) $recepcionId,
            $error,
            'f12-demo-norte-sale-00000001'
        );
        if (!$ventaId) {
            throw new RuntimeException('No se pudo registrar la venta sintética: ' . $error);
        }

        return [
            'empresa' => $empresaId,
            'sedes' => [(int) $sedeCentro, (int) $sedeRio],
            'direccion' => $direccionId,
            'recepcion' => (int) $recepcionId,
            'socios' => [$socioUno, $socioDos],
            'tarifa' => $tarifaId,
            'producto' => $productoId,
            'sesion_caja' => (int) $sesionCajaId,
            'venta' => (int) $ventaId,
            'aprovisionamiento_sql' => ['empresa', 'direccion'],
        ];
    }
}
