<?php
/**
 * Prueba de humo: renderiza cada pantalla del panel con una sesión de admin
 * simulada y reporta cualquier error, aviso o consulta SQL fallida.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

$GLOBALS['incidencias'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    $GLOBALS['incidencias'][] = basename($file) . ':' . $line . ' — ' . $str;
    return true;
});

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) {
        echo "\n[FATAL] " . $e['message'] . "\n         en " . basename($e['file']) . ':' . $e['line'] . "\n";
    }
});

require_once __DIR__ . '/_arranque.php';   // base de pruebas, nunca la de trabajo

$raiz = dirname(__DIR__);

// Permite renderizar una sola pantalla: php render_test.php mostrarVentas
$soloMetodo = $argv[1] ?? null;

session_start();
$_SESSION['logueado']           = true;
$_SESSION['usuario_id']         = 1;
// Como empresa para poder renderizar también Sedes, que es exclusiva suya.
$_SESSION['usuario_rol']        = 'empresa';
$_SESSION['usuario_nombre']     = 'admin';
$_SESSION['usuario_nombre_real'] = 'Admin Sistema';
$_SESSION['usuario_foto']       = null;

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';

require_once $raiz . '/app/controllers/AdminController.php';
$ctrl = new AdminController();

$pantallas = [
    'Inicio'      => 'mostrarInicio',
    'Productos'   => 'mostrarProductos',
    'Ventas'      => 'mostrarVentas',
    'Socios'      => 'mostrarSocios',
    'Membresías'  => 'mostrarMembresias',
    'Reportes'    => 'mostrarReportes',
    'Log'         => 'mostrarLog',
    'Domiciliaciones' => 'mostrarRemesas',
    'Sedes'           => 'mostrarSedes',
    'Personal'        => 'mostrarEmpleados',
];

foreach ($pantallas as $nombre => $metodo) {
    if ($soloMetodo !== null && $metodo !== $soloMetodo) continue;
    $GLOBALS['incidencias'] = [];
    $_GET = ['action' => 'x'];

    ob_start();
    try {
        $ctrl->$metodo();
        $html = ob_get_clean();
        $error = null;
    } catch (\Throwable $e) {
        $html = ob_get_clean();
        $error = get_class($e) . ': ' . $e->getMessage();
    }

    $bytes = strlen($html);
    $tieneCierre = strpos($html, '</html>') !== false;

    if ($error) {
        echo "[FALLO]  $nombre — $error\n";
    } elseif (!empty($GLOBALS['incidencias'])) {
        echo "[AVISOS] $nombre ($bytes bytes)\n";
        foreach (array_slice(array_unique($GLOBALS['incidencias']), 0, 5) as $i) {
            echo "         $i\n";
        }
    } else {
        echo "[OK]     $nombre — $bytes bytes" . ($tieneCierre ? ', HTML completo' : ', HTML SIN CERRAR') . "\n";
    }
}
