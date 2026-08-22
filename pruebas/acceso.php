<?php
/**
 * Acceso en dos niveles, comprobado por HTTP contra el servidor.
 *
 *   Nivel 1: el gimnasio se identifica con email y contraseña.
 *   Nivel 2: el empleado entra con su usuario corto.
 *
 * Lo que más importa aquí es que el segundo nivel no se pueda alcanzar sin
 * superar el primero, y que un empleado no entre por un gimnasio ajeno.
 *
 * Requiere el servidor levantado en localhost:8080 y los datos de ejemplo.
 */

putenv('APP_ENV=test');
require_once dirname(__DIR__) . '/app/config/database.php';

$base = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8091/index.php';
$ok = 0; $fallos = 0;
$forceFailure = in_array('--force-failure', $argv ?? [], true);
$cookieFiles = [];
register_shutdown_function(static function () use (&$cookieFiles): void {
    foreach (array_unique($cookieFiles) as $file) {
        if (is_string($file) && str_starts_with(basename($file), 'ck')) {
            @unlink($file);
        }
    }
});

/**
 * Esta suite provoca fallos de acceso a propósito, y el sistema bloquea por
 * intentos repetidos. Sin limpiar el registro entre bloques, las últimas
 * comprobaciones fallarían por el bloqueo y no por lo que quieren medir.
 */
/*
 * El proceso PHP que sirve estas peticiones DEBE ejecutarse con APP_ENV=test.
 * SecurityHeaders añade X-App-Environment: test: sin esa prueba positiva la
 * suite aborta antes de borrar intentos o enviar credenciales.
 */
require_once __DIR__ . '/../app/config/config.php';
if (PHP_SAPI !== 'cli' || APP_ENV !== 'test') {
    fwrite(STDERR, "\n  Esta prueba solo se ejecuta con APP_ENV=test.\n\n");
    exit(1);
}

$sonda = pedir($base . '?action=login_gimnasio');
if (stripos($sonda['cuerpo'], 'X-App-Environment: test') === false) {
    fwrite(STDERR, "\n  ABORTADO: el servidor HTTP no acredita APP_ENV=test.\n\n");
    exit(1);
}

function limpiarIntentos(): void {
    $db = Database::getInstance()->getConnection();
    foreach (['intentos_login', 'intentos_gimnasio'] as $tabla) {
        try { $db->exec("DELETE FROM {$tabla}"); } catch (PDOException $e) { /* no existe aún */ }
    }
}

limpiarIntentos();

function comprobar(string $d, bool $condicion, string $detalle = '') {
    global $ok, $fallos;
    if ($condicion) { $ok++; echo "  OK   $d\n"; }
    else { $fallos++; echo "  FALLO $d $detalle\n"; }
}

/**
 * Testigo CSRF de una sesión, tal y como lo haría un navegador: se pide la
 * pantalla de acceso con esas cookies y se lee el campo oculto del formulario.
 *
 * No se guarda de una llamada a otra: al cerrar sesión se vacía la sesión
 * entera, testigo incluido, y uno viejo ya no valdría. Se prueban las dos
 * pantallas porque, según el estado, una de ellas redirige a la otra.
 */
function testigo(string $galleta) {
    global $base;
    foreach (['login_gimnasio', 'login'] as $pantalla) {
        $r = pedir("$base?action=$pantalla", null, $galleta);
        if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $r['cuerpo'], $m)) {
            return $m[1];
        }
    }
    return '';
}

/**
 * Petición que conserva las cookies en el fichero $galleta.
 *
 * En los POST se añade el testigo CSRF automáticamente si no viene puesto:
 * los formularios de acceso lo llevan desde que se cerró ese hueco, y aquí
 * interesa comprobar las credenciales, no el testigo.
 */
function pedir(string $url, ?array $post = null, string $galleta = '') {
    if ($post !== null && !isset($post['_csrf']) && strpos($url, 'autenticar') !== false) {
        $post['_csrf'] = testigo($galleta);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE     => $galleta,
        CURLOPT_COOKIEJAR      => $galleta,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $resp = curl_exec($ch);
    $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', $resp, $m);
    return ['cuerpo' => (string) $resp, 'destino' => trim($m[1] ?? ''), 'estado' => $estado];
}

function testigoPanel(string $galleta): string {
    global $base;
    $r = pedir("$base?action=admin", null, $galleta);
    return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $r['cuerpo'], $m) ? $m[1] : '';
}

function galletaNueva(): string {
    global $cookieFiles;
    $file = tempnam(sys_get_temp_dir(), 'ck');
    if ($file === false) throw new RuntimeException('No se pudo crear cookie jar temporal.');
    $cookieFiles[] = $file;
    return $file;
}

/** Abre el nivel 1 y devuelve la galleta con la sesión del gimnasio. */
function entrarGimnasio(string $email, string $clave): string {
    global $base;
    $ck = galletaNueva();
    pedir("$base?action=autenticar_gimnasio", ['email' => $email, 'contrasena' => $clave], $ck);
    return $ck;
}

const CLETO_EMAIL = 'cleto.reyes.villaviciosa@gmail.com';
const CLETO_CLAVE = 'cleto2026';
const NORTE_EMAIL = 'sede.norte@gmail.com';
const NORTE_CLAVE = 'norte2026';

echo "== NIVEL 1: acceso del gimnasio ==\n";

$r = pedir("$base?action=autenticar_gimnasio",
    ['email' => CLETO_EMAIL, 'contrasena' => CLETO_CLAVE], galletaNueva());
comprobar('el gimnasio entra con sus credenciales',
    strpos($r['destino'], 'login_gimnasio') !== false, "-> {$r['destino']}");

$r = pedir("$base?action=autenticar_gimnasio",
    ['email' => CLETO_EMAIL, 'contrasena' => 'claveerronea'], galletaNueva());
comprobar('contraseña de gimnasio incorrecta rechazada',
    strpos($r['destino'], 'login_gimnasio') === false, "-> {$r['destino']}");

$r = pedir("$base?action=autenticar_gimnasio",
    ['email' => 'noexiste@ejemplo.com', 'contrasena' => 'loquesea'], galletaNueva());
comprobar('email de gimnasio inexistente rechazado',
    strpos($r['destino'], 'login_gimnasio') === false, "-> {$r['destino']}");

echo "\n== SIN NIVEL 1 NO HAY NIVEL 2 ==\n";
$r = pedir("$base?action=login_gimnasio", null, galletaNueva());
comprobar('la pantalla de empleados redirige al acceso del gimnasio',
    strpos($r['destino'], 'action=login') !== false, "-> {$r['destino']}");

$r = pedir("$base?action=autenticar", ['usuario' => 'daniel', 'contrasena' => '1234'], galletaNueva());
comprobar('un empleado no entra saltándose el gimnasio',
    strpos($r['destino'], 'action=admin') === false, "-> {$r['destino']}");

echo "\n== NIVEL 2: empleados de Cleto Reyes ==\n";
foreach (['daniel', 'kevin', 'pedro'] as $empleado) {
    $ck = entrarGimnasio(CLETO_EMAIL, CLETO_CLAVE);
    $r  = pedir("$base?action=autenticar", ['usuario' => $empleado, 'contrasena' => '1234'], $ck);
    comprobar("$empleado entra con clave 1234",
        strpos($r['destino'], 'action=admin') !== false, "-> {$r['destino']}");
}

$ck = entrarGimnasio(CLETO_EMAIL, CLETO_CLAVE);
$r  = pedir("$base?action=autenticar", ['usuario' => 'daniel', 'contrasena' => '9999'], $ck);
comprobar('clave de empleado incorrecta rechazada',
    strpos($r['destino'], 'action=admin') === false, "-> {$r['destino']}");

echo "\n== UN EMPLEADO NO ENTRA POR OTRO GIMNASIO ==\n";
$ck = entrarGimnasio(NORTE_EMAIL, NORTE_CLAVE);
$r  = pedir("$base?action=autenticar", ['usuario' => 'daniel', 'contrasena' => '1234'], $ck);
comprobar('daniel NO entra por Sede Norte',
    strpos($r['destino'], 'action=admin') === false, "-> {$r['destino']}");

$ck = entrarGimnasio(NORTE_EMAIL, NORTE_CLAVE);
$r  = pedir("$base?action=autenticar", ['usuario' => 'nora', 'contrasena' => 'admin123'], $ck);
comprobar('nora sí entra por la suya',
    strpos($r['destino'], 'action=admin') !== false, "-> {$r['destino']}");

echo "\n== LA EMPRESA ENTRA POR CUALQUIERA ==\n";
foreach ([[CLETO_EMAIL, CLETO_CLAVE], [NORTE_EMAIL, NORTE_CLAVE]] as [$em, $cl]) {
    $ck = entrarGimnasio($em, $cl);
    $r  = pedir("$base?action=autenticar", ['usuario' => 'empresa', 'contrasena' => 'admin123'], $ck);
    comprobar("empresa entra por $em",
        strpos($r['destino'], 'action=admin') !== false, "-> {$r['destino']}");
}

echo "\n== LOS SOCIOS SIGUEN SIN ACCESO ==\n";
$ck = entrarGimnasio(CLETO_EMAIL, CLETO_CLAVE);
$r  = pedir("$base?action=autenticar", ['usuario' => 'omar', 'contrasena' => 'admin123'], $ck);
comprobar('el socio omar es rechazado',
    strpos($r['destino'], 'action=admin') === false, "-> {$r['destino']}");

echo "\n== LA MARCA DEL GIMNASIO SE APLICA ==\n";
$ck = entrarGimnasio(CLETO_EMAIL, CLETO_CLAVE);
$r  = pedir("$base?action=login_gimnasio", null, $ck);
comprobar('la pantalla muestra el nombre del gimnasio',
    strpos($r['cuerpo'], 'Cleto Reyes Villaviciosa') !== false);

// La marca se lee de la ficha: el color sale del logo de cada cliente, así que
// fijarlo aquí a mano obligaría a tocar la prueba cada vez que se cambia.
$marcaCleto = Database::getInstance()->getConnection()
    ->query("SELECT logo, color_primario FROM gimnasio WHERE id_gimnasio = 1")->fetch();

comprobar('usa su color de marca',
    strpos($r['cuerpo'], (string) $marcaCleto['color_primario']) !== false,
    "-> esperaba {$marcaCleto['color_primario']}");
comprobar('muestra su logo',
    $marcaCleto['logo'] && strpos($r['cuerpo'], 'assets/gimnasios/' . $marcaCleto['logo']) !== false,
    "-> esperaba {$marcaCleto['logo']}");

// La recuperación de contraseña se abre desde el login de la sede: tiene que
// conservar su marca, no volver a la genérica de la plataforma.
$r = pedir("$base?action=password_forgot", null, $ck);
comprobar('la recuperación de contraseña mantiene la marca',
    strpos($r['cuerpo'], (string) $marcaCleto['color_primario']) !== false
        && strpos($r['cuerpo'], 'Cleto Reyes Villaviciosa') !== false);

echo "\n== RELEVO DE TURNO: salir no cierra el gimnasio ==\n";
limpiarIntentos();   // los rechazos anteriores ya cumplieron su función
$ck = entrarGimnasio(CLETO_EMAIL, CLETO_CLAVE);
$r  = pedir("$base?action=autenticar", ['usuario' => 'daniel', 'contrasena' => '1234'], $ck);
comprobar('daniel entra', strpos($r['destino'], 'action=admin') !== false, "-> {$r['destino']}");

$r = pedir("$base?action=logout", null, $ck);
$sigue = pedir("$base?action=admin", null, $ck);
comprobar('logout por GET sin CSRF no cierra la sesión', $sigue['estado'] === 200);
$r = pedir("$base?action=logout", ['_csrf' => testigoPanel($ck)], $ck);
comprobar('al salir vuelve al login del gimnasio, no al de la plataforma',
    strpos($r['destino'], 'login_gimnasio') !== false, "-> {$r['destino']}");

// El gimnasio sigue identificado: entra el siguiente turno sin repetir el nivel 1.
$r = pedir("$base?action=login_gimnasio", null, $ck);
comprobar('la pantalla sigue siendo la del gimnasio',
    strpos($r['cuerpo'], 'Cleto Reyes Villaviciosa') !== false);

$r = pedir("$base?action=autenticar", ['usuario' => 'kevin', 'contrasena' => '1234'], $ck);
comprobar('kevin entra sin repetir las credenciales del gimnasio',
    strpos($r['destino'], 'action=admin') !== false, "-> {$r['destino']}");

echo "\n== SALIDA COMPLETA: cerrar el local ==\n";
$r = pedir("$base?action=salir_gimnasio", null, $ck);
$sigue = pedir("$base?action=admin", null, $ck);
comprobar('cerrar el gimnasio por GET sin CSRF se rechaza', $sigue['estado'] === 200);
$r = pedir("$base?action=salir_gimnasio", ['_csrf' => testigoPanel($ck)], $ck);
comprobar('salir del gimnasio lleva a la pantalla inicial',
    strpos($r['destino'], 'action=login') !== false && strpos($r['destino'], 'login_gimnasio') === false,
    "-> {$r['destino']}");

$r = pedir("$base?action=login_gimnasio", null, $ck);
comprobar('ya no se puede volver al nivel 2 sin identificarse',
    strpos($r['destino'], 'action=login') !== false, "-> {$r['destino']}");

$r = pedir("$base?action=admin", null, $ck);
comprobar('ni al panel', strpos($r['destino'], 'action=login') !== false, "-> {$r['destino']}");

echo "\n== EL ACCESO NO REVELA QUÉ SEDES EXISTEN ==\n";
// La pantalla inicial lleva la marca de la instalación (APP_NOMBRE y APP_LOGO):
// es la puerta del propio cliente, así que su nombre sí puede salir. Lo que no
// debe verse es el resto de sedes del grupo, que es lo que se comprueba aquí.
$r = pedir("$base?action=login", null, galletaNueva());
comprobar('la pantalla inicial no lista las sedes',
    strpos($r['cuerpo'], 'Sede Norte') === false
    && strpos($r['cuerpo'], 'sede.norte@gmail.com') === false);

echo "\n== EL ACCESO EXIGE TESTIGO CSRF ==\n";
// Sin testigo, un formulario alojado en otra web no puede meter a nadie en una
// sesión ajena. Se manda el POST a pelo, con credenciales buenas.
$ck = galletaNueva();
pedir("$base?action=login", null, $ck);   // abre sesión y crea el testigo
$r = pedir("$base?action=autenticar_gimnasio",
    ['email' => 'cleto.reyes.villaviciosa@gmail.com', 'contrasena' => 'cleto2026', '_csrf' => ''], $ck);
comprobar('sin testigo no se identifica el gimnasio',
    strpos($r['destino'], 'action=login') !== false
    && strpos($r['destino'], 'login_gimnasio') === false, "-> {$r['destino']}");

// Y con testigo, lo de siempre.
$r = pedir("$base?action=autenticar_gimnasio",
    ['email' => 'cleto.reyes.villaviciosa@gmail.com', 'contrasena' => 'cleto2026'], $ck);
comprobar('con testigo entra con normalidad',
    strpos($r['destino'], 'login_gimnasio') !== false, "-> {$r['destino']}");

$r = pedir("$base?action=autenticar",
    ['usuario' => 'daniel', 'contrasena' => '1234', '_csrf' => 'no-vale'], $ck);
comprobar('sin testigo tampoco entra el empleado',
    strpos($r['destino'], 'action=admin') === false, "-> {$r['destino']}");

echo "\n== AUTORIZACIÓN Y CSRF DEL PANEL ==\n";
$ckRecepcion = entrarGimnasio(CLETO_EMAIL, CLETO_CLAVE);
pedir("$base?action=autenticar", ['usuario' => 'kevin', 'contrasena' => '1234'], $ckRecepcion);
$r = pedir("$base?action=admin_productos", null, $ckRecepcion);
comprobar('recepción no puede gestionar productos', $r['estado'] === 403, "-> HTTP {$r['estado']}");
$r = pedir("$base?action=admin_importaciones", null, $ckRecepcion);
comprobar('recepción no puede gestionar importaciones', $r['estado'] === 403, "-> HTTP {$r['estado']}");
$r = pedir("$base?action=admin_venta_anular", ['id_venta' => 1, '_csrf' => testigoPanel($ckRecepcion)], $ckRecepcion);
comprobar('recepción no puede anular ventas', $r['estado'] === 403, "-> HTTP {$r['estado']}");

$ckAdmin = entrarGimnasio(CLETO_EMAIL, CLETO_CLAVE);
pedir("$base?action=autenticar", ['usuario' => 'daniel', 'contrasena' => '1234'], $ckAdmin);
$stockAntes = (int) Database::getInstance()->getConnection()->query('SELECT stock FROM producto WHERE id_producto = 1')->fetchColumn();
pedir("$base?action=admin_productos", [
    'accion' => 'actualizar_stock', 'id_producto' => 1, 'stock' => $stockAntes + 999, '_csrf' => 'incorrecto'
], $ckAdmin);
$stockDespues = (int) Database::getInstance()->getConnection()->query('SELECT stock FROM producto WHERE id_producto = 1')->fetchColumn();
comprobar('un POST sensible con CSRF incorrecto no cambia stock', $stockAntes === $stockDespues);
$r = pedir("$base?action=admin_remesa_descargar&id=1", null, $ckAdmin);
comprobar('descarga de remesa por GET sin CSRF se rechaza',
    strpos($r['destino'], 'action=admin_remesas') !== false && stripos($r['cuerpo'], 'Content-Type: application/xml') === false,
    "-> HTTP {$r['estado']} {$r['destino']}");
$r = pedir("$base?action=admin_exportar_ventas_csv&desde=2026-01-01&hasta=2026-12-31", null, $ckAdmin);
comprobar('exportación CSV por GET sin CSRF se rechaza',
    strpos($r['destino'], 'action=admin_reportes') !== false && stripos($r['cuerpo'], 'Content-Type: text/csv') === false,
    "-> HTTP {$r['estado']} {$r['destino']}");

$ckDireccion = entrarGimnasio(CLETO_EMAIL, CLETO_CLAVE);
pedir("$base?action=autenticar", ['usuario' => 'empresa', 'contrasena' => 'admin123'], $ckDireccion);
$r = pedir("$base?action=admin_importaciones", null, $ckDireccion);
comprobar('dirección puede abrir importaciones', $r['estado'] === 200);

$r = pedir("$base?action=logout", ['_csrf' => testigoPanel($ckAdmin)], $ckAdmin);
$r = pedir("$base?action=admin", null, $ckAdmin);
comprobar('tras logout no se puede reutilizar el panel', strpos($r['destino'], 'action=login') !== false);
if ($forceFailure) {
    comprobar('fallo deliberado del contrato HTTP', false);
}

echo "\nRESUMEN: $ok correctas, $fallos fallidas\n";
exit($fallos === 0 ? 0 : 1);
