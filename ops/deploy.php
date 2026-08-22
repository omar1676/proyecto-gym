<?php
require_once dirname(__DIR__) . '/app/config/config.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
$confirmed = in_array('--confirm-production', $args, true);
$stagingConfirmed = in_array('--confirm-staging', $args, true);
if (APP_ENV === 'production' && !$confirmed) { fwrite(STDERR, "Falta --confirm-production.\n"); exit(1); }
if (APP_ENV === 'staging' && !$stagingConfirmed) { fwrite(STDERR, "Falta --confirm-staging.\n"); exit(1); }
$php = PHP_BINARY; $root = dirname(__DIR__);
$commands = [
    [$php, $root . '/ops/preflight.php'],
    [$php, $root . '/cron/copia_seguridad.php'],
    [$php, $root . '/cron/copia_archivos.php'],
    [$php, $root . '/ops/schema_gate.php', '--mode=migrate'],
    array_values(array_filter([
        $php,
        $root . '/ops/migrate.php',
        APP_ENV === 'production' ? '--confirm-production' : (APP_ENV === 'staging' ? '--confirm-staging' : null),
    ])),
    [$php, $root . '/ops/status.php'],
    // Este gate ejecuta el runtime de la release candidata. Un smoke contra
    // APP_URL antes de activar current solo probaría la release antigua.
    [$php, $root . '/ops/runtime_check.php'],
];
foreach ($commands as $command) {
    echo "\n>>> " . basename($command[1]) . PHP_EOL;
    $escaped = implode(' ', array_map('escapeshellarg', $command));
    passthru($escaped, $exit);
    if ($exit !== 0) { fwrite(STDERR, "DESPLIEGUE DETENIDO en " . basename($command[1]) . "\n"); exit($exit); }
}
echo "\nCANDIDATA VERIFICADA. Activa current atómicamente y ejecuta después ops/smoke.php contra la URL externa; si falla, revierte current.\n";
