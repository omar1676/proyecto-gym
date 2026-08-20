<?php
require_once dirname(__DIR__) . '/app/config/config.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$results = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$results): void { $results[] = compact('name','ok','detail'); };
$check('PHP >= 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION);
foreach (['pdo_mysql','mbstring','openssl','fileinfo','curl','dom','simplexml','zlib'] as $ext) $check('ext-' . $ext, extension_loaded($ext));
$check('APP_ENV válido', in_array(APP_ENV, ['development','test','production'], true), APP_ENV);
$check('base test separada', DB_NAME_PRUEBAS !== '' && DB_NAME_PRUEBAS !== DB_NAME);
$check('URL HTTPS producción', APP_ENV !== 'production' || str_starts_with(APP_URL, 'https://'), APP_URL);
$check('.env no versionado', trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' ls-files .env')) === '');
$check('document root public', realpath($root . '/public') !== false);
$check('copia externa configurada', COPIAS_EXTERNAS_DIR !== '' && is_dir(COPIAS_EXTERNAS_DIR), COPIAS_EXTERNAS_DIR === '' ? 'PENDIENTE' : COPIAS_EXTERNAS_DIR);
$check('sin backup local de secretos en release', !is_file($root . '/.env.produccion.bak'));
$check('sin repositorio de respaldo en release', !is_dir($root . '/.git.bak'));
$check('sin ZIP histórico de inscripciones', !is_file($root . '/recursos/inscripciones.zip'));
$check('sin instalador en release', !is_file($root . '/instalar.php'));
foreach ([LOG_DIR, COPIAS_DIR, $root . '/public/assets/fotos', $root . '/public/assets/productos', $root . '/public/assets/gimnasios'] as $dir) {
    $check('escritura ' . str_replace($root, '<root>', $dir), is_dir($dir) && is_writable($dir));
}
if (SESSION_DIR !== '') $check('escritura SESSION_DIR', is_dir(SESSION_DIR) && is_writable(SESSION_DIR));
$check('SMTP configurado', MAIL_SMTP_HOST !== '' && MAIL_FROM !== '', MAIL_SMTP_HOST === '' ? 'NO VERIFICADO' : 'configurado');
foreach ($results as $r) printf("[%s] %s%s\n", $r['ok'] ? 'OK' : 'PENDIENTE', $r['name'], $r['detail'] !== '' ? ' — ' . $r['detail'] : '');
$productionOnly = ['SMTP configurado','copia externa configurada','sin backup local de secretos en release','sin repositorio de respaldo en release','sin ZIP histórico de inscripciones','sin instalador en release'];
$requiredFailures = array_filter($results, fn($r) => !$r['ok'] && (APP_ENV === 'production' || !in_array($r['name'], $productionOnly, true)));
exit($requiredFailures ? 1 : 0);
