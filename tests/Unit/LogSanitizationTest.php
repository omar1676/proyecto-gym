<?php

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gimnera-log-sanitize-' . bin2hex(random_bytes(6));
mkdir($base, 0700, true);
define('APP_ENV', 'test');
define('LOG_DIR', $base);
define('LOG_MAX_BYTES', 1048576);
require_once dirname(__DIR__, 2) . '/app/helpers/SafeException.php';

$ok = 0;
$fail = 0;
$check = static function (string $description, bool $condition) use (&$ok, &$fail): void {
    if ($condition) { $ok++; echo "  OK   {$description}\n"; }
    else { $fail++; echo "  FALLO {$description}\n"; }
};

$sensitive = implode(' ', [
    'email=persona.sintetica@example.invalid',
    'iban=ES9121000418450200051332',
    'dni=12345678Z',
    'token=synthetic-token-value-123456789',
    'password=NoDebeAparecer!234',
    "UPDATE usuario SET email='persona.sintetica@example.invalid' WHERE dni='12345678Z'",
    'C:\\Users\\persona\\proyecto\\archivo.php',
    '/var/www/gimnasio/shared/.env',
]);
$exception = new PDOException($sensitive, 23000);
SafeException::log('synthetic_exception', $exception, 'LogSanitizationTest');
AppLogger::error('synthetic_direct_context', ['free_text' => $sensitive]);

$files = glob($base . DIRECTORY_SEPARATOR . '*.log') ?: [];
$payload = '';
foreach ($files as $file) $payload .= (string) file_get_contents($file);
$check('se escriben eventos estructurados de prueba', $payload !== '' && str_contains($payload, 'synthetic_exception'));
foreach (['persona.sintetica@example.invalid','ES9121000418450200051332','12345678Z',
          'synthetic-token-value-123456789','NoDebeAparecer!234','UPDATE usuario SET',
          'C:\\Users\\persona','/var/www/gimnasio'] as $secret) {
    $check('log no contiene dato sensible: ' . substr(hash('sha256', $secret), 0, 8), !str_contains($payload, $secret));
}
$decoded = array_values(array_filter(array_map(
    static fn(string $line): mixed => json_decode($line, true), preg_split('/\R/', trim($payload)) ?: []
)));
$exceptionEvent = null;
foreach ($decoded as $event) {
    if (($event['event'] ?? '') === 'synthetic_exception') { $exceptionEvent = $event; break; }
}
$check('excepción conserva clase, código seguro y fingerprint', is_array($exceptionEvent)
    && ($exceptionEvent['context']['error_class'] ?? '') === PDOException::class
    && (string) ($exceptionEvent['context']['safe_code'] ?? '') === '23000'
    && preg_match('/^[a-f0-9]{64}$/', (string) ($exceptionEvent['context']['error_fingerprint'] ?? '')) === 1);
$check('logs neutralizan saltos y no permiten inyección de entradas', substr_count(trim($payload), "\n") === count($decoded) - 1);

foreach ($files as $file) @unlink($file);
@rmdir($base);
echo "RESUMEN: {$ok} correctas, {$fail} fallidas\n";
exit($fail === 0 ? 0 : 1);
