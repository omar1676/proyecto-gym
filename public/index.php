<?php
/**
 * index.php — front controller / router del panel de gestión.
 *
 * Todas las peticiones pasan por aquí gracias al .htaccess de /public.
 * La acción se elige con ?action=NOMBRE y la tabla de abajo dice qué método la
 * atiende. Añadir una pantalla es añadir una línea.
 *
 * El acceso web es solo para empresa, admin y recepción. Los socios existen
 * como datos del negocio, pero no inician sesión.
 */

ob_start();

require_once __DIR__ . '/../app/controllers/AuthController.php';
require_once __DIR__ . '/../app/controllers/AdminController.php';
require_once __DIR__ . '/../app/controllers/MediaController.php';
require_once __DIR__ . '/../app/helpers/SecurityHeaders.php';
require_once __DIR__ . '/../app/helpers/ErrorHandler.php';
require_once __DIR__ . '/../app/helpers/RequestContext.php';
RequestContext::bootstrap('WEB');
SecurityHeaders::apply();
ErrorHandler::register();

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (($_GET['action'] ?? '') === 'health' || rtrim($requestPath, '/') === '/health') {
    require_once __DIR__ . '/../app/helpers/HealthCheck.php';
    $health = HealthCheck::run();
    http_response_code($health['ok'] ? 200 : 503);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $payload = ['status' => $health['ok'] ? 'ok' : 'error'];
    if (!$health['ok']) {
        $payload['component'] = $health['component'] ?? 'runtime';
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit($health['ok'] ? 0 : 1);
}
if (($_GET['action'] ?? '') === 'heartbeat' || rtrim($requestPath, '/') === '/heartbeat') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $file = MONITOR_STATE_DIR !== '' ? rtrim(MONITOR_STATE_DIR, '/\\') . '/heartbeat.json' : '';
    $payload = $file !== '' && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $stamp = is_array($payload) ? strtotime((string) ($payload['checked_at_utc'] ?? '')) : false;
    $age = $stamp ? max(0, time() - $stamp) : null;
    $monitorStatus = is_array($payload) ? strtoupper((string) ($payload['status'] ?? 'UNKNOWN')) : 'UNKNOWN';
    $fresh = is_array($payload) && $stamp !== false && $age <= 300 && ($payload['environment'] ?? '') === APP_ENV;
    $ok = $fresh && $monitorStatus === 'OK';
    http_response_code($ok ? 200 : 503);
    echo json_encode([
        'status' => $ok ? 'ok' : ($fresh ? strtolower($monitorStatus) : 'stale'),
        'monitor_status' => $monitorStatus,
        'environment' => APP_ENV,
        'instance' => is_array($payload) ? ($payload['instance'] ?? null) : null,
        'checked_at_utc' => is_array($payload) ? ($payload['checked_at_utc'] ?? null) : null,
        'age_seconds' => $age,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

/*
 * En desarrollo los errores se ven en pantalla; en producción NO.
 * Un aviso de PHP sin querer enseña la ruta del servidor, el nombre de las
 * tablas y a veces la consulta entera al primero que pase por la web.
 */
error_reporting(E_ALL);
if (defined('LOG_DIR') && is_dir(LOG_DIR) && is_writable(LOG_DIR)) {
    ini_set('log_errors', '1');
    ini_set('error_log', rtrim(LOG_DIR, '/\\') . DIRECTORY_SEPARATOR . 'php-' . date('Y-m-d') . '.log');
}
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// Una release antigua no puede operar silenciosamente contra un esquema que
// no entiende. Health/heartbeat siguen disponibles para diagnosticar; el
// resto del runtime se detiene de forma segura.
try {
    require_once __DIR__ . '/../app/config/database.php';
    require_once __DIR__ . '/../app/helpers/SchemaCompatibility.php';
    SchemaCompatibility::assertRuntime(Database::getInstance()->getConnection(), dirname(__DIR__));
} catch (Throwable $e) {
    require_once __DIR__ . '/../app/helpers/AppLogger.php';
    AppLogger::error('runtime_schema_incompatible');
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'Servicio temporalmente no disponible.';
    exit;
}

/**
 * Mapa de rutas: acción => [controlador, método].
 *
 * 'auth'  = acceso, recuperación de contraseña y perfil propio.
 * 'admin' = el panel. Cada método pone su propia guardia de permisos
 *           (requireEmpresa, requireAdmin o requirePersonal): el router NO
 *           decide quién entra, solo a dónde va la petición.
 */
$rutas = [
    /* --- Acceso en dos pasos ------------------------------------------- */
    'login'                  => ['auth', 'mostrarLogin'],
    'autenticar_gimnasio'    => ['auth', 'autenticarGimnasio'],
    'login_gimnasio'         => ['auth', 'mostrarLoginGimnasio'],
    'salir_gimnasio'         => ['auth', 'salirGimnasio'],
    'autenticar'             => ['auth', 'autenticar'],
    'logout'                 => ['auth', 'cerrarSesion'],

    /* --- Recuperar contraseña ------------------------------------------ */
    'password_forgot'        => ['auth', 'mostrarOlvideContrasena'],
    'password_forgot_submit' => ['auth', 'procesarOlvideContrasena'],
    'password_reset'         => ['auth', 'mostrarResetContrasena'],
    'password_reset_submit'  => ['auth', 'procesarResetContrasena'],

    /* --- Perfil propio -------------------------------------------------- */
    'perfil'                 => ['auth', 'mostrarPerfil'],
    'perfil_actualizar'      => ['auth', 'actualizarPerfil'],
    'media_foto'             => ['media', 'fotoUsuario'],

    /* --- Panel ---------------------------------------------------------- */
    'admin'                  => ['admin', 'mostrarInicio'],
    'admin_reportes'         => ['admin', 'mostrarReportes'],
    'admin_log'              => ['admin', 'mostrarLog'],
    'admin_exportar_ventas_csv' => ['admin', 'exportarVentasCSV'],

    /* Productos y stock */
    'admin_productos'              => ['admin', 'mostrarProductos'],
    'admin_subir_imagen_producto'  => ['admin', 'subirImagenProducto'],
    'admin_quitar_imagen_producto' => ['admin', 'quitarImagenProducto'],

    /* Ventas */
    'admin_ventas'           => ['admin', 'mostrarVentas'],
    'admin_venta_registrar'  => ['admin', 'registrarVenta'],
    'admin_venta_anular'     => ['admin', 'anularVenta'],

    /* Caja física por sede */
    'admin_caja'             => ['admin', 'mostrarCaja'],
    'admin_caja_operar'      => ['admin', 'operarCaja'],

    /* Socios y membresías */
    'admin_socios'              => ['admin', 'mostrarSocios'],
    'admin_socio_registrar'     => ['admin', 'registrarSocio'],
    'admin_socio_editar'        => ['admin', 'editarSocio'],
    'admin_socio_prueba'        => ['admin', 'iniciarPruebaSocio'],
    'admin_membresia_contratar' => ['admin', 'contratarMembresia'],
    'admin_membresias'          => ['admin', 'mostrarMembresias'],

    /* Importaciones masivas (solo dirección/superadmin) */
    'admin_importaciones'             => ['admin', 'mostrarImportaciones'],
    'admin_importacion_subir'         => ['admin', 'subirImportacion'],
    'admin_importacion_dry_run'       => ['admin', 'simularImportacion'],
    'admin_importacion_confirmar'     => ['admin', 'confirmarImportacion'],
    'admin_importacion_descartar'     => ['admin', 'descartarImportacion'],

    /* Domiciliación SEPA */
    'admin_mandato_crear'    => ['admin', 'crearMandato'],
    'admin_remesas'          => ['admin', 'mostrarRemesas'],
    'admin_remesa_descargar' => ['admin', 'descargarRemesa'],

    /* Sedes (solo empresa) */
    'admin_sedes'       => ['admin', 'mostrarSedes'],
    'admin_sede_activa' => ['admin', 'cambiarSedeActiva'],
    'admin_sede_marca'  => ['admin', 'guardarMarcaSede'],

    /* Personal */
    'admin_empleados'       => ['admin', 'mostrarEmpleados'],
    'admin_empleado_crear'  => ['admin', 'crearEmpleado'],
    'admin_empleado_editar' => ['admin', 'editarEmpleado'],
    'admin_empleado_toggle' => ['admin', 'toggleEmpleado'],
];

$action = $_GET['action'] ?? 'login';

if (!isset($rutas[$action])) {
    header('Location: ' . APP_URL . '/index.php?action=login');
    exit;
}

[$controlador, $metodo] = $rutas[$action];

// Los controladores se crean al usarlos: cada constructor arranca la sesión y
// abre la conexión, y no hace falta hacerlo dos veces en cada petición.
$ctrl = $controlador === 'auth'
    ? new AuthController()
    : ($controlador === 'media' ? new MediaController() : new AdminController());
$ctrl->$metodo();
