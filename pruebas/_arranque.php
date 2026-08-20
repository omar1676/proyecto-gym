<?php
/**
 * _arranque.php — lo primero que carga cualquier suite de pruebas.
 *
 * Por qué existe: estas pruebas borran filas para partir de un estado conocido
 * (`DELETE FROM venta WHERE id_gimnasio > 1`, `DELETE FROM usuario WHERE
 * nombre_usuario LIKE 'test_%'`…). Mientras corrieran contra la base de
 * trabajo, ejecutar una suite en el servidor equivalía a borrar datos reales.
 *
 * Aquí se hacen tres cosas, en este orden:
 *   1. Definir MODO_PRUEBAS ANTES de que nadie abra la conexión, que es lo que
 *      hace que Database use DB_NAME_PRUEBAS.
 *   2. Negarse a arrancar en producción.
 *   3. Comprobar que la base a la que se ha conectado es de verdad la de
 *      pruebas y que existe. Mejor parar con un mensaje claro que empezar a
 *      borrar en el sitio equivocado.
 *
 * Excepción: pruebas/acceso.php NO usa este arranque. Es una prueba de
 * integración que habla por HTTP con el servidor de desarrollo, y ese servidor
 * usa la base de trabajo; de las dos tablas que limpia (los registros de
 * intentos fallidos) no depende ningún dato del negocio.
 */

putenv('APP_ENV=test');
define('MODO_PRUEBAS', true);

require_once __DIR__ . '/../app/config/config.php';

function pruebasAbortar(string $motivo): void
{
    fwrite(STDERR, "\n  NO SE EJECUTAN LAS PRUEBAS\n  " . $motivo . "\n\n");
    exit(1);
}

if (PHP_SAPI !== 'cli') {
    pruebasAbortar('Las pruebas solo se ejecutan por línea de comandos.');
}

if (APP_ENV !== 'test') {
    pruebasAbortar('APP_ENV debe ser test. Nunca se ejecutan pruebas con configuración de trabajo o producción.');
}

if (DB_NAME_PRUEBAS === '' || DB_NAME_PRUEBAS === DB_NAME) {
    pruebasAbortar(
        'DB_NAME_PRUEBAS no puede estar vacío ni ser igual que DB_NAME (' . DB_NAME . ").\n"
        . '  Ponlo en el .env y crea la base con: php pruebas/preparar_base.php'
    );
}

require_once __DIR__ . '/../app/config/database.php';

try {
    $baseReal = Database::getInstance()->nombreBase();
} catch (\Throwable $e) {
    pruebasAbortar('No se pudo conectar a la base de pruebas: ' . $e->getMessage());
}

if ($baseReal !== DB_NAME_PRUEBAS) {
    pruebasAbortar('Se ha conectado a "' . $baseReal . '" y se esperaba "' . DB_NAME_PRUEBAS . '".');
}

// Aviso visible para que nadie confunda una salida de pruebas con datos reales.
// Va por STDERR y no por la salida normal: render.php arranca una sesión justo
// después, y cualquier cosa escrita en STDOUT le impediría fijar las cookies.
fwrite(STDERR, '· base de pruebas: ' . $baseReal . "\n");

/** Limpieza explícita del histórico económico sintético antes de borrar ventas. */
function pruebasLimpiarVentas(PDO $db, string $condicionVenta): void
{
    $db->exec("DELETE cm FROM caja_movimiento cm INNER JOIN venta v ON v.id_venta=cm.id_venta WHERE " . $condicionVenta);
    $db->exec("DELETE vl FROM venta_linea vl INNER JOIN venta v ON v.id_venta=vl.id_venta WHERE " . $condicionVenta);
    $db->exec("DELETE v FROM venta v WHERE " . $condicionVenta);
}

/** Limpieza de contratos sintéticos respetando las FK del histórico económico. */
function pruebasLimpiarMembresias(PDO $db, string $condicionMembresia): void
{
    $db->exec("DELETE cm FROM caja_movimiento cm INNER JOIN cobro c ON c.id_cobro=cm.id_cobro INNER JOIN socio_membresia sm ON sm.id_socio_membresia=c.id_socio_membresia WHERE " . $condicionMembresia);
    $db->exec("DELETE c FROM cobro c INNER JOIN socio_membresia sm ON sm.id_socio_membresia=c.id_socio_membresia WHERE " . $condicionMembresia);
    $db->exec("DELETE o FROM obligacion_pago o INNER JOIN socio_membresia sm ON sm.id_socio_membresia=o.id_socio_membresia WHERE " . $condicionMembresia);
    $db->exec("DELETE sm FROM socio_membresia sm WHERE " . $condicionMembresia);
}

/** Limpieza de remesas sintéticas sin desactivar la integridad referencial. */
function pruebasLimpiarRemesas(PDO $db, string $condicionRemesa): void
{
    $db->exec("DELETE cm FROM caja_movimiento cm INNER JOIN cobro c ON c.id_cobro=cm.id_cobro INNER JOIN remesa_recibo rr ON rr.id_recibo=c.id_remesa_recibo INNER JOIN remesa r ON r.id_remesa=rr.id_remesa WHERE " . $condicionRemesa);
    $db->exec("DELETE c FROM cobro c INNER JOIN remesa_recibo rr ON rr.id_recibo=c.id_remesa_recibo INNER JOIN remesa r ON r.id_remesa=rr.id_remesa WHERE " . $condicionRemesa);
    $db->exec("DELETE rr FROM remesa_recibo rr INNER JOIN remesa r ON r.id_remesa=rr.id_remesa WHERE " . $condicionRemesa);
    $db->exec("DELETE r FROM remesa r WHERE " . $condicionRemesa);
}
