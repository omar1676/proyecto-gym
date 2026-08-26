<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/helpers/AccessPolicyPresentation.php';

check('estado temporal se presenta en lenguaje humano', AccessPolicyPresentation::state('TEMPORARY') === 'Acceso temporal');
check('bloqueo permanente se distingue del denegado', AccessPolicyPresentation::state('PERMANENT_BLOCK') === 'Bloqueo permanente');
check('motivo técnico se traduce sin mostrar enum', AccessPolicyPresentation::reason('TEMPORARY_VISIT') === 'Visita temporal');
check('sync disabled explica que no hay integración física', AccessPolicyPresentation::syncState('DISABLED') === 'Integración física desactivada');
check('fecha UTC se presenta en horario de Madrid', AccessPolicyPresentation::dateTime('2026-08-26 12:00:00') === '26/08/2026 14:00');

finishTests();
