<?php
require_once dirname(__DIR__) . '/app/config/config.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? APP_URL, '/');
if (!filter_var($base, FILTER_VALIDATE_URL)) { fwrite(STDERR, "URL no válida.\n"); exit(1); }
function getUrl(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10]);
    $body = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); curl_close($ch);
    return [$status, (string) $body, $error];
}
$tests = [
    ['health responde', '/health', fn($s,$b) => $s === 200 && str_contains($b, '"status":"ok"')],
    ['login carga', '/index.php?action=login', fn($s,$b) => $s === 200 && stripos($b, '<form') !== false],
    ['.env no se sirve', '/.env', fn($s,$b) => in_array($s, [403,404], true)],
    ['backup de .env no se sirve', '/.env.produccion.bak', fn($s,$b) => in_array($s, [403,404], true)],
    ['instalador no se sirve', '/instalar.php', fn($s,$b) => in_array($s, [403,404], true)],
    ['git no se sirve', '/.git/config', fn($s,$b) => in_array($s, [403,404], true)],
    ['app no se sirve', '/app/config/config.php', fn($s,$b) => in_array($s, [403,404], true)],
    ['tests no se sirven', '/tests/run.php', fn($s,$b) => in_array($s, [403,404], true)],
    ['pruebas no se sirven', '/pruebas/preparar_base.php', fn($s,$b) => in_array($s, [403,404], true)],
    ['copias no se sirven', '/copias/', fn($s,$b) => in_array($s, [403,404], true)],
    ['ops no se sirve', '/ops/status.php', fn($s,$b) => in_array($s, [403,404], true)],
];
$failed = 0;
foreach ($tests as [$name,$path,$assert]) { [$status,$body,$error] = getUrl($base . $path); $ok = $assert($status,$body); printf("[%s] %s — HTTP %d%s\n", $ok?'OK':'FALLO', $name, $status, $error?" ({$error})":''); if(!$ok)$failed++; }
exit($failed ? 1 : 0);
