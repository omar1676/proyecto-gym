<?php

require_once dirname(__DIR__, 2) . '/app/config/config.php';
require_once dirname(__DIR__, 2) . '/app/helpers/Mailer.php';

$blocked = Mailer::enviar(
    'socio-no-autorizado@example.com',
    'No debe salir de staging',
    '<p>Mensaje sintético.</p>'
) === false;

echo json_encode([
    'environment' => APP_ENV,
    'access_control_mode' => ACCESS_CONTROL_MODE,
    'mail_blocked' => $blocked,
    'allowlist' => STAGING_MAIL_ALLOWLIST,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit(APP_ENV === 'staging' && ACCESS_CONTROL_MODE === 'disabled' && $blocked ? 0 : 1);
