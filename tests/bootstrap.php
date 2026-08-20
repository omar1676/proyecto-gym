<?php
require_once dirname(__DIR__) . '/pruebas/_arranque.php';

$GLOBALS['test_ok'] = 0;
$GLOBALS['test_fail'] = 0;
function check(string $description, bool $condition): void
{
    if ($condition) {
        $GLOBALS['test_ok']++;
        echo "  OK   {$description}\n";
    } else {
        $GLOBALS['test_fail']++;
        echo "  FALLO {$description}\n";
    }
}
function finishTests(): void
{
    echo "RESUMEN: {$GLOBALS['test_ok']} correctas, {$GLOBALS['test_fail']} fallidas\n";
    exit($GLOBALS['test_fail'] === 0 ? 0 : 1);
}
