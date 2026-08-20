<?php

require_once dirname(__DIR__) . '/app/config/database.php';
require_once dirname(__DIR__) . '/app/models/GimnasioModel.php';
require_once dirname(__DIR__) . '/app/models/UserModel.php';
require_once dirname(__DIR__) . '/app/models/MembresiaModel.php';
require_once dirname(__DIR__) . '/app/models/ProductoModel.php';
require_once dirname(__DIR__) . '/app/models/VentaModel.php';
require_once dirname(__DIR__) . '/app/models/CashModel.php';
require_once dirname(__DIR__) . '/app/models/SepaModel.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$args = array_slice($argv, 1);
$password = (string) getenv('STAGING_SEED_PASSWORD');
if (APP_ENV !== 'staging' || stripos(DB_NAME, 'staging') === false) {
    fwrite(STDERR, "Este sembrado solo puede ejecutarse en una base identificada como staging.\n");
    exit(1);
}
if (!in_array('--confirm-synthetic', $args, true)) {
    fwrite(STDERR, "Falta --confirm-synthetic.\n");
    exit(1);
}
if (ACCESS_CONTROL_MODE !== 'disabled') {
    fwrite(STDERR, "El control de acceso debe permanecer deshabilitado.\n");
    exit(1);
}
if (strlen($password) < 16) {
    fwrite(STDERR, "STAGING_SEED_PASSWORD debe tener al menos 16 caracteres y no se almacena en Git.\n");
    exit(1);
}

$db = Database::getInstance()->getConnection();
$counts = [];
foreach (['usuario', 'producto', 'venta', 'socio_membresia', 'remesa', 'caja_sesion'] as $table) {
    $counts[$table] = (int) $db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}
if (array_sum($counts) !== 0
    || (int) $db->query('SELECT COUNT(*) FROM empresa')->fetchColumn() !== 1
    || (int) $db->query('SELECT COUNT(*) FROM gimnasio')->fetchColumn() !== 1) {
    fwrite(STDERR, "La base no es una instalación staging recién migrada; no se modifica.\n");
    exit(1);
}

$started = microtime(true);
$times = [];
$measure = static function (string $name, callable $operation) use (&$times) {
    $start = microtime(true);
    $result = $operation();
    $times[$name] = round((microtime(true) - $start) * 1000, 2);
    return $result;
};

try {
    $empresaId = (int) $db->query('SELECT id_empresa FROM empresa LIMIT 1')->fetchColumn();
    $sedePrincipal = (int) $db->query('SELECT id_gimnasio FROM gimnasio LIMIT 1')->fetchColumn();

    $measure('empresa_y_sedes', function () use ($db, $empresaId, $sedePrincipal, &$sedeSecundaria): void {
        $db->prepare(
            "UPDATE empresa SET nombre = 'F13 Gimnasio Piloto Sintético',
             nombre_comercial = 'Piloto Sintético F13', email = NULL, telefono = NULL,
             configuracion = JSON_OBJECT('fixture', 'fase13', 'synthetic', TRUE)
             WHERE id_empresa = :empresa"
        )->execute([':empresa' => $empresaId]);
        $db->prepare(
            "UPDATE gimnasio SET nombre = 'F13 Sede Centro Sintética', slug = 'f13-centro-sintetica',
             razon_social = 'Entidad Sintética F13', cif = 'B00000000', direccion = 'Calle Ficticia 1',
             telefono = '600000001', email = 'centro.f13@example.invalid', email_acceso = NULL,
             contrasena_acceso = NULL, iban = NULL, bic = NULL, identificador_acreedor = NULL,
             logo = NULL WHERE id_gimnasio = :sede AND id_empresa = :empresa"
        )->execute([':sede' => $sedePrincipal, ':empresa' => $empresaId]);
        $sedeSecundaria = (new GimnasioModel($empresaId))->crear([
            'nombre' => 'F13 Sede Norte Sintética',
            'razon_social' => 'Entidad Sintética F13',
            'cif' => 'B00000000',
            'direccion' => 'Calle Ficticia 2',
            'telefono' => '600000002',
            'email' => 'norte.f13@example.invalid',
        ]);
        if (!$sedeSecundaria) throw new RuntimeException('No se pudo crear la segunda sede sintética.');
    });

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    $measure('usuarios', function () use ($db, $empresaId, $sedePrincipal, $sedeSecundaria, $password, $hash, &$direccionId, &$adminId, &$recepcionId, &$socios): void {
        $stmt = $db->prepare(
            "INSERT INTO usuario
             (id_empresa, id_gimnasio, nombre, apellidos, dni, telefono, email, nombre_usuario, contrasena, rol, activo)
             VALUES (:empresa, NULL, 'Dirección', 'Sintética F13', 'F13DIR0001', '600000010',
                     'direccion.f13@example.invalid', 'f13_direccion', :hash, 'direccion', 1)"
        );
        $stmt->execute([':empresa' => $empresaId, ':hash' => $hash]);
        $direccionId = (int) $db->lastInsertId();

        $users = new UserModel($sedePrincipal, $empresaId);
        $adminId = $users->crearEmpleado(
            'Administración', 'Sintética F13', 'F13ADM0001', 'admin.f13@example.invalid',
            '600000011', 'f13_admin', $password, 'admin', $sedePrincipal
        );
        $recepcionId = $users->crearEmpleado(
            'Recepción', 'Sintética F13', 'F13REC0001', 'recepcion.f13@example.invalid',
            '600000012', 'f13_recepcion', $password, 'recepcion', $sedePrincipal
        );
        $recepcionNorte = $users->crearEmpleado(
            'Recepción Norte', 'Sintética F13', 'F13REC0002', 'recepcion.norte.f13@example.invalid',
            '600000013', 'f13_recepcion_norte', $password, 'recepcion', $sedeSecundaria
        );
        if (!$adminId || !$recepcionId || !$recepcionNorte) throw new RuntimeException('No se pudo crear el personal sintético.');

        $socios = [];
        foreach ([
            ['Ada', 'Prueba Uno', 'F13SOC0001', '600000021', 'socio1.f13@example.invalid', 'f13_socio_1', null],
            ['Bruno', 'Prueba Dos', 'F13SOC0002', '600000022', 'socio2.f13@example.invalid', 'f13_socio_2', 'ES9121000418450200051332'],
            ['Carla', 'Prueba Tres', 'F13SOC0003', '600000023', 'socio3.f13@example.invalid', 'f13_socio_3', 'ES9121000418450200051332'],
            ['Diego', 'Prueba Cuatro', 'F13SOC0004', '600000024', 'socio4.f13@example.invalid', 'f13_socio_4', null],
        ] as [$name, $surname, $dni, $phone, $email, $username, $iban]) {
            if (!$users->crear($name, $surname, $dni, $phone, $email, $username, $password, $iban)) {
                throw new RuntimeException('No se pudo crear un socio sintético.');
            }
            $q = $db->prepare('SELECT id_usuario FROM usuario WHERE nombre_usuario = :usuario');
            $q->execute([':usuario' => $username]);
            $socios[] = (int) $q->fetchColumn();
        }
    });

    $measure('credenciales_locales', function () use ($empresaId, $sedePrincipal, $password): void {
        if (!(new GimnasioModel($empresaId))->guardarCredenciales(
            $sedePrincipal,
            'acceso.f13@example.invalid',
            $password
        )) {
            throw new RuntimeException('No se pudieron crear las credenciales sintéticas de la sede.');
        }
    });

    $memberships = new MembresiaModel($sedePrincipal, $empresaId);
    $tarifaId = (int) $db->query(
        "SELECT id_tipo_membresia FROM tipo_membresia
         WHERE id_empresa = {$empresaId} AND nombre = 'Mensual' ORDER BY id_tipo_membresia LIMIT 1"
    )->fetchColumn();
    $measure('membresias_y_cobros', function () use ($memberships, $socios, $tarifaId, $recepcionId, $db, &$membresias): void {
        $error = '';
        $membresias = [];
        foreach ([
            [$socios[0], 'efectivo', 'f13-member-cash-0001'],
            [$socios[1], 'transferencia', 'f13-member-sepa-0002'],
            [$socios[2], 'transferencia', 'f13-member-overdue-0003'],
        ] as [$socio, $metodo, $key]) {
            $id = $memberships->contratar($socio, $tarifaId, $metodo, $error, null, 'mostrador', $key, $recepcionId);
            if (!$id) throw new RuntimeException('No se pudo crear la membresía sintética: ' . $error);
            $membresias[] = $id;
        }
        $stmt = $db->prepare(
            "UPDATE obligacion_pago SET estado = 'vencida', fecha_vencimiento = DATE_SUB(CURDATE(), INTERVAL 5 DAY)
             WHERE id_socio_membresia = :membresia AND estado = 'pendiente'"
        );
        $stmt->execute([':membresia' => $membresias[2]]);
    });

    $products = new ProductoModel($sedePrincipal, $empresaId);
    $measure('productos', function () use ($products, $db, $sedePrincipal, &$productoIds): void {
        foreach ([
            ['Agua F13', 'Producto exclusivamente sintético', 1.50, 30, 5, 10.0],
            ['Toalla F13', 'Producto exclusivamente sintético', 8.95, 12, 3, 21.0],
            ['Batido F13', 'Producto exclusivamente sintético', 3.25, 20, 4, 10.0],
        ] as [$name, $description, $price, $stock, $minimum, $vat]) {
            if (!$products->crear($name, $description, $price, $stock, $minimum, 'activo', null, $vat)) {
                throw new RuntimeException('No se pudo crear un producto sintético.');
            }
        }
        $productoIds = array_map('intval', $db->query(
            "SELECT id_producto FROM producto WHERE id_gimnasio = {$sedePrincipal} ORDER BY id_producto"
        )->fetchAll(PDO::FETCH_COLUMN));
    });

    $cash = new CashModel($sedePrincipal, $empresaId);
    $measure('caja', function () use ($cash, $recepcionId, &$cajaId): void {
        $error = '';
        $cajaId = $cash->abrir('75.00', $recepcionId, $error);
        if (!$cajaId) throw new RuntimeException('No se pudo abrir la caja sintética: ' . $error);
    });

    $sales = new VentaModel($sedePrincipal, $empresaId);
    $measure('ventas_stock_y_anulacion', function () use ($sales, $productoIds, $socios, $recepcionId, &$ventaActiva, &$ventaAnulada): void {
        $error = '';
        $ventaActiva = $sales->registrar(
            [['id_producto' => $productoIds[0], 'cantidad' => 2], ['id_producto' => $productoIds[1], 'cantidad' => 1]],
            $socios[0], 'efectivo', $recepcionId, $error, 'f13-sale-active-0001'
        );
        if (!$ventaActiva) throw new RuntimeException('No se pudo crear la venta sintética: ' . $error);
        $ventaAnulada = $sales->registrar(
            [['id_producto' => $productoIds[2], 'cantidad' => 1]],
            $socios[0], 'datafono', $recepcionId, $error, 'f13-sale-cancelled-0002'
        );
        if (!$ventaAnulada || !$sales->anular($ventaAnulada, $recepcionId, 'Anulación sintética F13')) {
            throw new RuntimeException('No se pudo crear y anular la venta sintética.');
        }
    });

    $sepa = new SepaModel($sedePrincipal, $empresaId);
    $measure('remesa_simulada_y_devolucion', function () use ($sepa, $socios, $membresias, $recepcionId, $db, &$remesaId, &$reciboId): void {
        $error = '';
        if (!$sepa->crearMandato($socios[1], 'ES9121000418450200051332', date('Y-m-d'), $error)) {
            throw new RuntimeException('No se pudo crear el mandato sintético: ' . $error);
        }
        if (!$sepa->crearMandato($socios[2], 'ES9121000418450200051332', date('Y-m-d'), $error)) {
            throw new RuntimeException('No se pudo crear el segundo mandato sintético: ' . $error);
        }
        $remesaId = $sepa->crearRemesa(
            [$membresias[1]], 'REMESA SINTÉTICA F13 — NO ENVIAR', date('Y-m-d', strtotime('+3 days')),
            $recepcionId, $error, 'f13-remittance-simulated-0001'
        );
        if (!$remesaId) throw new RuntimeException('No se pudo crear la remesa sintética: ' . $error);
        $reciboId = (int) $db->query("SELECT id_recibo FROM remesa_recibo WHERE id_remesa = {$remesaId} LIMIT 1")->fetchColumn();
        if (!$reciboId || !$sepa->marcarDevuelto($reciboId, 'Devolución sintética F13', $recepcionId)) {
            throw new RuntimeException('No se pudo simular la devolución.');
        }
    });

    $measure('busqueda_edicion_e_informe', function () use ($db, $empresaId, $sedePrincipal, $socios, $sales): void {
        $users = new UserModel($sedePrincipal, $empresaId);
        $found = $users->buscarPorId($socios[3]);
        if (!$found || !$users->actualizarDatosSocio(
            $socios[3], 'Diego Editado', 'Prueba Cuatro', '600000024',
            'socio4.editado.f13@example.invalid', null
        )) {
            throw new RuntimeException('No se pudo completar la búsqueda/edición sintética.');
        }
        if ($sales->contarDelDia() < 1 || $sales->sumarDelDia() <= 0) {
            throw new RuntimeException('El informe sintético no refleja la venta activa.');
        }
        $visible = (int) $db->query(
            "SELECT COUNT(*) FROM usuario WHERE id_empresa = {$empresaId} AND id_gimnasio = {$sedePrincipal} AND rol = 'socio'"
        )->fetchColumn();
        if ($visible !== 4) throw new RuntimeException('El recuento sintético de socios es incoherente.');
    });

    $summary = [
        'status' => 'ok',
        'classification' => 'VERIFICADO LOCAL',
        'environment' => APP_ENV,
        'database' => DB_NAME,
        'synthetic_only' => true,
        'access_control_mode' => ACCESS_CONTROL_MODE,
        'company_id' => $empresaId,
        'sites' => [$sedePrincipal, (int) $sedeSecundaria],
        'users' => ['direction' => $direccionId, 'admin' => $adminId, 'reception' => $recepcionId, 'members' => $socios],
        'cash_session_id' => $cajaId,
        'sales' => ['active' => $ventaActiva, 'cancelled' => $ventaAnulada],
        'remittance' => ['id' => $remesaId, 'receipt_returned' => $reciboId, 'sent_to_bank' => false],
        'elapsed_ms' => round((microtime(true) - $started) * 1000, 2),
        'flow_ms' => $times,
    ];
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "No se pudo completar el escenario sintético: {$e->getMessage()}\n");
    exit(1);
}
