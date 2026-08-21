<?php
putenv('APP_ENV=test');

$root = dirname(__DIR__);
$php = PHP_BINARY;
require_once $root . '/app/config/config.php';

$suites = [
    'Unit' => [
        'tests/Unit/ValidationTest.php', 'tests/Unit/MailerTest.php',
        'tests/Unit/CsvImportReaderTest.php', 'tests/Unit/AccessDecisionTest.php',
        'tests/Unit/MockAccessControlProviderTest.php', 'tests/Unit/BackupStorageTest.php',
    ],
    'Integration' => [
        'tests/Integration/IntegrityTest.php', 'tests/Integration/IdempotencyTest.php',
        'tests/Integration/ConcurrencyStockTest.php', 'tests/Integration/SociosPaginationTest.php',
        'tests/Integration/MigrationBaselineV21Test.php', 'tests/Integration/MigrationLockTest.php',
        'tests/Integration/MigrationPartialFailureTest.php', 'tests/Integration/MigrationImportTest.php',
        'tests/Integration/MigrationResumeTest.php', 'tests/Integration/MigrationDuplicateTest.php',
        'tests/Integration/EconomicModelTest.php', 'tests/Integration/CashTest.php',
        'tests/Integration/EconomicAtomicityTest.php',
        'tests/Integration/EconomicConcurrencyTest.php',
        'tests/Integration/CashConcurrencyTest.php', 'tests/Integration/AccessControlSyncTest.php',
        'tests/Integration/SecondGymGeneralizationTest.php',
    ],
    'Security' => [
        'tests/Security/OutputEncodingTest.php', 'tests/Security/RateLimitTest.php',
        'tests/Security/MigrationSecurityTest.php', 'tests/Security/MigrationInconsistentSchemaTest.php',
        'tests/Security/EconomicIsolationTest.php', 'tests/Security/AccessControlIsolationTest.php',
        'tests/Security/StagingSafetyTest.php', 'pruebas/multiempresa.php',
        'pruebas/multisede.php', 'pruebas/autorizacion.php',
    ],
    'Functional' => [
        'pruebas/iban.php', 'pruebas/negocio.php', 'pruebas/renovaciones.php',
        'pruebas/suplementos.php', 'pruebas/sepa.php', 'pruebas/facturacion.php',
        'pruebas/personal.php', 'pruebas/prueba_acceso.php',
        'tests/Functional/SociosViewTest.php', 'tests/Functional/DashboardViewTest.php',
        'tests/Functional/VentaSinSedeTest.php', 'tests/Functional/MigrationViewTest.php',
        'tests/Functional/CashViewTest.php',
    ],
];

$args = array_slice($argv, 1);
$p0Gate = in_array('--p0-gate', $args, true);
$injectFailure = in_array('--inject-failure', $args, true);
$knownP1 = [];

if (APP_ENV !== 'test' || DB_NAME_PRUEBAS === '' || DB_NAME_PRUEBAS === DB_NAME) {
    fwrite(STDERR, "TEST HARNESS BLOQUEADO: configuración de base no aislada.\n");
    exit(2);
}
if (!preg_match('/(?:test|prueba)/i', DB_NAME_PRUEBAS)) {
    fwrite(STDERR, "TEST HARNESS BLOQUEADO: DB_NAME_PRUEBAS debe contener test o prueba.\n");
    exit(2);
}

$temporaryFailure = null;
if ($injectFailure) {
    $temporaryFailure = tempnam(sys_get_temp_dir(), 'gimnera_f20_deliberate_fail_');
    if ($temporaryFailure === false) {
        fwrite(STDERR, "No se pudo crear el test de fallo deliberado.\n");
        exit(2);
    }
    file_put_contents(
        $temporaryFailure,
        "<?php fwrite(STDOUT, \"  FALLO fallo deliberado del harness\\nRESUMEN: 0 correctas, 1 fallidas\\n\"); exit(23);\n",
        LOCK_EX
    );
    array_unshift($suites['Unit'], $temporaryFailure);
}

$failed = [];
$executed = 0;
$omitted = [];
$assertionsOk = 0;
$assertionsFailed = 0;
$createdDatabases = [];
$runId = substr(bin2hex(random_bytes(6)), 0, 12);

/** @return array{output:string,exit:int} */
function runProcess(array $command, string $cwd): array
{
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $spec, $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['output' => "No se pudo iniciar el proceso.\n", 'exit' => 127];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return ['output' => (string) $stdout . (string) $stderr, 'exit' => $exit];
}

function suiteDatabaseName(string $base, string $suite, string $runId): string
{
    $prefix = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $base) ?: 'gimnera_test');
    $suite = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $suite) ?: 'suite');
    return substr($prefix . '_f20_' . $suite . '_' . $runId . '_test', 0, 60);
}

function dropSuiteDatabase(string $database): void
{
    if (!preg_match('/^[a-z0-9_]*f20_[a-z0-9_]+_test$/i', $database) || !preg_match('/test/i', $database)) {
        throw new RuntimeException('Se rechazó limpiar una base fuera del patrón temporal F20.');
    }
    $admin = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}

try {
    foreach ($suites as $suite => $files) {
        echo "\n===== {$suite} =====\n";
        $suiteDb = suiteDatabaseName(DB_NAME_PRUEBAS, $suite, $runId);
        $createdDatabases[] = $suiteDb;
        putenv('DB_NAME_PRUEBAS=' . $suiteDb);
        $prepared = runProcess([$php, $root . '/pruebas/preparar_base.php'], $root);
        echo $prepared['output'];
        if ($prepared['exit'] !== 0) {
            $failed[] = 'PREPARE:' . $suite;
            echo "PREPARACIÓN FALLIDA: {$suite}; exit={$prepared['exit']}\n";
            continue;
        }

        foreach ($files as $file) {
            if ($p0Gate && in_array($file, $knownP1, true)) {
                $omitted[] = $file . ' (P1 conocido; solo excluido del gate P0)';
                continue;
            }
            $display = $file;
            $path = $file;
            if ($temporaryFailure !== null && $file === $temporaryFailure) {
                $display = 'TEMPORAL:FALLO_DELIBERADO';
            } else {
                $path = $root . '/' . $file;
            }
            echo "\n--- {$display} ---\n";
            $result = runProcess([$php, $path], $root);
            echo $result['output'];
            $executed++;

            $summariesFound = preg_match_all(
                '/(?:==\s*)?RESUMEN:\s*(\d+)\s+correctas,\s*(\d+)\s+fallidas/i',
                $result['output'],
                $matches,
                PREG_SET_ORDER
            );
            if ($summariesFound) {
                foreach ($matches as $summary) {
                    $assertionsOk += (int) $summary[1];
                    $assertionsFailed += (int) $summary[2];
                }
            } elseif (preg_match_all(
                '/(?:^|\n)[^\n]*:\s*(\d+)\s+comprobaciones,\s*(\d+)\s+fallos\s*(?:\r?\n|$)/i',
                $result['output'],
                $legacyMatches,
                PREG_SET_ORDER
            )) {
                foreach ($legacyMatches as $summary) {
                    $assertionsOk += (int) $summary[1];
                    $assertionsFailed += (int) $summary[2];
                }
            }

            $failureTextWithSuccessExit = $result['exit'] === 0
                && preg_match('/^\s*(?:FALLO|ERROR|FAILED)\b/im', $result['output']) === 1;
            if ($result['exit'] !== 0 || $failureTextWithSuccessExit) {
                $failed[] = $display;
                if ($failureTextWithSuccessExit) {
                    echo "CONTRATO INVÁLIDO: salida de fallo con exit 0.\n";
                }
                echo "EXIT SCRIPT: {$result['exit']}\n";
            }
        }
    }
} finally {
    foreach (array_reverse($createdDatabases) as $database) {
        try {
            dropSuiteDatabase($database);
        } catch (Throwable $e) {
            $failed[] = 'CLEANUP:' . $database;
            fwrite(STDERR, 'No se pudo limpiar una base temporal F20: ' . $e->getMessage() . "\n");
        }
    }
    if ($temporaryFailure !== null && is_file($temporaryFailure)) {
        unlink($temporaryFailure);
    }
}

echo "\nSUITES: " . count($suites)
    . '; scripts ejecutados: ' . $executed
    . '; scripts omitidos: ' . count($omitted)
    . '; assertions correctas: ' . $assertionsOk
    . '; assertions fallidas: ' . $assertionsFailed
    . '; scripts fallidos: ' . count($failed) . "\n";
if ($omitted !== []) {
    echo 'OMITIDOS: ' . implode(', ', $omitted) . "\n";
}
if ($failed !== []) {
    echo 'FALLARON: ' . implode(', ', array_values(array_unique($failed))) . "\n";
}
exit($failed === [] ? 0 : 1);
