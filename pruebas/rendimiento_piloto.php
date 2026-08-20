<?php
/** Benchmark reproducible de consultas usadas por las pantallas del piloto. */

require_once __DIR__ . '/_arranque.php';
require_once __DIR__ . '/../app/models/UserModel.php';
require_once __DIR__ . '/../app/models/MembresiaModel.php';
require_once __DIR__ . '/../app/models/ProductoModel.php';
require_once __DIR__ . '/../app/models/VentaModel.php';
require_once __DIR__ . '/../app/models/LogModel.php';

$db = Database::getInstance()->getConnection();
$totalSocios = (int) $db->query("SELECT COUNT(*) FROM usuario WHERE rol = 'socio'")->fetchColumn();
if ($totalSocios < 5000) {
    fwrite(STDERR, "Falta la carga sintética: ejecuta php pruebas/carga_piloto.php.\n");
    exit(1);
}

$idSede = (int) $db->query('SELECT MIN(id_gimnasio) FROM gimnasio')->fetchColumn();
$stmt = $db->prepare('SELECT id_empresa FROM gimnasio WHERE id_gimnasio = :sede');
$stmt->execute([':sede' => $idSede]);
$idEmpresa = (int) $stmt->fetchColumn();

$usuariosSede = new UserModel($idSede, $idEmpresa);
$membresiasSede = new MembresiaModel($idSede, $idEmpresa);
$membresiasEmpresa = new MembresiaModel(null, $idEmpresa);
$productosSede = new ProductoModel($idSede, $idEmpresa);
$ventasSede = new VentaModel($idSede, $idEmpresa);
$logsEmpresa = new LogModel($idEmpresa);

/** Ejecuta una consulta una vez para calentar y luego nueve muestras reales. */
function medir(string $nombre, callable $consulta): array
{
    $calentamiento = $consulta();
    unset($calentamiento);
    $muestras = [];
    $filas = 0;
    for ($i = 0; $i < 9; $i++) {
        $inicio = hrtime(true);
        $resultado = $consulta();
        $muestras[] = (hrtime(true) - $inicio) / 1_000_000;
        $filas = is_array($resultado) ? count($resultado) : 1;
        unset($resultado);
    }
    sort($muestras, SORT_NUMERIC);
    $mediana = $muestras[4];
    $p95 = $muestras[8];
    return [
        'consulta' => $nombre,
        'filas'    => $filas,
        'mediana'  => $mediana,
        'p95'      => $p95,
        'min'      => $muestras[0],
        'max'      => $muestras[8],
    ];
}

$desde = (new DateTimeImmutable('today'))->modify('-120 days')->format('Y-m-d');
$hasta = (new DateTimeImmutable('today'))->format('Y-m-d');

$mediciones = [
    medir('Socios · listado sede', static fn() => $membresiasSede->listarSocios('')),
    medir('Socios · listado empresa', static fn() => $membresiasEmpresa->listarSocios('')),
    medir('Socios · coincidencia parcial', static fn() => $membresiasSede->listarSocios('Piloto 00')),
    medir('Socios · email exacto', static fn() => $membresiasSede->listarSocios('piloto.socio.00001@test.invalid')),
    medir('Socios · cero resultados', static fn() => $membresiasSede->listarSocios('ZZZ-SIN-RESULTADO')),
    medir('Socios · contadores cabecera', static fn() => [
        $usuariosSede->contarPorRol('socio'),
        $membresiasSede->contarActivas(),
        $membresiasSede->contarVencidas(),
        $membresiasSede->listarProximasAVencer(15),
        $membresiasSede->listarPruebasPendientes(),
    ]),
    medir('Productos · listado sede', static fn() => $productosSede->listarTodos('')),
    medir('Ventas · rango 120 días', static fn() => $ventasSede->listarPorRango($desde, $hasta)),
    medir('Ventas · resumen por pago', static fn() => $ventasSede->sumarPorMetodoPago($desde, $hasta)),
    medir('Informes · top productos', static fn() => $ventasSede->topProductos($desde, $hasta, 10)),
    medir('Auditoría · últimas 200 empresa', static fn() => $logsEmpresa->listar(200, null, [])),
];

echo "Base: " . Database::getInstance()->nombreBase() . "\n";
echo "Volumen: {$totalSocios} socios; sede {$idSede}; empresa {$idEmpresa}\n\n";
printf("%-38s %8s %12s %12s %12s %12s\n", 'CONSULTA', 'FILAS', 'MEDIANA ms', 'P95 ms', 'MIN ms', 'MAX ms');
foreach ($mediciones as $m) {
    printf(
        "%-38s %8d %12.2f %12.2f %12.2f %12.2f\n",
        $m['consulta'], $m['filas'], $m['mediana'], $m['p95'], $m['min'], $m['max']
    );
}

$lentas = array_values(array_filter($mediciones, static fn(array $m): bool => $m['p95'] >= 200.0));
echo "\nUmbral de consulta lenta: P95 >= 200 ms\n";
echo 'Consultas lentas: ' . count($lentas) . "\n";
exit(0);
