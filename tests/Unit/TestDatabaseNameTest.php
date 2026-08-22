<?php

require_once dirname(__DIR__) . '/Support/TestDatabaseName.php';

$ok = 0; $fail = 0;
$check = static function (string $name, bool $condition) use (&$ok, &$fail): void {
    if ($condition) $ok++; else { $fail++; echo "FALLO: {$name}\n"; }
};

$short = TestDatabaseName::generate('gimnera_test', 'Unit', '001122334455');
$maximal = TestDatabaseName::generate(str_repeat('base-muy-larga-', 20), str_repeat('suite-larga-', 20), 'aabbccddeeff001122');
$otherBase = TestDatabaseName::generate(str_repeat('base-muy-larga-', 19) . 'otra', str_repeat('suite-larga-', 20), 'aabbccddeeff001122');
$parallel = TestDatabaseName::generate('gimnera_test', 'Unit', 'ffeeddccbbaa');

$check('nombre corto es gestionado y conserva marcador test', TestDatabaseName::isManaged($short) && str_contains($short, '_test_'));
$check('entrada excesivamente larga produce nombre de máximo 64 caracteres', TestDatabaseName::isManaged($maximal) && strlen($maximal) <= 64);
$check('bases largas distintas no colisionan', $maximal !== $otherBase);
$check('runs paralelos no colisionan', $short !== $parallel);
$check('nombre sin prefijo exclusivo no puede limpiarse', !TestDatabaseName::isManaged('gimnasio_staging'));
$check('truncado antiguo sin sufijo no puede limpiarse', !TestDatabaseName::isManaged('gimnera_f21_integration_001122334455'));

echo "RESUMEN: {$ok} correctas, {$fail} fallidas\n";
exit($fail === 0 ? 0 : 1);
