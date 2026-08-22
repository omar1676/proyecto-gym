<?php

require_once dirname(__DIR__, 2) . '/app/helpers/BackupRetention.php';
require_once dirname(__DIR__, 2) . '/app/helpers/PrivatePhotoStorage.php';
require_once dirname(__DIR__, 2) . '/app/helpers/RequestContext.php';
require_once dirname(__DIR__, 2) . '/app/helpers/TechnicalAlertMailer.php';
require_once dirname(__DIR__, 2) . '/app/helpers/Retry.php';
require_once dirname(__DIR__, 2) . '/app/helpers/AlertPolicy.php';

$ok = 0; $fail = 0;
$check = static function (string $name, bool $condition) use (&$ok, &$fail): void {
    if ($condition) $ok++; else { $fail++; echo "FALLO: {$name}\n"; }
};

$items = [];
for ($day = 0; $day < 45; $day++) {
    $items[] = ['name' => 'set-' . $day, 'timestamp' => strtotime('2026-08-22 UTC') - $day * 86400, 'verified' => true];
}
$items[] = ['name' => 'incomplete', 'timestamp' => time() + 10, 'verified' => false];
$plan = BackupRetention::plan($items, 7, 4, 6);
$check('retención nunca elimina el backup válido más reciente', in_array('set-0', $plan['keep'], true) && !in_array('set-0', $plan['delete'], true));
$check('retención ignora artefactos incompletos y no los borra', in_array('incomplete', $plan['ignored'], true) && !in_array('incomplete', $plan['delete'], true));
$check('retención selecciona copias antiguas verificadas', count($plan['delete']) > 0);
$parsedSet = BackupRetention::timestampFromSetName('backup_set_2026-08-22_031500_123456Z_aabbccddeeff0011');
$check('retención obtiene UTC del nombre aunque R2 no tenga fecha de carpeta',
    $parsedSet === strtotime('2026-08-22 03:15:00 UTC'));
$check('retención preserva un nombre remoto de fecha ambigua',
    BackupRetention::timestampFromSetName('backup_set_sin_fecha') === null);
$setBase = 'backup_set_2026-08-22_031500_123456Z_aabbccddeeff0011.json';
$setFiles = [
    'backup_db.sql.gz', 'backup_db.sql.gz.manifest.json', 'backup_db.sql.gz.sha256',
    'backup_files.tar.gz', 'backup_files.tar.gz.manifest.json', 'backup_files.tar.gz.sha256',
    $setBase, $setBase . '.manifest.json', $setBase . '.sha256',
];
$check('retención distingue el manifiesto principal de su metadata', BackupRetention::verifiedSetFiles($setFiles));
$check('retención rechaza un set sin hash principal', !BackupRetention::verifiedSetFiles(array_slice($setFiles, 0, -1)));

$serviceRoot = dirname(__DIR__, 2) . '/ops/systemd';
$lockLine = '/usr/bin/flock -w 1800 /var/www/gimnasio/shared/.backup-operation.lock';
foreach (['gimnera-backup-db.service', 'gimnera-backup-files.service', 'gimnera-backup-external.service'] as $service) {
    $unit = (string) file_get_contents($serviceRoot . '/' . $service);
    $check($service . ' comparte el lock operativo de backups', str_contains($unit, $lockLine));
}

$photoDir = PRIVATE_PHOTO_DIR;
if (!is_dir($photoDir)) mkdir($photoDir, 0750, true);
$photo = 'f21-' . bin2hex(random_bytes(5)) . '.png';
$fake = 'f21-' . bin2hex(random_bytes(5)) . '.jpg';
file_put_contents($photoDir . DIRECTORY_SEPARATOR . $photo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
file_put_contents($photoDir . DIRECTORY_SEPARATOR . $fake, '<?php echo 1;');
try {
    $resolved = PrivatePhotoStorage::resolve($photo);
    $check('foto PNG real dentro del almacén privado es válida', $resolved !== null && $resolved['mime'] === 'image/png');
    $check('path traversal es rechazado', PrivatePhotoStorage::resolve('../' . $photo) === null);
    $check('MIME falso es rechazado', PrivatePhotoStorage::resolve($fake) === null);
    $check('extensión ejecutable es rechazada', PrivatePhotoStorage::resolve('payload.php') === null);
    $check('fichero inexistente es rechazado', PrivatePhotoStorage::resolve('missing.png') === null);
} finally {
    @unlink($photoDir . DIRECTORY_SEPARATOR . $photo);
    @unlink($photoDir . DIRECTORY_SEPARATOR . $fake);
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
$check('cliente no puede suplantar IP mediante X-Forwarded-For', RequestContext::clientIp() === '203.0.113.7');
RequestContext::resetForTests(null, 'WEB');
$first = RequestContext::correlationId();
$check('correlation ID generado por servidor es UUID', preg_match('/^[a-f0-9-]{36}$/', $first) === 1);
$check('correlation ID es estable durante la petición', RequestContext::correlationId() === $first);
$check('SMTP técnico incompleto queda bloqueado sin fallback', TechnicalAlertMailer::configured() === false);
$attempts = 0;
$value = Retry::limited(static function () use (&$attempts): string {
    $attempts++;
    if ($attempts < 3) throw new RuntimeException('fallo sintético');
    return 'ok';
}, 3, 0);
$check('transferencia externa reintenta de forma limitada', $value === 'ok' && $attempts === 3);
$attempts = 0; $exhausted = false;
try {
    Retry::limited(static function () use (&$attempts): void { $attempts++; throw new RuntimeException('fallo sintético'); }, 3, 0);
} catch (RuntimeException $e) { $exhausted = true; }
$check('fallo externo persistente termina en error tras tres intentos', $exhausted && $attempts === 3);
$now = time();
$firstAlert = AlertPolicy::decide([], 'CRITICAL', 'problem-a', $now, 3600);
$previous = ['status' => 'CRITICAL', 'fingerprint' => 'problem-a', 'last_attempted_at_utc' => gmdate('c', $now)];
$duplicate = AlertPolicy::decide($previous, 'CRITICAL', 'problem-a', $now + 60, 3600);
$changed = AlertPolicy::decide($previous, 'CRITICAL', 'problem-b', $now + 60, 3600);
$recovered = AlertPolicy::decide($previous, 'OK', hash('sha256', '[]'), $now + 60, 3600);
$check('primera incidencia exige alerta', $firstAlert['alert_required']);
$check('incidencia repetida queda suprimida durante cooldown', $duplicate['suppressed_by_cooldown'] && !$duplicate['alert_required']);
$check('incidencia distinta evita la deduplicación', $changed['alert_required']);
$check('recuperación genera notificación independiente', $recovered['recovered']);
$noChannelState = AlertPolicy::nextState([], 'CRITICAL', 'problem-a', $now, false, false);
$check('sin canal SMTP no se registra intento de entrega', $noChannelState['last_attempted_at_utc'] === null && $noChannelState['last_delivery_result'] === null);
$failedState = AlertPolicy::nextState([], 'CRITICAL', 'problem-a', $now, true, false);
$retryTooSoon = AlertPolicy::decide($failedState, 'CRITICAL', 'problem-a', $now + 299, 3600);
$retryDue = AlertPolicy::decide($failedState, 'CRITICAL', 'problem-a', $now + 301, 3600);
$check('fallo SMTP respeta retry corto sin tormenta inmediata', !$retryTooSoon['alert_required'] && $retryTooSoon['suppressed_by_cooldown']);
$check('fallo SMTP vuelve a intentarse tras cinco minutos', $retryDue['alert_required']);

echo "RESUMEN: {$ok} correctas, {$fail} fallidas\n";
exit($fail ? 1 : 0);
