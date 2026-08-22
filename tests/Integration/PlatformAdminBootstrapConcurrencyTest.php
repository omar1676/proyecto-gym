<?php

require_once dirname(__DIR__) . '/bootstrap.php';

$db = Database::getInstance()->getConnection();
$original = $db->query(
    "SELECT id_usuario,activo,sesiones_desde FROM usuario WHERE rol='superadmin' AND id_empresa IS NULL AND activo=1 ORDER BY id_usuario LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$createdIds = [];

try {
    if (!$original) throw new RuntimeException('Falta superadmin reversible de fixture.');
    $db->prepare('UPDATE usuario SET activo=0,sesiones_desde=NOW() WHERE id_usuario=:id')
        ->execute([':id'=>(int)$original['id_usuario']]);

    $suffixes = [bin2hex(random_bytes(4)), bin2hex(random_bytes(4))];
    $workers = [];
    foreach ($suffixes as $suffix) {
        $spec = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Support/platform_bootstrap_worker.php', $suffix],
            $spec, $pipes, dirname(__DIR__, 2), null, ['bypass_shell'=>true]
        );
        if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar worker bootstrap.');
        fclose($pipes[0]);
        $workers[] = [$process,$pipes,$suffix];
    }
    $results = [];
    foreach ($workers as [$process,$pipes,$suffix]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        $decoded = json_decode(trim($stdout), true);
        $results[] = ['exit'=>$exit,'data'=>$decoded,'stderr'=>$stderr];
        if (is_array($decoded) && !empty($decoded['user_id'])) $createdIds[] = (int)$decoded['user_id'];
    }
    $winners = array_values(array_filter($results, static fn(array $r): bool => $r['exit'] === 0 && ($r['data']['created'] ?? false)));
    $losers = array_values(array_filter($results, static fn(array $r): bool => $r['exit'] === 11 && ($r['data']['rejected'] ?? false)));
    check('dos bootstrap concurrentes producen un único ganador', count($winners) === 1 && count($losers) === 1);
    check('DB contiene exactamente un superadmin global activo', (int)$db->query(
        "SELECT COUNT(*) FROM usuario WHERE rol='superadmin' AND id_empresa IS NULL AND activo=1"
    )->fetchColumn() === 1);
    check('identidad histórica permanece desactivada', (int)$db->query(
        'SELECT activo FROM usuario WHERE id_usuario=' . (int)$original['id_usuario']
    )->fetchColumn() === 0);
    check('workers no filtran errores internos', count(array_filter(
        $results,
        static fn(array $r): bool => preg_match('/Fatal error|Warning|Notice|Uncaught/i', $r['stderr']) === 1
            || (($r['data']['internal'] ?? null) !== null)
    )) === 0);
} catch (Throwable $error) {
    check('escenario concurrente de bootstrap', false);
    fwrite(STDERR, get_class($error) . "\n");
} finally {
    foreach ($createdIds as $id) {
        $db->prepare('DELETE FROM log_actividad WHERE id_usuario=:actor OR id_usuario_afectado=:affected')
            ->execute([':actor'=>$id, ':affected'=>$id]);
        $db->prepare('DELETE FROM usuario WHERE id_usuario=:id')->execute([':id'=>$id]);
    }
    if ($original) {
        $db->prepare('UPDATE usuario SET activo=:active,sesiones_desde=:sessions WHERE id_usuario=:id')
            ->execute([':active'=>$original['activo'],':sessions'=>$original['sesiones_desde'],':id'=>(int)$original['id_usuario']]);
    }
}

finishTests();
