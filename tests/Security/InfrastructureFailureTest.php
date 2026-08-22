<?php

require_once dirname(__DIR__, 2) . '/app/config/config.php';

$ok = 0;
$fail = 0;
$check = static function (string $name, bool $condition) use (&$ok, &$fail): void {
    if ($condition) {
        $ok++;
        return;
    }
    $fail++;
    echo "FALLO: {$name}\n";
};

$root = dirname(__DIR__, 2);
$validEnvironment = getenv();
$validEnvironment['APP_ENV'] = 'test';
$environment = $validEnvironment;
$environment['APP_ENV'] = 'test';
$environment['DB_HOST'] = '127.0.0.1';
$environment['DB_PORT'] = '1';
$environment['DB_NAME'] = 'gimnera_f211_unreachable_test';
$environment['DB_NAME_PRUEBAS'] = 'gimnera_f211_unreachable_test';

/** @return array{output:string,exit:int} */
$run = static function (array $command, ?array $customEnvironment = null) use ($root, $environment): array {
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $spec, $pipes, $root, $customEnvironment ?? $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['output' => 'process_start_failed', 'exit' => 127];
    }
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['output' => $output, 'exit' => proc_close($process)];
};

foreach ([
    'schema_gate' => [PHP_BINARY, $root . '/ops/schema_gate.php', '--mode=runtime'],
    'migrate' => [PHP_BINARY, $root . '/ops/migrate.php', '--status'],
    'status' => [PHP_BINARY, $root . '/ops/status.php'],
    'runtime_check' => [PHP_BINARY, $root . '/ops/runtime_check.php'],
] as $name => $command) {
    $result = $run($command);
    $check("{$name} devuelve exit distinto de cero sin DB", $result['exit'] !== 0);
    $check("{$name} no expone la contraseña DB", DB_PASS === '' || !str_contains($result['output'], DB_PASS));
}

$valid = $run([PHP_BINARY, $root . '/ops/runtime_check.php'], $validEnvironment);
$check('runtime correcto devuelve exit cero', $valid['exit'] === 0);

$invalidCredential = $validEnvironment;
$invalidCredential['DB_PASS'] = 'F21.1-synthetic-invalid-credential';
$credentialFailure = $run([PHP_BINARY, $root . '/ops/runtime_check.php'], $invalidCredential);
$check('credencial sintética inválida devuelve exit distinto de cero', $credentialFailure['exit'] !== 0);
$check('credencial sintética inválida no aparece en salida', !str_contains($credentialFailure['output'], $invalidCredential['DB_PASS']));

$missingDatabase = $validEnvironment;
$missingDatabase['DB_NAME_PRUEBAS'] = 'gimnera_f211_missing_' . bin2hex(random_bytes(6)) . '_test';
$missingFailure = $run([PHP_BINARY, $root . '/ops/runtime_check.php'], $missingDatabase);
$check('base sintética inexistente devuelve exit distinto de cero', $missingFailure['exit'] !== 0);

$healthCode = <<<'PHP'
$_SERVER['REQUEST_URI'] = '/health';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
include $argv[1];
PHP;
$health = $run([PHP_BINARY, '-r', $healthCode, $root . '/public/index.php']);
$healthOutput = trim($health['output']);
$jsonMatch = [];
$hasJson = preg_match('/\{"status":"error","component":"database"\}/', $healthOutput, $jsonMatch) === 1;
$payload = $hasJson ? json_decode($jsonMatch[0], true) : null;
$check('health termina con exit distinto de cero sin DB', $health['exit'] !== 0);
$check('health devuelve JSON estable sin DB', $payload === ['status' => 'error', 'component' => 'database']);
$check('health no expone la contraseña DB', DB_PASS === '' || !str_contains($healthOutput, DB_PASS));

echo "RESUMEN: {$ok} correctas, {$fail} fallidas\n";
exit($fail === 0 ? 0 : 1);
