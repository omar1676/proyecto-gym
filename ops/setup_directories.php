<?php
require_once dirname(__DIR__) . '/app/config/config.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$dirs = array_values(array_filter([LOG_DIR, COPIAS_DIR, IMPORT_DIR, SESSION_DIR ?: null, $root.'/public/assets/fotos', $root.'/public/assets/productos', $root.'/public/assets/gimnasios', $root.'/public/assets/marca']));
foreach ($dirs as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) { fwrite(STDERR, "No se pudo crear {$dir}\n"); exit(1); }
    echo "[OK] {$dir}\n";
}
