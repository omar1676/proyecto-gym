<?php
/**
 * Datos sintéticos para la auditoría funcional del piloto.
 *
 * Uso seguro:
 *   php pruebas/preparar_base.php
 *   php pruebas/carga_piloto.php
 *
 * _arranque.php fuerza APP_ENV=test y comprueba que la conexión apunta a
 * DB_NAME_PRUEBAS. Este script nunca lee la base de trabajo ni copia socios
 * reales. Para repetirlo hay que reconstruir primero el fixture: así no hay
 * borrados parciales ni conjuntos de datos difíciles de reproducir.
 */

require_once __DIR__ . '/_arranque.php';

set_time_limit(0);
$inicio = microtime(true);
$db = Database::getInstance()->getConnection();

$yaCargada = (int) $db->query("SELECT COUNT(*) FROM empresa WHERE nombre LIKE 'PILOTO Empresa %'")->fetchColumn();
if ($yaCargada > 0) {
    fwrite(STDERR, "La carga PILOTO ya existe. Ejecuta primero pruebas/preparar_base.php.\n");
    exit(1);
}

$objetivos = [
    'empresas'     => 5,
    'socios'       => 5000,
    'membresias'   => 7500,
    'productos'    => 210,
    'ventas'       => 6000,
    'auditoria'    => 12000,
];

function importe(int $centimos): string
{
    return number_format($centimos / 100, 2, '.', '');
}

function fechaRelativa(string $modificador): string
{
    return (new DateTimeImmutable('today'))->modify($modificador)->format('Y-m-d');
}

try {
    $db->beginTransaction();

    $empresaInicial = (int) $db->query('SELECT MIN(id_empresa) FROM empresa')->fetchColumn();
    if ($empresaInicial <= 0) {
        throw new RuntimeException('El fixture no contiene la empresa inicial.');
    }

    $empresaIds = [$empresaInicial];
    $insertEmpresa = $db->prepare(
        "INSERT INTO empresa (nombre, nombre_comercial, email, telefono, estado)
         VALUES (:nombre, :comercial, :email, :telefono, 'activa')"
    );
    for ($n = 2; $n <= $objetivos['empresas']; $n++) {
        $insertEmpresa->execute([
            ':nombre'    => 'PILOTO Empresa ' . $n,
            ':comercial' => 'Gimnasio Sintético ' . $n,
            ':email'     => 'empresa' . $n . '@test.invalid',
            ':telefono'  => '9100000' . sprintf('%02d', $n),
        ]);
        $empresaIds[] = (int) $db->lastInsertId();
    }

    $hashGimnasio = password_hash('piloto2026', PASSWORD_BCRYPT, ['cost' => 4]);
    $insertSede = $db->prepare(
        "INSERT INTO gimnasio
         (id_empresa, nombre, slug, email_acceso, contrasena_acceso, activo)
         VALUES (:empresa, :nombre, :slug, :email, :clave, 1)"
    );
    $sedesPorEmpresa = [];
    foreach ($empresaIds as $indice => $idEmpresa) {
        if ($indice === 0) {
            $stmt = $db->prepare('SELECT id_gimnasio FROM gimnasio WHERE id_empresa = :empresa ORDER BY id_gimnasio');
            $stmt->execute([':empresa' => $idEmpresa]);
            $sedesPorEmpresa[$idEmpresa] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            continue;
        }
        $sedesPorEmpresa[$idEmpresa] = [];
        for ($s = 1; $s <= 3; $s++) {
            $numeroEmpresa = $indice + 1;
            $insertSede->execute([
                ':empresa' => $idEmpresa,
                ':nombre'  => 'PILOTO E' . $numeroEmpresa . ' Sede ' . $s,
                ':slug'    => 'piloto-e' . $numeroEmpresa . '-s' . $s,
                ':email'   => 'piloto.e' . $numeroEmpresa . '.s' . $s . '@test.invalid',
                ':clave'   => $hashGimnasio,
            ]);
            $sedesPorEmpresa[$idEmpresa][] = (int) $db->lastInsertId();
        }
    }

    $hashUsuario = password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 4]);
    $insertUsuario = $db->prepare(
        "INSERT INTO usuario
         (id_empresa, id_gimnasio, nombre, apellidos, dni, telefono, email,
          nombre_usuario, contrasena, rol, activo)
         VALUES
         (:empresa, :sede, :nombre, :apellidos, :dni, :telefono, :email,
          :usuario, :clave, :rol, 1)"
    );

    foreach ($empresaIds as $idEmpresa) {
        $insertUsuario->execute([
            ':empresa'   => $idEmpresa,
            ':sede'      => null,
            ':nombre'    => 'Dirección',
            ':apellidos' => 'Piloto ' . $idEmpresa,
            ':dni'       => 'PILOT-DIR-' . $idEmpresa,
            ':telefono'  => '600900' . sprintf('%03d', $idEmpresa),
            ':email'     => 'direccion.piloto.' . $idEmpresa . '@test.invalid',
            ':usuario'   => 'direccion_piloto_' . $idEmpresa,
            ':clave'     => $hashUsuario,
            ':rol'       => 'direccion',
        ]);
    }

    $nombres = ['Álvaro', 'María-José', 'Lucía', 'Óscar', 'Núria', 'Zoë', 'José', 'Carmen'];
    $apellidos = ['García', 'Muñoz', "O'Connor", 'De la Cruz', 'Núñez', 'López-Sanz'];
    $sociosPorSede = [];
    $sociosPorEmpresa = [];
    $stmtSocios = $db->query("SELECT id_usuario, id_empresa, id_gimnasio FROM usuario WHERE rol = 'socio'");
    foreach ($stmtSocios->fetchAll() as $socio) {
        $idSede = (int) $socio['id_gimnasio'];
        $idEmpresa = (int) $socio['id_empresa'];
        $sociosPorSede[$idSede][] = (int) $socio['id_usuario'];
        $sociosPorEmpresa[$idEmpresa][] = (int) $socio['id_usuario'];
    }

    $secuenciaSocio = 1;
    foreach ($empresaIds as $idEmpresa) {
        $existentes = count($sociosPorEmpresa[$idEmpresa] ?? []);
        $porCrear = 1000 - $existentes;
        $sedes = $sedesPorEmpresa[$idEmpresa];
        for ($i = 0; $i < $porCrear; $i++, $secuenciaSocio++) {
            $idSede = $sedes[$i % count($sedes)];
            $insertUsuario->execute([
                ':empresa'   => $idEmpresa,
                ':sede'      => $idSede,
                ':nombre'    => $nombres[$secuenciaSocio % count($nombres)],
                ':apellidos' => $apellidos[$secuenciaSocio % count($apellidos)] . ' Piloto ' . sprintf('%05d', $secuenciaSocio),
                ':dni'       => 'PILOT' . sprintf('%07d', $secuenciaSocio),
                ':telefono'  => '6' . sprintf('%08d', $secuenciaSocio),
                ':email'     => 'piloto.socio.' . sprintf('%05d', $secuenciaSocio) . '@test.invalid',
                ':usuario'   => 'piloto_socio_' . sprintf('%05d', $secuenciaSocio),
                ':clave'     => $hashUsuario,
                ':rol'       => 'socio',
            ]);
            $idSocio = (int) $db->lastInsertId();
            $sociosPorSede[$idSede][] = $idSocio;
            $sociosPorEmpresa[$idEmpresa][] = $idSocio;
        }
    }

    $insertTipo = $db->prepare(
        "INSERT INTO tipo_membresia
         (id_empresa, id_gimnasio, nombre, descripcion, precio, iva, duracion_meses, estado)
         VALUES (:empresa, NULL, :nombre, 'Datos sintéticos de rendimiento', :precio, 21.00, :meses, 'activo')"
    );
    $tiposPorEmpresa = [];
    foreach ($empresaIds as $idEmpresa) {
        $stmt = $db->prepare('SELECT id_tipo_membresia FROM tipo_membresia WHERE id_empresa = :empresa ORDER BY id_tipo_membresia LIMIT 1');
        $stmt->execute([':empresa' => $idEmpresa]);
        $idTipo = (int) $stmt->fetchColumn();
        if ($idTipo === 0) {
            $insertTipo->execute([
                ':empresa' => $idEmpresa,
                ':nombre'  => 'Mensual PILOTO',
                ':precio'  => '39.95',
                ':meses'   => 1,
            ]);
            $idTipo = (int) $db->lastInsertId();
        }
        $tiposPorEmpresa[$idEmpresa] = $idTipo;
    }

    $todosLosSocios = [];
    foreach ($sociosPorEmpresa as $idEmpresa => $ids) {
        foreach ($ids as $idSocio) {
            $todosLosSocios[] = [$idSocio, (int) $idEmpresa];
        }
    }
    usort($todosLosSocios, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

    $sedeDeSocio = $db->prepare('SELECT id_gimnasio FROM usuario WHERE id_usuario = :id');
    $insertMembresia = $db->prepare(
        "INSERT INTO socio_membresia
         (id_socio, id_gimnasio, id_tipo_membresia, nombre_tipo, es_prueba,
          estado_pago, precio_pagado, precio_suplemento, iva, metodo_pago,
          fecha_inicio, fecha_fin, renovar_auto, origen, idempotency_key)
         VALUES
         (:socio, :sede, :tipo, 'Mensual PILOTO', 0, 'pagado', :precio, 0.00,
          21.00, :metodo, :inicio, :fin, 0, 'mostrador', :clave)"
    );
    $mapaSedeSocio = [];
    foreach ($todosLosSocios as $pos => [$idSocio, $idEmpresa]) {
        $sedeDeSocio->execute([':id' => $idSocio]);
        $idSede = (int) $sedeDeSocio->fetchColumn();
        $mapaSedeSocio[$idSocio] = $idSede;
        $insertMembresia->execute([
            ':socio'  => $idSocio,
            ':sede'   => $idSede,
            ':tipo'   => $tiposPorEmpresa[$idEmpresa],
            ':precio' => '39.95',
            ':metodo' => $pos % 3 === 0 ? 'transferencia' : ($pos % 3 === 1 ? 'datafono' : 'efectivo'),
            ':inicio' => fechaRelativa('-13 months'),
            ':fin'    => fechaRelativa('-12 months'),
            ':clave'  => sprintf('pilot-old-%08d', $idSocio),
        ]);
    }
    for ($i = 0; $i < 2500; $i++) {
        [$idSocio, $idEmpresa] = $todosLosSocios[$i];
        $insertMembresia->execute([
            ':socio'  => $idSocio,
            ':sede'   => $mapaSedeSocio[$idSocio],
            ':tipo'   => $tiposPorEmpresa[$idEmpresa],
            ':precio' => '39.95',
            ':metodo' => $i % 3 === 0 ? 'transferencia' : ($i % 3 === 1 ? 'datafono' : 'efectivo'),
            ':inicio' => fechaRelativa('-15 days'),
            ':fin'    => fechaRelativa('+15 days'),
            ':clave'  => sprintf('pilot-new-%08d', $idSocio),
        ]);
    }

    $insertProducto = $db->prepare(
        "INSERT INTO producto
         (nombre, descripcion, precio, iva, stock, stock_minimo, estado, id_gimnasio)
         VALUES (:nombre, 'Producto sintético para prueba de volumen', :precio, 21.00,
                 :stock, 5, 'activo', :sede)"
    );
    $productosPorSede = [];
    $todosLosSitios = [];
    foreach ($sedesPorEmpresa as $idEmpresa => $sedes) {
        foreach ($sedes as $idSede) {
            $todosLosSitios[] = [(int) $idSede, (int) $idEmpresa];
            for ($p = 1; $p <= 15; $p++) {
                $insertProducto->execute([
                    ':nombre' => 'Producto PILOTO ' . sprintf('%02d', $p) . ' S' . $idSede,
                    ':precio' => importe(100 + (($p * 137) % 4900)),
                    ':stock'  => 20 + ($p * 3),
                    ':sede'   => $idSede,
                ]);
                $productosPorSede[$idSede][] = (int) $db->lastInsertId();
            }
        }
    }

    $insertVenta = $db->prepare(
        "INSERT INTO venta
         (serie, ejercicio, numero, idempotency_key, id_socio, id_usuario_registro,
          id_gimnasio, metodo_pago, total, base_imponible, total_iva, estado,
          anulada_en, motivo_anulacion, fecha)
         VALUES
         ('P', :ejercicio, :numero, :clave, :socio, NULL, :sede, :metodo,
          :total, :base, :iva, :estado, :anulada, :motivo, :fecha)"
    );
    $insertLinea = $db->prepare(
        "INSERT INTO venta_linea
         (id_venta, id_producto, nombre_producto, cantidad, precio_unitario, iva,
          subtotal, base_linea, cuota_iva)
         VALUES (:venta, :producto, :nombre, :cantidad, :precio, 21.00,
                 :subtotal, :base, :iva)"
    );
    $numerosPorSede = [];
    for ($i = 1; $i <= $objetivos['ventas']; $i++) {
        [$idSede] = $todosLosSitios[($i - 1) % count($todosLosSitios)];
        $sociosSede = $sociosPorSede[$idSede];
        $idSocio = $sociosSede[$i % count($sociosSede)];
        $productosSede = $productosPorSede[$idSede];
        $idProducto = $productosSede[$i % count($productosSede)];
        $cantidad = 1 + ($i % 3);
        $precioCentimos = 100 + ((($i % 15) + 1) * 137) % 4900;
        $subtotalCentimos = $precioCentimos * $cantidad;
        $baseCentimos = (int) round($subtotalCentimos / 1.21);
        $ivaCentimos = $subtotalCentimos - $baseCentimos;
        $numero = ($numerosPorSede[$idSede] ?? 0) + 1;
        $numerosPorSede[$idSede] = $numero;
        $anulada = $i % 20 === 0;
        $fecha = (new DateTimeImmutable('now'))
            ->modify('-' . ($i % 90) . ' days')
            ->setTime(8 + ($i % 14), $i % 60, $i % 60)
            ->format('Y-m-d H:i:s');

        $insertVenta->execute([
            ':ejercicio' => (int) date('Y'),
            ':numero'    => $numero,
            ':clave'     => sprintf('pilot-sale-%08d', $i),
            ':socio'     => $idSocio,
            ':sede'      => $idSede,
            ':metodo'    => $i % 3 === 0 ? 'transferencia' : ($i % 3 === 1 ? 'datafono' : 'efectivo'),
            ':total'     => importe($subtotalCentimos),
            ':base'      => importe($baseCentimos),
            ':iva'       => importe($ivaCentimos),
            ':estado'    => $anulada ? 'anulada' : 'activa',
            ':anulada'   => $anulada ? $fecha : null,
            ':motivo'    => $anulada ? 'Anulación sintética de carga' : null,
            ':fecha'     => $fecha,
        ]);
        $idVenta = (int) $db->lastInsertId();
        $insertLinea->execute([
            ':venta'    => $idVenta,
            ':producto' => $idProducto,
            ':nombre'   => 'Producto PILOTO ' . sprintf('%02d', ($i % 15) + 1) . ' S' . $idSede,
            ':cantidad' => $cantidad,
            ':precio'   => importe($precioCentimos),
            ':subtotal' => importe($subtotalCentimos),
            ':base'     => importe($baseCentimos),
            ':iva'      => importe($ivaCentimos),
        ]);
    }

    $insertLog = $db->prepare(
        "INSERT INTO log_actividad
         (id_usuario, id_usuario_afectado, accion, entidad, id_entidad, detalle,
          ip, id_gimnasio, id_empresa, fecha)
         VALUES (NULL, :afectado, :accion, 'socio', :entidad,
                 :detalle, '127.0.0.1', :sede, :empresa, :fecha)"
    );
    for ($i = 1; $i <= $objetivos['auditoria']; $i++) {
        [$idSocio, $idEmpresa] = $todosLosSocios[($i - 1) % count($todosLosSocios)];
        $insertLog->execute([
            ':afectado' => $idSocio,
            ':accion'   => $i % 4 === 0 ? 'Renovación PILOTO' : 'Consulta PILOTO',
            ':entidad'  => $idSocio,
            ':detalle'  => 'Registro sintético de rendimiento #' . $i,
            ':sede'     => $mapaSedeSocio[$idSocio],
            ':empresa'  => $idEmpresa,
            ':fecha'    => (new DateTimeImmutable('now'))->modify('-' . ($i % 120) . ' days')->format('Y-m-d H:i:s'),
        ]);
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Carga cancelada y revertida: ' . $e->getMessage() . "\n");
    exit(1);
}

$conteos = [
    'empresas'   => (int) $db->query('SELECT COUNT(*) FROM empresa')->fetchColumn(),
    'sedes'      => (int) $db->query('SELECT COUNT(*) FROM gimnasio')->fetchColumn(),
    'socios'     => (int) $db->query("SELECT COUNT(*) FROM usuario WHERE rol = 'socio'")->fetchColumn(),
    'membresias' => (int) $db->query('SELECT COUNT(*) FROM socio_membresia')->fetchColumn(),
    'productos'  => (int) $db->query("SELECT COUNT(*) FROM producto WHERE nombre LIKE 'Producto PILOTO %'")->fetchColumn(),
    'ventas'     => (int) $db->query("SELECT COUNT(*) FROM venta WHERE serie = 'P'")->fetchColumn(),
    'auditoria'  => (int) $db->query("SELECT COUNT(*) FROM log_actividad WHERE accion LIKE '%PILOTO%'")->fetchColumn(),
];

echo "Carga sintética PILOTO completada en " . number_format(microtime(true) - $inicio, 2, '.', '') . " s\n";
foreach ($conteos as $nombre => $cantidad) {
    echo '  ' . str_pad($nombre, 12) . ': ' . $cantidad . "\n";
}
