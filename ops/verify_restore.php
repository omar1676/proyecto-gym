<?php
require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/RestoreVerifier.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$target = (string) (($argv[1] ?? ''));
$filesTarget = '';
$artifact = '';
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--files=')) $filesTarget = substr($argument, 8);
    if (str_starts_with($argument, '--artifact=')) $artifact = substr($argument, 11);
}
if (!preg_match('/^[a-zA-Z0-9_]*restore[a-zA-Z0-9_]*$/i', $target) || in_array($target, [DB_NAME, DB_NAME_PRUEBAS], true)) { fwrite(STDERR, "Destino restore no válido.\n"); exit(1); }
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
try {
    $baseDsn = 'mysql:host='.DB_HOST.';port='.DB_PORT.';charset='.DB_CHARSET;
    $restored = new PDO($baseDsn.';dbname='.$target, DB_USER, DB_PASS, $options);
    $result = RestoreVerifier::verify($restored, dirname(__DIR__) . '/app/config', $artifact, $filesTarget);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($result['BACKUP_INTEGRITY'] ?? '') === 'OK' && ($result['SCHEMA_CURRENCY'] ?? '') !== 'FUTURE' ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "VERIFICACIÓN FALLIDA: integridad o acceso no demostrable.\n");
    exit(1);
}
