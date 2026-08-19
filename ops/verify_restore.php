<?php
require_once dirname(__DIR__) . '/app/config/config.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$target = (string) (($argv[1] ?? ''));
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
    foreach (['usuario','gimnasio','producto','venta','socio_membresia','remesa','schema_migrations'] as $table) {
        if (!in_array($table, $tablesSource, true)) continue;
        $checks['rows_'.$table] = (int)$source->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() === (int)$restored->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }
    echo json_encode(['status'=>in_array(false,$checks,true)?'mismatch':'verified','checks'=>$checks], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(in_array(false,$checks,true)?1:0);
} catch (Throwable $e) { fwrite(STDERR,"VERIFICACIÓN FALLIDA: {$e->getMessage()}\n"); exit(1); }
