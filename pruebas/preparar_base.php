<?php
/**
 * preparar_base.php — crea (o rehace) la base de datos de pruebas.
 *
 *   php pruebas/preparar_base.php
 *
 * Copia la estructura y los datos de la base de trabajo a DB_NAME_PRUEBAS.
 * Se hace con SQL puro (CREATE TABLE ... LIKE + INSERT ... SELECT) en vez de
 * con mysqldump para que funcione igual en cualquier alojamiento, tenga o no
 * acceso a las herramientas de línea de comandos de MySQL.
 *
 * Es destructivo SOBRE LA BASE DE PRUEBAS y solo sobre ella: lo primero que
 * comprueba es que el nombre de destino no sea el de la base de trabajo.
 */

require_once __DIR__ . '/../app/config/config.php';

if (PHP_SAPI !== 'cli') {
    exit("Este script solo se ejecuta por línea de comandos.\n");
}
if (DB_NAME_PRUEBAS === '' || DB_NAME_PRUEBAS === DB_NAME) {
    exit("DB_NAME_PRUEBAS no puede estar vacío ni ser igual que DB_NAME.\n");
}

$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET;
try {
    $db = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    exit('No se pudo conectar a MySQL: ' . $e->getMessage() . "\n");
}

$origen  = '`' . str_replace('`', '', DB_NAME) . '`';
$destino = '`' . str_replace('`', '', DB_NAME_PRUEBAS) . '`';

echo "Copiando " . DB_NAME . " → " . DB_NAME_PRUEBAS . "\n";

$db->exec("DROP DATABASE IF EXISTS {$destino}");
$db->exec("CREATE DATABASE {$destino} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// Sin comprobar las claves foráneas mientras se copia: el orden de las tablas
// es alfabético y una hija puede llegar antes que su madre.
$db->exec('SET FOREIGN_KEY_CHECKS = 0');

$tablas = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = " . $db->quote(DB_NAME) . "
                        AND TABLE_TYPE = 'BASE TABLE'
                      ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);

if (empty($tablas)) {
    exit("La base de origen (" . DB_NAME . ") no tiene tablas.\n");
}

$totalFilas = 0;
foreach ($tablas as $tabla) {
    $t = '`' . str_replace('`', '', $tabla) . '`';
    $db->exec("CREATE TABLE {$destino}.{$t} LIKE {$origen}.{$t}");
    $db->exec("INSERT INTO {$destino}.{$t} SELECT * FROM {$origen}.{$t}");
    $filas = (int) $db->query("SELECT COUNT(*) FROM {$destino}.{$t}")->fetchColumn();
    $totalFilas += $filas;
    printf("  %-22s %6d filas\n", $tabla, $filas);
}

/*
 * CREATE TABLE ... LIKE copia columnas e índices, pero NO las claves foráneas.
 * Sin ellas, la base de pruebas se comporta distinto que la de verdad: admite
 * filas huérfanas y no cascadea los borrados, así que una prueba podría pasar
 * aquí y fallar en producción. Se rehacen leyendo las restricciones del origen.
 */
$claves = $db->query(
    "SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = " . $db->quote(DB_NAME) . "
       AND REFERENCED_TABLE_NAME IS NOT NULL"
)->fetchAll();

$reglas = $db->query(
    "SELECT CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE
     FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = " . $db->quote(DB_NAME)
)->fetchAll(PDO::FETCH_UNIQUE);

$puestas = 0;
foreach ($claves as $k) {
    $borrado = $reglas[$k['CONSTRAINT_NAME']]['DELETE_RULE'] ?? 'RESTRICT';
    $sql = "ALTER TABLE {$destino}.`{$k['TABLE_NAME']}`
            ADD CONSTRAINT `{$k['CONSTRAINT_NAME']}`
            FOREIGN KEY (`{$k['COLUMN_NAME']}`)
            REFERENCES {$destino}.`{$k['REFERENCED_TABLE_NAME']}` (`{$k['REFERENCED_COLUMN_NAME']}`)
            ON DELETE {$borrado}";
    try {
        $db->exec($sql);
        $puestas++;
    } catch (PDOException $e) {
        // Una clave que no entra suele significar datos huérfanos en el origen:
        // se avisa y se sigue, en vez de dejar la base a medio hacer.
        echo "  aviso: no se pudo recrear {$k['CONSTRAINT_NAME']} ({$e->getMessage()})\n";
    }
}

$db->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "\nListo: " . count($tablas) . " tablas, " . $totalFilas . " filas, " . $puestas . " claves foráneas.\n";
echo "Ejecuta las pruebas con normalidad; ya no tocan " . DB_NAME . ".\n";
