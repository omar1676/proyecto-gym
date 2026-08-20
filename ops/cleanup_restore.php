<?php
require_once dirname(__DIR__) . '/app/config/config.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$target = (string)($argv[1] ?? '');
if (!in_array('--confirm', $argv, true) || !preg_match('/^[a-zA-Z0-9_]*restore[a-zA-Z0-9_]*$/i', $target) || in_array($target,[DB_NAME,DB_NAME_PRUEBAS],true)) {
    fwrite(STDERR,"Uso: php ops/cleanup_restore.php nombre_con_restore --confirm\n"); exit(1);
}
try {
    $db = new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';charset='.DB_CHARSET,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('DROP DATABASE IF EXISTS `'.$target.'`');
    echo "Base temporal eliminada: {$target}\n";
} catch(Throwable $e) { fwrite(STDERR,"No se pudo eliminar la base temporal.\n"); exit(1); }
