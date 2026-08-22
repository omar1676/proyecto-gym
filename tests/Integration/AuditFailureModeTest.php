<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/models/LogModel.php';

$db = Database::getInstance()->getConnection();
$renamed = false;
try {
    $db->exec('RENAME TABLE log_actividad TO log_actividad_f211_unavailable');
    $renamed = true;
    $model = new LogModel(1);
    $bestEffort = $model->registrarCambio(
        1, 'LOGIN', 'fallo sintético de auditoría', null, null, null,
        null, null, 1, 'fallo', 'SYNTHETIC', [], 'usuario', 'WEB', AuditPolicy::BEST_EFFORT
    );
    check('auditoría BEST_EFFORT devuelve fallo visible sin tumbar lectura', $bestEffort === false);

    $requiredThrows = false;
    try {
        $model->registrarCambio(
            1, 'Remesa SEPA', 'fallo sintético de auditoría', null, 'remesa', 1,
            null, null, 1, 'exito', 'SYNTHETIC', [], 'usuario', 'WEB', AuditPolicy::REQUIRED
        );
    } catch (AuditUnavailableException $e) {
        $requiredThrows = $e->getMessage() === 'Required audit unavailable.';
    }
    check('auditoría REQUIRED falla explícitamente para que la transacción pueda revertir', $requiredThrows);
} finally {
    if ($renamed) $db->exec('RENAME TABLE log_actividad_f211_unavailable TO log_actividad');
}

finishTests();
