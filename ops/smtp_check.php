<?php
require_once dirname(__DIR__) . '/app/config/config.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (MAIL_SMTP_HOST === '') { echo "NO VERIFICADO: MAIL_SMTP_HOST no está configurado.\n"; exit(2); }
$security = MAIL_SMTP_SEGURIDAD;
$target = ($security === 'ssl' ? 'ssl://' : '') . MAIL_SMTP_HOST . ':' . MAIL_SMTP_PUERTO;
$context = stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'SNI_enabled'=>true]]);
$socket = @stream_socket_client($target, $no, $error, 10, STREAM_CLIENT_CONNECT, $context);
if (!$socket) { fwrite(STDERR, "FALLO: no se pudo conectar al SMTP.\n"); exit(1); }
fclose($socket);
echo "VERIFICADO: conexión TCP/TLS inicial establecida. El envío requiere una dirección sintética autorizada y se prueba aparte.\n";
