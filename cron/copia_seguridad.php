<?php
/**
 * copia_seguridad.php — copia diaria de la base de datos.
 *
 *   php cron/copia_seguridad.php
 *
 * Deja un .sql (comprimido si el servidor tiene gzip) en COPIAS_DIR y borra
 * las que pasen de COPIAS_DIAS días.
 *
 * Usa mysqldump si está disponible, porque es lo más fiable y rápido. Si no lo
 * está —cosa habitual en alojamiento compartido— vuelca la base con PHP puro.
 * El objetivo es que la copia se haga sí o sí: una copia que solo funciona en
 * algunos servidores es una copia que el día malo no existe.
 *
 * Cron sugerido (todos los días a las 3:30):
 *   30 3 * * * /usr/bin/php /ruta/al/proyecto/cron/copia_seguridad.php
 */

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por línea de comandos.\n");
}

$inicio  = microtime(true);
$destino = rtrim(COPIAS_DIR, "/\\");

if (!is_dir($destino) && !@mkdir($destino, 0750, true)) {
    exit("No se pudo crear la carpeta de copias: {$destino}\n");
}
if (!is_writable($destino)) {
    exit("La carpeta de copias no tiene permisos de escritura: {$destino}\n");
}

// Si la carpeta acabara dentro de public/ por un COPIAS_DIR mal puesto, el
// volcado entero (con los IBAN dentro) quedaría descargable desde la web.
protegerCarpeta($destino);

$archivo = $destino . DIRECTORY_SEPARATOR . 'copia_' . DB_NAME . '_' . date('Y-m-d_His') . '.sql';

// Con --php se salta mysqldump. Sirve para comprobar que la vía de emergencia
// funciona en este servidor ANTES de necesitarla.
$forzarPhp = in_array('--php', $argv ?? [], true);
$hecho = (!$forzarPhp && volcarConMysqldump($archivo)) || volcarConPhp($archivo);

if (!$hecho || !is_file($archivo) || filesize($archivo) === 0) {
    @unlink($archivo);
    exit("ERROR: no se pudo generar la copia.\n");
}

$archivo = comprimir($archivo);
$borradas = rotar($destino, COPIAS_DIAS);

printf(
    "Copia hecha: %s (%s) en %.1f s. Copias antiguas borradas: %d\n",
    basename($archivo), tamanoLegible(filesize($archivo)), microtime(true) - $inicio, $borradas
);

/* ------------------------------------------------------------------------- */

function protegerCarpeta(string $dir): void
{
    $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\nOptions -Indexes\n");
    }
    $indice = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($indice)) {
        @file_put_contents($indice, '');
    }
}

/** Vía rápida: la herramienta oficial, si el servidor la tiene. */
function volcarConMysqldump(string $archivo): bool
{
    if (!function_exists('exec')) {
        return false;
    }
    $candidatos = [
        'mysqldump',
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        '/usr/bin/mysqldump',
        '/usr/local/bin/mysqldump',
    ];

    foreach ($candidatos as $binario) {
        $orden = escapeshellarg($binario)
            . ' --host=' . escapeshellarg(DB_HOST)
            . ' --port=' . escapeshellarg((string) DB_PORT)
            . ' --user=' . escapeshellarg(DB_USER)
            . (DB_PASS !== '' ? ' --password=' . escapeshellarg(DB_PASS) : '')
            . ' --single-transaction --routines --default-character-set=utf8mb4 '
            . escapeshellarg(DB_NAME)
            . ' > ' . escapeshellarg($archivo) . ' 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');

        $salida = 1;
        @exec($orden, $lineas, $salida);
        if ($salida === 0 && is_file($archivo) && filesize($archivo) > 0) {
            return true;
        }
        @unlink($archivo);
    }
    return false;
}

/** Vía lenta pero universal: se recorre la base con PDO. */
function volcarConPhp(string $archivo): bool
{
    $db = Database::getInstance()->getConnection();
    $f  = @fopen($archivo, 'w');
    if (!$f) {
        return false;
    }

    fwrite($f, "-- Copia de " . DB_NAME . " generada el " . date('d/m/Y H:i:s') . "\n");
    fwrite($f, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

    try {
        $tablas = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tablas as $tabla) {
            $crear = $db->query('SHOW CREATE TABLE `' . $tabla . '`')->fetch(PDO::FETCH_NUM);
            fwrite($f, "DROP TABLE IF EXISTS `{$tabla}`;\n" . $crear[1] . ";\n\n");

            // Por lotes: una tabla grande no debe cargarse entera en memoria.
            $filas = $db->query('SELECT * FROM `' . $tabla . '`');
            $lote  = [];
            while ($fila = $filas->fetch(PDO::FETCH_ASSOC)) {
                $valores = array_map(function ($v) use ($db) {
                    return $v === null ? 'NULL' : $db->quote((string) $v);
                }, $fila);
                $lote[] = '(' . implode(',', $valores) . ')';

                if (count($lote) >= 200) {
                    fwrite($f, "INSERT INTO `{$tabla}` VALUES\n" . implode(",\n", $lote) . ";\n");
                    $lote = [];
                }
            }
            if ($lote) {
                fwrite($f, "INSERT INTO `{$tabla}` VALUES\n" . implode(",\n", $lote) . ";\n");
            }
            fwrite($f, "\n");
        }

        fwrite($f, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($f);
        return true;

    } catch (\PDOException $e) {
        fclose($f);
        error_log('copia_seguridad: ' . $e->getMessage());
        return false;
    }
}

function comprimir(string $archivo): string
{
    if (!function_exists('gzopen')) {
        return $archivo;
    }
    $gz = @gzopen($archivo . '.gz', 'wb9');
    $in = @fopen($archivo, 'rb');
    if (!$gz || !$in) {
        if ($gz) gzclose($gz);
        if ($in) fclose($in);
        return $archivo;
    }
    while (!feof($in)) {
        gzwrite($gz, fread($in, 262144));
    }
    fclose($in);
    gzclose($gz);
    unlink($archivo);
    return $archivo . '.gz';
}

/** Borra las copias más viejas que $dias. Devuelve cuántas ha borrado. */
function rotar(string $dir, int $dias): int
{
    if ($dias <= 0) {
        return 0;
    }
    $limite  = time() - $dias * 86400;
    $borradas = 0;
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'copia_*.sql*') ?: [] as $archivo) {
        if (filemtime($archivo) < $limite && @unlink($archivo)) {
            $borradas++;
        }
    }
    return $borradas;
}

function tamanoLegible(int $bytes): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($u) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $u[$i];
}
