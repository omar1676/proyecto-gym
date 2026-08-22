<?php

require_once dirname(__DIR__, 2) . '/app/helpers/AuditPolicy.php';

$ok = 0; $fail = 0;
$check = static function (string $name, bool $condition) use (&$ok, &$fail): void {
    if ($condition) $ok++; else { $fail++; echo "FALLO: {$name}\n"; }
};

foreach (['Venta', 'Apertura de caja', 'Actualizar stock', 'Remesa SEPA', 'Cambio de rol', 'PASSWORD_RESET_COMPLETED'] as $action) {
    $check("{$action} queda clasificada REQUIRED", AuditPolicy::modeFor($action) === AuditPolicy::REQUIRED);
}
foreach (['LOGIN', 'LOGOUT', 'Exportar ventas CSV', 'Búsqueda de socios'] as $action) {
    $check("{$action} queda clasificada BEST_EFFORT", AuditPolicy::modeFor($action) === AuditPolicy::BEST_EFFORT);
}
$check('modo desconocido no escala a REQUIRED', AuditPolicy::normalize('lo_que_sea') === AuditPolicy::BEST_EFFORT);

echo "RESUMEN: {$ok} correctas, {$fail} fallidas\n";
exit($fail === 0 ? 0 : 1);
