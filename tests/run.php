<?php
putenv('APP_ENV=test');

$root = dirname(__DIR__);
$php = PHP_BINARY;
require_once $root . '/app/config/config.php';
require_once $root . '/tests/Support/TestDatabaseName.php';

$suites = [
    'Unit' => [
        'tests/Unit/ValidationTest.php', 'tests/Unit/MailerTest.php',
        'tests/Unit/CsvImportReaderTest.php', 'tests/Unit/AccessDecisionTest.php',
        'tests/Unit/MockAccessControlProviderTest.php', 'tests/Unit/BackupStorageTest.php',
        'tests/Unit/F21OperationalSafetyTest.php', 'tests/Unit/TestDatabaseNameTest.php',
        'tests/Unit/AuditPolicyTest.php',
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
        'tests/Integration/PasswordResetAtomicityTest.php',
        'tests/Integration/PasswordResetDeliveryTest.php',
        'tests/Integration/CashConcurrencyTest.php', 'tests/Integration/AccessControlSyncTest.php',
        'tests/Integration/SecondGymGeneralizationTest.php',
        'tests/Integration/AuditPrivacyOffboardingTest.php',
        'tests/Integration/AuditFailureModeTest.php',
        'tests/Integration/SchemaCompatibilityTest.php',
        'tests/Integration/MigrationV28StructureTest.php',
        'tests/Integration/MigrationV29StructureTest.php',
        'tests/Integration/RestoreCurrencyTest.php',
        'tests/Integration/TenantProvisioningTest.php',
        'tests/Integration/PlatformAdminBootstrapTest.php',
        'tests/Integration/TenantConfigurationTest.php',
        'tests/Integration/TenantOnboardingConcurrencyTest.php',
        'tests/Integration/AtlasOnboardingTest.php',
        'tests/Integration/TenantProvisioningScaleTest.php',
    ],
    'Security' => [
        'tests/Security/OutputEncodingTest.php', 'tests/Security/RateLimitTest.php',
        'tests/Security/MigrationSecurityTest.php', 'tests/Security/MigrationInconsistentSchemaTest.php',
        'tests/Security/EconomicIsolationTest.php', 'tests/Security/AccessControlIsolationTest.php',
        'tests/Security/StagingSafetyTest.php', 'pruebas/multiempresa.php',
        'tests/Security/PrivacyRoutesTest.php', 'tests/Security/InfrastructureFailureTest.php',
        'tests/Security/TenantOnboardingIsolationTest.php',
        'pruebas/multisede.php', 'pruebas/autorizacion.php',
    ],
    'Functional' => [
        'pruebas/iban.php', 'pruebas/negocio.php', 'pruebas/renovaciones.php',
        'pruebas/suplementos.php', 'pruebas/sepa.php', 'pruebas/facturacion.php',
        'pruebas/personal.php', 'pruebas/prueba_acceso.php',
        'tests/Functional/SociosViewTest.php', 'tests/Functional/DashboardViewTest.php',
        'tests/Functional/VentaSinSedeTest.php', 'tests/Functional/MigrationViewTest.php',
        'tests/Functional/CashViewTest.php', 'tests/Functional/ExportFormsTest.php',
        'tests/Functional/TenantBrandingTest.php',
        'pruebas/acceso.php',
    ],
];

$args = array_slice($argv, 1);
$p0Gate = in_array('--p0-gate', $args, true);
$injectFailure = in_array('--inject-failure', $args, true);
$injectAccessFailure = in_array('--inject-access-failure', $args, true);
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
function runProcess(array $command, string $cwd, ?array $environment = null): array
{
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $spec, $pipes, $cwd, $environment, ['bypass_shell' => true]);
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

function dropSuiteDatabase(string $database): void
{
    if (!TestDatabaseName::isManaged($database)) {
        throw new RuntimeException('Se rechazó limpiar una base fuera del patrón temporal F21.');
    }
    $admin = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}

/** @return array{output:string,exit:int} */
function runHttpAccessTest(string $php, string $path, string $root, string $database, bool $injectFailure = false): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if ($socket === false) {
        return ['output' => "No se pudo reservar puerto HTTP temporal.\n", 'exit' => 127];
    }
    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    $sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gimnera_f211_sessions_' . bin2hex(random_bytes(8));
    if (!mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
        return ['output' => "No se pudo crear almacén de sesión temporal.\n", 'exit' => 127];
    }
    $stdout = tempnam(sys_get_temp_dir(), 'gimnera_f211_http_out_');
    $stderr = tempnam(sys_get_temp_dir(), 'gimnera_f211_http_err_');
    if ($stdout === false || $stderr === false) {
        return ['output' => "No se pudo crear log HTTP temporal.\n", 'exit' => 127];
    }

    $environment = getenv();
    $environment['APP_ENV'] = 'test';
    $environment['APP_URL'] = 'http://' . $address;
    $environment['TEST_BASE_URL'] = 'http://' . $address . '/index.php';
    $environment['DB_NAME_PRUEBAS'] = $database;
    $environment['SESSION_DIR'] = $sessionDir;
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['file', $stdout, 'ab'],
        2 => ['file', $stderr, 'ab'],
    ];
    $server = proc_open(
        [$php, '-S', $address, '-t', $root . '/public'],
        $spec,
        $pipes,
        $root,
        $environment,
        ['bypass_shell' => true]
    );
    if (!is_resource($server)) {
        return ['output' => "No se pudo iniciar servidor HTTP temporal.\n", 'exit' => 127];
    }
    fclose($pipes[0]);
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @fsockopen('127.0.0.1', (int) substr(strrchr($address, ':'), 1), $errno, $errstr, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                $ready = true;
                break;
            }
            $status = proc_get_status($server);
            if (!$status['running']) break;
            usleep(100000);
        }
        if (!$ready) {
            return ['output' => "Servidor HTTP temporal no quedó listo.\n", 'exit' => 127];
        }
        $command = [$php, $path];
        if ($injectFailure) $command[] = '--force-failure';
        return runProcess($command, $root, $environment);
    } finally {
        proc_terminate($server);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $status = proc_get_status($server);
            if (!$status['running']) break;
            usleep(50000);
        }
        $status = proc_get_status($server);
        if ($status['running']) proc_terminate($server, 9);
        proc_close($server);
        foreach (glob($sessionDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) @unlink($file);
        }
        @rmdir($sessionDir);
        @unlink($stdout);
        @unlink($stderr);
    }
}

/** Inventario solapable: legacy/E2E/destructive describen naturaleza, no omisión. */
function testInventory(string $root, array $suites): array
{
    $integrated = [];
    foreach ($suites as $files) {
        foreach ($files as $file) {
            if (str_ends_with(strtolower((string) $file), '.php')) {
                $integrated[] = str_replace('\\', '/', (string) $file);
            }
        }
    }
    $integrated = array_values(array_unique($integrated));
    $result = [
        'integrated' => $integrated,
        'support' => [],
        'manual' => [],
        'legacy' => array_values(array_filter($integrated, static fn(string $file): bool => str_starts_with($file, 'pruebas/'))),
        'e2e' => array_values(array_filter($integrated, static fn(string $file): bool => $file === 'pruebas/acceso.php')),
        'destructive' => [],
        'unknown' => [],
    ];
    foreach (['tests', 'pruebas'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (in_array($relative, $integrated, true)) continue;
            $support = $relative === 'tests/run.php'
                || $relative === 'tests/bootstrap.php'
                || str_starts_with($relative, 'tests/Support/')
                || str_ends_with($relative, '_worker.php')
                || $relative === 'pruebas/_arranque.php';
            $manual = $relative === 'pruebas/preparar_base.php'
                || $relative === 'pruebas/render.php'
                || $relative === 'pruebas/generar_fixtures_importacion.php'
                || str_starts_with($relative, 'pruebas/carga_')
                || str_starts_with($relative, 'pruebas/rendimiento_');
            if ($support) $result['support'][] = $relative;
            elseif ($manual) {
                $result['manual'][] = $relative;
                if ($relative === 'pruebas/preparar_base.php' || str_starts_with($relative, 'pruebas/carga_')) {
                    $result['destructive'][] = $relative . ' (solo DB test)';
                }
            }
            else $result['unknown'][] = $relative;
        }
    }
    foreach ($result as &$files) sort($files);
    unset($files);
    return $result;
}

$inventory = testInventory($root, $suites);
if ($inventory['unknown'] !== []) {
    fwrite(STDERR, 'TEST INVENTORY BLOQUEADO: scripts PHP sin clasificar: ' . implode(', ', $inventory['unknown']) . "\n");
    exit(3);
}
echo 'INVENTARIO: integrados=' . count($inventory['integrated'])
    . '; soporte=' . count($inventory['support'])
    . '; manuales_no_integrados=' . count($inventory['manual'])
    . '; legacy_integrados=' . count($inventory['legacy'])
    . '; e2e_integrados=' . count($inventory['e2e'])
    . '; destructivos_aislados=' . count($inventory['destructive'])
    . "; sin_clasificar=0\n";

try {
    foreach ($suites as $suite => $files) {
        echo "\n===== {$suite} =====\n";
        $suiteDb = TestDatabaseName::generate(DB_NAME_PRUEBAS, $suite, $runId);
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
            $result = $file === 'pruebas/acceso.php'
                ? runHttpAccessTest($php, $path, $root, $suiteDb, $injectAccessFailure)
                : runProcess([$php, $path], $root);
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
            fwrite(STDERR, 'No se pudo limpiar una base temporal F21: ' . $e->getMessage() . "\n");
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
