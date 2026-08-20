<?php
$root = dirname(__DIR__);
$php = PHP_BINARY;
$suites = [
    'Unit' => ['tests/Unit/ValidationTest.php', 'tests/Unit/MailerTest.php', 'tests/Unit/CsvImportReaderTest.php'],
    'Integration' => ['tests/Integration/IntegrityTest.php', 'tests/Integration/IdempotencyTest.php', 'tests/Integration/ConcurrencyStockTest.php', 'tests/Integration/SociosPaginationTest.php', 'tests/Integration/MigrationImportTest.php', 'tests/Integration/MigrationResumeTest.php', 'tests/Integration/MigrationDuplicateTest.php', 'tests/Integration/EconomicModelTest.php', 'tests/Integration/CashTest.php', 'tests/Integration/CashConcurrencyTest.php'],
    'Security' => ['tests/Security/OutputEncodingTest.php', 'tests/Security/RateLimitTest.php', 'tests/Security/MigrationSecurityTest.php', 'tests/Security/EconomicIsolationTest.php', 'pruebas/multiempresa.php', 'pruebas/multisede.php', 'pruebas/autorizacion.php'],
    'Functional' => ['pruebas/iban.php', 'pruebas/negocio.php', 'pruebas/renovaciones.php', 'pruebas/suplementos.php', 'pruebas/sepa.php', 'pruebas/facturacion.php', 'pruebas/personal.php', 'tests/Functional/SociosViewTest.php', 'tests/Functional/DashboardViewTest.php', 'tests/Functional/VentaSinSedeTest.php', 'tests/Functional/MigrationViewTest.php', 'tests/Functional/CashViewTest.php'],
];
$failed = [];
foreach ($suites as $suite => $files) {
    echo "\n===== {$suite} =====\n";
    foreach ($files as $file) {
        echo "\n--- {$file} ---\n";
        passthru(escapeshellarg($php) . ' ' . escapeshellarg($root . '/' . $file), $exit);
        if ($exit !== 0) $failed[] = $file;
    }
}
echo "\nSUITES: " . count($suites) . '; scripts: ' . array_sum(array_map('count', $suites)) . '; fallidos: ' . count($failed) . "\n";
if ($failed) echo 'FALLARON: ' . implode(', ', $failed) . "\n";
exit($failed ? 1 : 0);
