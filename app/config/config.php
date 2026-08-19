<?php

$envPath = __DIR__ . '/../../.env';

if (!file_exists($envPath)) {
    die('Archivo .env no encontrado. Copia .env.example a .env y configura tus valores.');
}

$lineas = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lineas as $linea) {
    $linea = trim($linea);
    if (substr($linea, 0, 1) === '#' || strpos($linea, '=') === false) continue;

    [$clave, $valor] = explode('=', $linea, 2);
    $clave = trim($clave);
    $valor = trim($valor, " \t\"'");

    $_ENV[$clave] = $valor;
}

$clavesEntorno = [
    'APP_ENV', 'APP_URL', 'APP_NOMBRE', 'APP_LOGO', 'APP_ZONA_HORARIA',
    'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET', 'DB_NAME_PRUEBAS',
    'SESION_MINUTOS', 'SESSION_DIR', 'MAIL_FROM', 'MAIL_NOMBRE', 'MAIL_SMTP_HOST', 'MAIL_SMTP_PUERTO',
    'MAIL_SMTP_USUARIO', 'MAIL_SMTP_CLAVE', 'MAIL_SMTP_SEGURIDAD',
    'COPIAS_DIR', 'COPIAS_EXTERNAS_DIR', 'COPIAS_DIARIAS', 'COPIAS_SEMANALES', 'COPIAS_MENSUALES',
    'LOG_DIR', 'LOG_DIAS', 'LOG_MAX_BYTES', 'LOG_ERRORS_HORA_MAX', 'DISCO_LIBRE_MINIMO_PCT', 'MONITOR_URL',
    'IMPORT_DIR', 'IMPORT_MAX_BYTES', 'IMPORT_MAX_ROWS', 'IMPORT_RETENTION_DAYS',
];
foreach ($clavesEntorno as $claveEntorno) {
    $valorEntorno = getenv($claveEntorno);
    if ($valorEntorno !== false && $valorEntorno !== '') $_ENV[$claveEntorno] = $valorEntorno;
}

define('DB_HOST',    $_ENV['DB_HOST']    ?? 'localhost');
define('DB_PORT',    $_ENV['DB_PORT']    ?? '3306');
define('DB_NAME',    $_ENV['DB_NAME']    ?? 'portal_de_cursos');
define('DB_USER',    $_ENV['DB_USER']    ?? 'root');
define('DB_PASS',    $_ENV['DB_PASS']    ?? '');
define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');

// Base de datos que usan las pruebas. Nunca debe ser la misma que DB_NAME: las
// suites borran filas para partir de un estado conocido. Se crea con
// `php pruebas/preparar_base.php`.
define('DB_NAME_PRUEBAS', $_ENV['DB_NAME_PRUEBAS'] ?? (DB_NAME . '_pruebas'));

define('APP_ENV',    $_ENV['APP_ENV']    ?? 'development');

// Zona horaria de todo el sistema. Sin fijarla, la caja "del día" y los
// vencimientos dependen de cómo esté configurado el servidor, que en
// alojamiento compartido suele ir en UTC.
date_default_timezone_set($_ENV['APP_ZONA_HORARIA'] ?? 'Europe/Madrid');

// URL base del portal (sin barra final). Todos los redirects y enlaces del panel
// se construyen a partir de aquí, así que basta cambiar APP_URL en el .env para
// mover la aplicación a otro dominio.
define('APP_URL',    rtrim($_ENV['APP_URL'] ?? 'http://portal.formacion.glitchlab.es', '/'));

// Nombre comercial del gimnasio. Aparece en el título de las páginas, el pie y
// los correos: cámbialo en el .env y se actualiza en todo el panel.
define('APP_NOMBRE', $_ENV['APP_NOMBRE'] ?? 'Gimnasio');

// Logo de la instalación: el archivo que hay dentro de public/assets/marca/.
// Se usa en la pantalla de acceso de la plataforma y en la cabecera del panel
// cuando no hay una sede con logo propio. Vacío deja el icono de siempre.
define('APP_LOGO', basename(trim((string) ($_ENV['APP_LOGO'] ?? ''))));

// Minutos de inactividad antes de cerrar la sesión del panel. En un mostrador
// abierto al público conviene que no sea muy alto.
define('SESION_MINUTOS', (int) ($_ENV['SESION_MINUTOS'] ?? 120));
define('SESSION_DIR', trim((string) ($_ENV['SESSION_DIR'] ?? '')));

/* --- Correo saliente ---------------------------------------------------------
 *
 * El remitente TIENE que ser una dirección del dominio desde el que se envía.
 * Con un From de otro dominio, el SPF del receptor no cuadra y los avisos de
 * vencimiento y los enlaces de recuperar contraseña acaban en spam o rebotan.
 *
 * Si se rellenan los datos SMTP se envía por ahí (lo recomendable en
 * alojamiento compartido); si no, se sigue usando mail() del servidor.
 */
define('MAIL_FROM',    $_ENV['MAIL_FROM']    ?? '');
define('MAIL_NOMBRE',  $_ENV['MAIL_NOMBRE']  ?? APP_NOMBRE);
define('MAIL_SMTP_HOST',   $_ENV['MAIL_SMTP_HOST']   ?? '');
define('MAIL_SMTP_PUERTO', (int) ($_ENV['MAIL_SMTP_PUERTO'] ?? 587));
define('MAIL_SMTP_USUARIO', $_ENV['MAIL_SMTP_USUARIO'] ?? '');
define('MAIL_SMTP_CLAVE',   $_ENV['MAIL_SMTP_CLAVE']   ?? '');
// 'tls' (STARTTLS, puerto 587), 'ssl' (puerto 465) o '' (sin cifrar).
define('MAIL_SMTP_SEGURIDAD', strtolower($_ENV['MAIL_SMTP_SEGURIDAD'] ?? 'tls'));

// Copias de seguridad: carpeta donde se dejan (fuera de public/ por defecto) y
// cuántos días se conservan antes de borrar las viejas.
define('COPIAS_DIR',  ($_ENV['COPIAS_DIR'] ?? '') ?: (__DIR__ . '/../../copias'));
define('COPIAS_DIAS', (int) ($_ENV['COPIAS_DIAS'] ?? 30));
define('COPIAS_EXTERNAS_DIR', trim((string) ($_ENV['COPIAS_EXTERNAS_DIR'] ?? '')));
define('COPIAS_DIARIAS', max(1, (int) ($_ENV['COPIAS_DIARIAS'] ?? 7)));
define('COPIAS_SEMANALES', max(0, (int) ($_ENV['COPIAS_SEMANALES'] ?? 4)));
define('COPIAS_MENSUALES', max(0, (int) ($_ENV['COPIAS_MENSUALES'] ?? 6)));

define('LOG_DIR', ($_ENV['LOG_DIR'] ?? '') ?: (__DIR__ . '/../../storage/logs'));
define('LOG_DIAS', max(1, (int) ($_ENV['LOG_DIAS'] ?? 30)));
define('LOG_MAX_BYTES', max(1048576, (int) ($_ENV['LOG_MAX_BYTES'] ?? 10485760)));
define('LOG_ERRORS_HORA_MAX', max(1, (int) ($_ENV['LOG_ERRORS_HORA_MAX'] ?? 50)));
define('DISCO_LIBRE_MINIMO_PCT', max(1, min(50, (int) ($_ENV['DISCO_LIBRE_MINIMO_PCT'] ?? 15))));
define('MONITOR_URL', rtrim((string) ($_ENV['MONITOR_URL'] ?? ''), '/'));

// Staging temporal de importaciones. Debe vivir fuera de public/ y se limpia
// automáticamente al vencer cada batch.
define('IMPORT_DIR', ($_ENV['IMPORT_DIR'] ?? '') ?: (__DIR__ . '/../../storage/imports'));
define('IMPORT_MAX_BYTES', max(1024, (int) ($_ENV['IMPORT_MAX_BYTES'] ?? 10485760)));
define('IMPORT_MAX_ROWS', max(1, min(100000, (int) ($_ENV['IMPORT_MAX_ROWS'] ?? 10000))));
define('IMPORT_RETENTION_DAYS', max(1, min(90, (int) ($_ENV['IMPORT_RETENTION_DAYS'] ?? 7))));

/* --- Credenciales de prueba (SOLO desarrollo) -------------------------------
 *
 * Rellenan de antemano los formularios de acceso para no teclearlos en cada
 * prueba. Viven en el .env (que está en .gitignore), nunca en el código.
 *
 * El candado importante es esta condición: fuera de APP_ENV=development las
 * constantes quedan vacías pase lo que pase. Aunque estas claves acabaran por
 * descuido en el .env del servidor, los formularios saldrían en blanco.
 * Para desactivarlas aquí basta con borrar las líneas DEV_ del .env.
 */
$esDesarrollo = APP_ENV === 'development';

define('DEV_GIMNASIO_EMAIL', $esDesarrollo ? ($_ENV['DEV_GIMNASIO_EMAIL'] ?? '') : '');
define('DEV_GIMNASIO_PASS',  $esDesarrollo ? ($_ENV['DEV_GIMNASIO_PASS']  ?? '') : '');
define('DEV_USUARIO',        $esDesarrollo ? ($_ENV['DEV_USUARIO']        ?? '') : '');
define('DEV_USUARIO_PASS',   $esDesarrollo ? ($_ENV['DEV_USUARIO_PASS']   ?? '') : '');

unset($esDesarrollo);
