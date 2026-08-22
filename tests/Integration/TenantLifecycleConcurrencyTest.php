<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/TenantOnboardingFactory.php';
require_once dirname(__DIR__, 2) . '/app/models/UserModel.php';

$db = Database::getInstance()->getConnection();
$company = 0;
$ready = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gimnera-f221-lifecycle-' . bin2hex(random_bytes(8));
$process = null;

try {
    $created = TenantOnboardingFactory::create($db, 'Lifecycle Race ' . bin2hex(random_bytes(3)));
    $company = (int) $created['company_id'];
    $site = (int) $created['site_id'];
    $actor = (int) $db->query("SELECT id_usuario FROM usuario WHERE rol='superadmin' AND id_empresa IS NULL AND activo=1 LIMIT 1")->fetchColumn();
    $suffix = bin2hex(random_bytes(5));
    $spec = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/Support/tenant_lifecycle_worker.php', (string)$company, (string)$site, $ready, $suffix],
        $spec, $pipes, dirname(__DIR__, 2), null, ['bypass_shell'=>true]
    );
    if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar el worker concurrente.');
    fclose($pipes[0]);
    for ($i=0; $i<100 && !is_file($ready); $i++) usleep(20000);
    check('worker adquirió el lock antes de cancelar', is_file($ready));

    $started = microtime(true);
    $cancel = (new TenantProvisioningService($db, $actor))->cancel($company);
    $elapsed = microtime(true) - $started;
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process); $process = null;

    check('escritura iniciada antes de cancelar termina de forma consistente', $exit === 0 && str_contains($stdout, '"created":true'));
    check('cancelación espera al writer en lugar de adelantarlo', $elapsed >= 0.45);
    check('cancelación queda confirmada después del writer', $cancel['cancelled'] === true
        && $db->query("SELECT CONCAT(estado,':',onboarding_state) FROM empresa WHERE id_empresa={$company}")->fetchColumn() === 'inactiva:CANCELLED');
    check('se conserva exactamente el alta que empezó antes de cancelar', (int) $db->query(
        "SELECT COUNT(*) FROM usuario WHERE id_empresa={$company} AND nombre_usuario=" . $db->quote('race.' . strtolower($suffix))
    )->fetchColumn() === 1);

    $postRejected = false;
    try {
        (new UserModel($site, $company))->crear(
            'Carrera', 'Posterior', 'F221POST01', null, 'post.race@test.invalid',
            'post.race.f221', 'Synthetic-only-F221!'
        );
    } catch (DomainException) { $postRejected = true; }
    check('toda escritura posterior a la cancelación se rechaza', $postRejected);
    check('worker no produjo errores internos', !preg_match('/Fatal error|Warning|Notice|Uncaught/i', $stderr));
} catch (Throwable $error) {
    check('escenario concurrente de lifecycle', false);
    fwrite(STDERR, get_class($error) . "\n");
} finally {
    if (is_resource($process)) proc_terminate($process);
    @unlink($ready);
    if ($company > 0) {
        $db->exec("DELETE FROM log_actividad WHERE id_empresa={$company}");
        $db->exec("DELETE FROM usuario WHERE id_empresa={$company}");
        $db->exec("DELETE FROM tipo_membresia WHERE id_empresa={$company}");
        $db->exec("DELETE FROM categoria_producto WHERE id_empresa={$company}");
        $db->exec("DELETE FROM gimnasio WHERE id_empresa={$company}");
        $db->exec("DELETE FROM empresa WHERE id_empresa={$company}");
    }
}

finishTests();
