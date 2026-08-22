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
require_once __DIR__ . '/../app/helpers/BackupStorage.php';
require_once __DIR__ . '/../app/helpers/BackupManifest.php';
require_once __DIR__ . '/../app/helpers/AppLogger.php';
require_once __DIR__ . '/../app/helpers/RequestContext.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "Solo por línea de comandos.\n");
    exit(1);
}
RequestContext::bootstrap('CRON');

$inicio  = microtime(true);
$destino = rtrim(COPIAS_DIR, "/\\");

try { BackupStorage::ensureDirectory($destino); }
catch (Throwable $e) { AppLogger::error('backup_database_failed', ['reason' => $e->getMessage()]); fwrite(STDERR, "ERROR: {$e->getMessage()}\n"); exit(1); }

// Si la carpeta acabara dentro de public/ por un COPIAS_DIR mal puesto, el
// volcado entero (con los IBAN dentro) quedaría descargable desde la web.
protegerCarpeta($destino);

$archivo = BackupStorage::uniqueArtifactPath($destino, 'backup_db_', '.sql');

// Con --php se salta mysqldump. Sirve para comprobar que la vía de emergencia
// funciona en este servidor ANTES de necesitarla.
$forzarPhp = in_array('--php', $argv ?? [], true);
$metodo = 'mysqldump-single-transaction';
$hecho = !$forzarPhp && volcarConMysqldump($archivo);
if (!$hecho) {
    $metodo = 'php-consistent-snapshot';
    $hecho = volcarConPhp($archivo);
}

if (!$hecho || !is_file($archivo) || filesize($archivo) === 0) {
    @unlink($archivo);
    AppLogger::error('backup_database_failed', ['reason' => 'dump_failed']);
    fwrite(STDERR, "ERROR: no se pudo generar la copia.\n");
    exit(1);
}

$archivo = comprimir($archivo);
if (!validarDump($archivo)) {
    @unlink($archivo);
    AppLogger::error('backup_database_failed', ['reason' => 'validation_failed']);
    fwrite(STDERR, "ERROR: la copia no superó la validación de contenido.\n");
    exit(1);
}
try {
    $hash = BackupStorage::checksum($archivo);
    BackupManifest::writeForArtifact($archivo, 'database', [
        'dump_method' => $metodo,
        'consistent_snapshot' => true,
    ]);
    $externa = BackupStorage::externalCopy($archivo);
    $borradas = BackupStorage::rotate($destino, 'backup_db_');
    if ($externa !== null) BackupStorage::rotate(COPIAS_EXTERNAS_DIR, 'backup_db_');
} catch (Throwable $e) {
    AppLogger::error('backup_database_failed', ['reason' => $e->getMessage()]);
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}

AppLogger::info('backup_database_ok', [
    'file' => basename($archivo), 'bytes' => filesize($archivo),
    'sha256' => $hash, 'method' => $metodo, 'external' => $externa !== null, 'deleted' => $borradas,
]);

printf(
    "Copia verificada: %s (%s), SHA-256 %s, externa %s, en %.1f s. Rotadas: %d\n",
    basename($archivo), tamanoLegible(filesize($archivo)), $hash,
    $externa !== null ? 'sí' : 'NO CONFIGURADA', microtime(true) - $inicio, $borradas
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
        $orden = [$binario, '--host=' . DB_HOST, '--port=' . DB_PORT, '--user=' . DB_USER,
            '--single-transaction', '--routines', '--default-character-set=utf8mb4', DB_NAME];
        $entorno = getenv();
        if (DB_PASS !== '') $entorno['MYSQL_PWD'] = DB_PASS;
        $pipes = [];
        $proceso = @proc_open($orden, [0=>['pipe','r'],1=>['file',$archivo,'wb'],2=>['pipe','w']], $pipes, null, $entorno);
        $salida = 1;
        if (is_resource($proceso)) {
            fclose($pipes[0]);
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $salida = proc_close($proceso);
        }
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

    fwrite($f, "-- Copia de " . DB_NAME . " generada UTC " . gmdate('Y-m-d H:i:s') . "\n");
    fwrite($f, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

    try {
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->beginTransaction();
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
        $db->commit();
        fclose($f);
        return true;

    } catch (\PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
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

function validarDump(string $archivo): bool
{
    if (!is_file($archivo) || filesize($archivo) < 200) return false;
    if (str_ends_with($archivo, '.gz')) {
        $f = @gzopen($archivo, 'rb');
        if (!$f) return false;
        $inicio = '';
        while (!gzeof($f) && strlen($inicio) < 1048576) $inicio .= gzread($f, 65536);
        gzclose($f);
    } else {
        $f = @fopen($archivo, 'rb');
        if (!$f) return false;
        $inicio = fread($f, 1048576);
        fclose($f);
    }
    return stripos($inicio, 'CREATE TABLE') !== false && stripos($inicio, 'SET ') !== false;
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
