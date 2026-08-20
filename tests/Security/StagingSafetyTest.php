<?php

require_once dirname(__DIR__) . '/bootstrap.php';

$root = dirname(__DIR__, 2);
$db = Database::getInstance()->getConnection();

/** @return array{exit:int,stdout:string,stderr:string} */
function stagingProcess(array $command, array $environment): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 2),
        array_merge(getenv(), $environment)
    );
    if (!is_resource($process)) {
        return ['exit' => 127, 'stdout' => '', 'stderr' => 'No se pudo iniciar el proceso.'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

$stageEnvironment = [
    'APP_ENV' => 'staging',
    'APP_URL' => 'https://staging.example.invalid',
    'DB_HOST' => DB_HOST,
    'DB_PORT' => DB_PORT,
    'DB_NAME' => DB_NAME_PRUEBAS,
    'DB_NAME_PRUEBAS' => DB_NAME . '_otra_prueba',
    'DB_USER' => DB_USER,
    'DB_PASS' => DB_PASS,
    'ACCESS_CONTROL_MODE' => 'active',
    'ACCESS_CONTROL_ACTIVE_CONFIRM' => 'true',
    'STAGING_MAIL_ALLOWLIST' => 'buzon-autorizado@example.invalid',
];

$probe = stagingProcess([PHP_BINARY, $root . '/tests/Support/staging_safety_probe.php'], $stageEnvironment);
$probeData = json_decode(trim($probe['stdout']), true);
check('staging se reconoce como entorno independiente', $probe['exit'] === 0 && ($probeData['environment'] ?? '') === 'staging');
check('staging fuerza el control de acceso a disabled', ($probeData['access_control_mode'] ?? '') === 'disabled');
check('staging bloquea correo fuera de allowlist', ($probeData['mail_blocked'] ?? false) === true);

$membershipsBefore = (int) $db->query('SELECT COUNT(*) FROM socio_membresia')->fetchColumn();
$remittancesBefore = (int) $db->query('SELECT COUNT(*) FROM remesa')->fetchColumn();
$cron = stagingProcess([PHP_BINARY, $root . '/cron/tareas.php', 'renovar', 'remesa'], $stageEnvironment);
$membershipsAfter = (int) $db->query('SELECT COUNT(*) FROM socio_membresia')->fetchColumn();
$remittancesAfter = (int) $db->query('SELECT COUNT(*) FROM remesa')->fetchColumn();
check('cron económico queda forzado a simulación en staging', $cron['exit'] === 0 && str_contains($cron['stdout'], 'simulación económica forzada'));
check('cron staging no crea membresías ni remesas', $membershipsBefore === $membershipsAfter && $remittancesBefore === $remittancesAfter);

$migration = stagingProcess([PHP_BINARY, $root . '/ops/migrate.php'], $stageEnvironment);
check('migrar staging exige confirmación explícita', $migration['exit'] !== 0 && str_contains($migration['stderr'], '--confirm-staging'));

finishTests();
