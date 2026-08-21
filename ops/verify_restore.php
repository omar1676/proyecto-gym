<?php
require_once dirname(__DIR__) . '/app/config/config.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$target = (string) (($argv[1] ?? ''));
$filesTarget = '';
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--files=')) $filesTarget = substr($argument, 8);
}
if (!preg_match('/^[a-zA-Z0-9_]*restore[a-zA-Z0-9_]*$/i', $target) || in_array($target, [DB_NAME, DB_NAME_PRUEBAS], true)) { fwrite(STDERR, "Destino restore no válido.\n"); exit(1); }
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
try {
    $baseDsn = 'mysql:host='.DB_HOST.';port='.DB_PORT.';charset='.DB_CHARSET;
    $source = new PDO($baseDsn.';dbname='.DB_NAME, DB_USER, DB_PASS, $options);
    $restored = new PDO($baseDsn.';dbname='.$target, DB_USER, DB_PASS, $options);
    $tablesSource = $source->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $tablesRestored = $restored->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    sort($tablesSource); sort($tablesRestored);
    $checks = ['same_tables' => $tablesSource === $tablesRestored];
    $counts = [];
    foreach (['usuario','gimnasio','producto','venta','socio_membresia','remesa','obligacion_pago','cobro','caja_sesion','caja_movimiento','log_actividad','schema_migrations'] as $table) {
        if (!in_array($table, $tablesSource, true)) continue;
        $sourceCount = (int)$source->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $restoredCount = (int)$restored->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $counts[$table] = ['source'=>$sourceCount,'restored'=>$restoredCount];
        $checks['rows_'.$table] = $sourceCount === $restoredCount;
    }
    if (in_array('schema_migrations', $tablesSource, true)) {
        $sourceMigrations = $source->query('SELECT migration, checksum FROM schema_migrations ORDER BY migration')->fetchAll(PDO::FETCH_KEY_PAIR);
        $restoredMigrations = $restored->query('SELECT migration, checksum FROM schema_migrations ORDER BY migration')->fetchAll(PDO::FETCH_KEY_PAIR);
        $checks['schema_migrations_exact'] = $sourceMigrations === $restoredMigrations;
    }
    $files = null;
    if ($filesTarget !== '') {
        $root = realpath($filesTarget);
        $manifestPath = $root ? $root . DIRECTORY_SEPARATOR . 'BACKUP_MANIFEST.json' : '';
        $manifest = $manifestPath !== '' && is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
        $files = ['manifest_entries'=>0,'verified'=>0];
        $filesOk = is_array($manifest) && isset($manifest['files']) && is_array($manifest['files']);
        if ($filesOk) {
            $files['manifest_entries'] = count($manifest['files']);
            foreach ($manifest['files'] as $entry) {
                $relative = str_replace('\\', '/', (string)($entry['path'] ?? ''));
                if ($relative === '' || str_contains($relative, '../') || str_starts_with($relative, '/')) { $filesOk = false; break; }
                $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (!is_file($path)
                    || filesize($path) !== (int)($entry['bytes'] ?? -1)
                    || !hash_equals(strtolower((string)($entry['sha256'] ?? '')), strtolower((string)hash_file('sha256', $path)))) {
                    $filesOk = false; break;
                }
                $files['verified']++;
            }
        }
        $checks['files_manifest_and_hashes'] = $filesOk;
    }
    echo json_encode(['status'=>in_array(false,$checks,true)?'mismatch':'verified','checks'=>$checks,'counts'=>$counts,'files'=>$files], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(in_array(false,$checks,true)?1:0);
} catch (Throwable $e) { fwrite(STDERR,"VERIFICACIÓN FALLIDA: {$e->getMessage()}\n"); exit(1); }
