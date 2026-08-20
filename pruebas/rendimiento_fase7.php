<?php
/** Benchmark reproducible antes/después de la paginación de socios. */

require_once __DIR__ . '/_arranque.php';
require_once __DIR__ . '/../app/models/MembresiaModel.php';

set_time_limit(0);
$db = Database::getInstance()->getConnection();
$idEmpresa = (int) $db->query('SELECT MIN(id_empresa) FROM empresa')->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM usuario WHERE rol = 'socio' AND id_empresa = :empresa");
$stmt->execute([':empresa' => $idEmpresa]);
$totalEmpresa = (int) $stmt->fetchColumn();
if ($totalEmpresa < 5000) {
    fwrite(STDERR, "Falta volumen: ejecuta preparar_base.php, carga_piloto.php y carga_fase7.php.\n");
    exit(1);
}

$model = new MembresiaModel(null, $idEmpresa);

function medirFase7(string $nombre, callable $operacion): array
{
    $operacion(); // calentamiento
    $tiempos = $memorias = $bytes = [];
    $filas = 0;
    for ($i = 0; $i < 9; $i++) {
        gc_collect_cycles();
        $inicio = hrtime(true);
        $resultado = $operacion();
        $tiempos[] = (hrtime(true) - $inicio) / 1_000_000;
        $memorias[] = memory_get_usage(true);
        if (is_string($resultado)) {
            $bytes[] = strlen($resultado);
            $filas = substr_count($resultado, '<tr class="border-t');
        } else {
            $filas = is_array($resultado) ? count($resultado) : 1;
            $json = json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $bytes[] = is_string($json) ? strlen($json) : 0;
        }
        unset($resultado);
    }
    sort($tiempos, SORT_NUMERIC);
    sort($memorias, SORT_NUMERIC);
    sort($bytes, SORT_NUMERIC);
    return [
        'operacion' => $nombre,
        'filas' => $filas,
        'p50_ms' => $tiempos[4],
        'p95_ms' => $tiempos[8],
        'bytes_p50' => $bytes[4],
        'memoria_p95' => $memorias[8],
    ];
}

function medirUnaFase7(string $nombre, callable $operacion): array
{
    gc_collect_cycles();
    $inicio = hrtime(true);
    $resultado = $operacion();
    $tiempo = (hrtime(true) - $inicio) / 1_000_000;
    $json = json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $medicion = [
        'operacion' => $nombre,
        'filas' => is_array($resultado) ? count($resultado) : 1,
        'p50_ms' => $tiempo,
        'p95_ms' => $tiempo,
        'bytes_p50' => is_string($json) ? strlen($json) : 0,
        'memoria_p95' => memory_get_usage(true),
    ];
    unset($resultado);
    return $medicion;
}

$mediciones = [];
if (in_array('--include-legacy', $argv ?? [], true)) {
    // Es deliberadamente una sola muestra: en la medición inicial real tardó
    // más de 100 segundos y repetirla nueve veces no aporta valor operativo.
    $mediciones[] = medirUnaFase7('ANTES SQL sin paginar · 5000', static fn() => $model->listarSocios(''));
}
$mediciones = array_merge($mediciones, [
    medirFase7('DESPUÉS SQL página 50/5000', static fn() => $model->paginarSocios('', 1, 50)['items']),
    medirFase7('DESPUÉS búsqueda · 50 resultados', static fn() => $model->paginarSocios('F7V050', 1, 50)['items']),
    medirFase7('DESPUÉS búsqueda · 500 resultados', static fn() => $model->paginarSocios('F7V500', 1, 50)['items']),
    medirFase7('DESPUÉS búsqueda exacta email', static fn() => $model->paginarSocios('fase7.volumen.01001@test.invalid', 1, 50)['items']),
    medirFase7('DESPUÉS búsqueda amplia F7V', static fn() => $model->paginarSocios('F7V', 1, 50)['items']),
    medirFase7('DESPUÉS búsqueda teléfono', static fn() => $model->paginarSocios('600 001', 1, 50)['items']),
]);

// Render completo de la pantalla (PHP + consultas + HTML), aún sin red.
$sesionesTmp = __DIR__ . '/sesiones_tmp';
if (!is_dir($sesionesTmp)) mkdir($sesionesTmp, 0700, true);
session_save_path($sesionesTmp);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$stmt = $db->prepare("SELECT id_usuario, rol FROM usuario WHERE id_empresa = :empresa AND rol IN ('direccion','superadmin') ORDER BY FIELD(rol,'direccion','superadmin'), id_usuario LIMIT 1");
$stmt->execute([':empresa' => $idEmpresa]);
$direccion = $stmt->fetch();
if (!$direccion) {
    fwrite(STDERR, "La carga no contiene un usuario de dirección.\n");
    exit(1);
}
$_SESSION['logueado'] = true;
$_SESSION['usuario_id'] = (int) $direccion['id_usuario'];
$_SESSION['usuario_rol'] = (string) $direccion['rol'];
$_SESSION['usuario_nombre'] = 'direccion_fase7';
$_SESSION['usuario_nombre_real'] = 'Dirección Fase 7';
$_SESSION['gimnasio_auth_id'] = (int) $db->query('SELECT MIN(id_gimnasio) FROM gimnasio WHERE id_empresa = ' . $idEmpresa)->fetchColumn();
unset($_SESSION['gimnasio_activo']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../app/controllers/AdminController.php';

$render = static function (string $buscar = ''): string {
    $_GET = ['action' => 'admin_socios'];
    if ($buscar !== '') $_GET['buscar'] = $buscar;
    $controller = new AdminController();
    ob_start();
    $controller->mostrarSocios();
    return (string) ob_get_clean();
};
$mediciones[] = medirFase7('DESPUÉS render HTML · 5000', static fn() => $render(''));
$mediciones[] = medirFase7('DESPUÉS render búsqueda amplia', static fn() => $render('F7V'));

echo "Base: " . Database::getInstance()->nombreBase() . PHP_EOL;
echo "Empresa: {$idEmpresa}; socios en ámbito: {$totalEmpresa}; muestras: 9 + calentamiento" . PHP_EOL . PHP_EOL;
printf("%-40s %7s %11s %11s %12s %12s\n", 'OPERACIÓN', 'FILAS', 'P50 ms', 'P95 ms', 'BYTES P50', 'MEMORIA P95');
foreach ($mediciones as $m) {
    printf(
        "%-40s %7d %11.2f %11.2f %12d %12d\n",
        $m['operacion'], $m['filas'], $m['p50_ms'], $m['p95_ms'], $m['bytes_p50'], $m['memoria_p95']
    );
}

echo PHP_EOL . json_encode($mediciones, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
