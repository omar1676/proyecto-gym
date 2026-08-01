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

/*
 * En desarrollo los errores se ven en pantalla; en producción NO.
 * Un aviso de PHP sin querer enseña la ruta del servidor, el nombre de las
 * tablas y a veces la consulta entera al primero que pase por la web.
 */
error_reporting(E_ALL);
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
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

    /* Socios y membresías */
    'admin_socios'              => ['admin', 'mostrarSocios'],
    'admin_socio_registrar'     => ['admin', 'registrarSocio'],
    'admin_socio_editar'        => ['admin', 'editarSocio'],
    'admin_socio_prueba'        => ['admin', 'iniciarPruebaSocio'],
    'admin_membresia_contratar' => ['admin', 'contratarMembresia'],
    'admin_membresias'          => ['admin', 'mostrarMembresias'],

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
$ctrl = $controlador === 'auth' ? new AuthController() : new AdminController();
$ctrl->$metodo();
