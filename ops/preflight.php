<?php
require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/TechnicalAlertMailer.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$results = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$results): void { $results[] = compact('name','ok','detail'); };
$check('PHP >= 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION);
foreach (['pdo_mysql','mbstring','openssl','fileinfo','curl','dom','simplexml','zlib'] as $ext) $check('ext-' . $ext, extension_loaded($ext));
$check('APP_ENV válido', in_array(APP_ENV, ['development','test','staging','production'], true), APP_ENV);
$check('release identificada', !in_array(APP_ENV, ['staging','production'], true) || APP_RELEASE !== '', APP_RELEASE === '' ? 'PENDIENTE' : APP_RELEASE);
$check('base test separada', DB_NAME_PRUEBAS !== '' && DB_NAME_PRUEBAS !== DB_NAME);
$check('URL HTTPS entorno protegido', !in_array(APP_ENV, ['staging','production'], true) || str_starts_with(APP_URL, 'https://'), APP_URL);
$check('base staging identificable', APP_ENV !== 'staging' || (stripos(DB_NAME, 'staging') !== false && DB_NAME !== DB_NAME_PRUEBAS), DB_NAME);
$check('acceso físico deshabilitado en staging', APP_ENV !== 'staging' || ACCESS_CONTROL_MODE === 'disabled', ACCESS_CONTROL_MODE);
$check('.env no versionado', trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' ls-files .env')) === '');
$check('document root public', realpath($root . '/public') !== false);
$externalMarkerOk = false;
$externalMarker = rtrim(BACKUP_EXTERNAL_VERIFY_DIR, '/\\') . '/external_backup_success.json';
if (BACKUP_EXTERNAL_VERIFY_DIR !== '' && is_file($externalMarker)) {
    $markerData = json_decode((string) file_get_contents($externalMarker), true);
    $verifiedAt = strtotime((string) ($markerData['verified_at_utc'] ?? ''));
    $externalMarkerOk = is_array($markerData)
        && ($markerData['encrypted'] ?? false) === true
        && $verifiedAt !== false
        && $verifiedAt >= time() - 36 * 3600;
}
$externalConfigured = (COPIAS_EXTERNAS_DIR !== '' && is_dir(COPIAS_EXTERNAS_DIR))
    || (BACKUP_EXTERNAL_ENABLED && BACKUP_EXTERNAL_ENCRYPTED && BACKUP_EXTERNAL_REMOTE !== ''
        && ((BACKUP_EXTERNAL_CONFIG !== '' && is_file(BACKUP_EXTERNAL_CONFIG)) || $externalMarkerOk));
$check('copia externa configurada', $externalConfigured, $externalConfigured ? 'configurada' : 'PENDIENTE');
$check('sin backup local de secretos en release', !is_file($root . '/.env.produccion.bak'));
$check('sin repositorio de respaldo en release', !is_dir($root . '/.git.bak'));
$check('sin ZIP histórico de inscripciones', !is_file($root . '/recursos/inscripciones.zip'));
$check('sin instalador en release', !is_file($root . '/instalar.php'));
foreach ([LOG_DIR, COPIAS_DIR, IMPORT_DIR, PRIVATE_PHOTO_DIR, $root . '/public/assets/productos', $root . '/public/assets/gimnasios'] as $dir) {
    $check('escritura ' . str_replace($root, '<root>', $dir), is_dir($dir) && is_writable($dir));
}
foreach (array_filter([MONITOR_STATE_DIR, BACKUP_EXTERNAL_VERIFY_DIR]) as $dir) {
    $check('escritura ' . str_replace($root, '<root>', $dir), is_dir($dir) && is_writable($dir));
}
if (SESSION_DIR !== '') $check('escritura SESSION_DIR', is_dir(SESSION_DIR) && is_writable(SESSION_DIR));
$check('SESSION_DIR propio de staging', APP_ENV !== 'staging' || (SESSION_DIR !== '' && stripos(SESSION_DIR, 'staging') !== false), SESSION_DIR ?: 'PENDIENTE');
$check('monitor HTTPS staging', APP_ENV !== 'staging' || (MONITOR_URL !== '' && str_starts_with(MONITOR_URL, 'https://')), MONITOR_URL ?: 'PENDIENTE');
$paths = [LOG_DIR, COPIAS_DIR, IMPORT_DIR, SESSION_DIR];
$paths = array_values(array_filter(array_map(static fn($path) => rtrim(str_replace('\\', '/', (string) $path), '/'), $paths)));
$check('almacenamientos staging separados', APP_ENV !== 'staging' || (count($paths) === 4 && count(array_unique($paths)) === 4), implode(', ', $paths));
$check('SMTP configurado', MAIL_SMTP_HOST !== '' && MAIL_FROM !== '', MAIL_SMTP_HOST === '' ? 'NO VERIFICADO' : 'configurado');
$check('SMTP alertas configurado', TechnicalAlertMailer::configured(), TechnicalAlertMailer::configured() ? 'configurado y destinatario permitido' : 'NO VERIFICADO');
$check('correo staging restringido', APP_ENV !== 'staging' || STAGING_MAIL_ALLOWLIST === '' || !str_contains(STAGING_MAIL_ALLOWLIST, '*'), STAGING_MAIL_ALLOWLIST === '' ? 'bloqueado' : 'allowlist exacta');
foreach ($results as $r) printf("[%s] %s%s\n", $r['ok'] ? 'OK' : 'PENDIENTE', $r['name'], $r['detail'] !== '' ? ' — ' . $r['detail'] : '');
$productionOnly = ['SMTP configurado','SMTP alertas configurado','copia externa configurada','sin backup local de secretos en release','sin repositorio de respaldo en release','sin ZIP histórico de inscripciones','sin instalador en release'];
$strictEnvironment = in_array(APP_ENV, ['staging', 'production'], true);
$requiredFailures = array_filter($results, fn($r) => !$r['ok'] && ($strictEnvironment ? !in_array($r['name'], ['SMTP configurado', 'SMTP alertas configurado'], true) : !in_array($r['name'], $productionOnly, true)));
exit($requiredFailures ? 1 : 0);
