<?php
require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/Mailer.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
if (APP_ENV !== 'staging' || !in_array('--confirm-staging-test', $argv, true)) {
    fwrite(STDERR, "La prueba exige staging y --confirm-staging-test.\n");
    exit(1);
}
if (!filter_var(MONITOR_ALERT_EMAIL, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "No hay destinatario técnico autorizado.\n");
    exit(1);
}

$html = '<p>Prueba sintética del canal técnico de alertas.</p>'
    . '<p>No representa una caída real y no contiene datos de clientes.</p>'
    . '<p>UTC: ' . htmlspecialchars(gmdate('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8') . '</p>';
$sent = Mailer::enviar(
    MONITOR_ALERT_EMAIL,
    '[GIMNERA STAGING TEST] Canal técnico de alertas',
    $html
);
if (!$sent) {
    fwrite(STDERR, "La alerta de prueba no fue aceptada por el canal configurado.\n");
    exit(1);
}
echo json_encode(['status' => 'sent', 'synthetic' => true], JSON_PRETTY_PRINT) . PHP_EOL;
