<?php
require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/TechnicalAlertMailer.php';
RequestContext::bootstrap('SYSTEM');

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
if (APP_ENV !== 'staging' || !in_array('--confirm-staging-test', $argv, true)) {
    fwrite(STDERR, "La prueba exige staging y --confirm-staging-test.\n");
    exit(1);
}
if (!TechnicalAlertMailer::configured()) {
    fwrite(STDERR, "El canal SMTP técnico no está configurado.\n");
    exit(1);
}
$severity = 'TEST';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--severity=')) $severity = strtoupper(substr($argument, 11));
}
if (!in_array($severity, ['TEST', 'WARNING', 'CRITICAL'], true)) {
    fwrite(STDERR, "Severidad no válida.\n");
    exit(1);
}
$sent = TechnicalAlertMailer::send($severity, 'monitor-test', 'Prueba sintética del canal técnico; no representa una caída real.');
if (!$sent) {
    fwrite(STDERR, "La alerta de prueba no fue aceptada por el canal configurado.\n");
    exit(1);
}
echo json_encode(['status' => 'sent', 'synthetic' => true], JSON_PRETTY_PRINT) . PHP_EOL;
