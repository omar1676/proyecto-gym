<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/DemoGymFactory.php';

$db = Database::getInstance()->getConnection();
$demo = null;
$temps = [];
$processes = [];
try {
    $demo = DemoGymFactory::create($db);
    $company=(int)$demo['empresa']; $site=(int)$demo['sedes'][0]; $actor=(int)$demo['recepcion']; $member=(int)$demo['socios'][0];
    $version=(int)$db->query("SELECT profile_version FROM usuario WHERE id_usuario={$member}")->fetchColumn();
    $barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gimnera_f23_barrier_' . bin2hex(random_bytes(6));
    $temps[] = $barrier;
    foreach (['A','B'] as $suffix) {
        $resultFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gimnera_f23_result_' . bin2hex(random_bytes(6));
        $temps[] = $resultFile;
        $command = [PHP_BINARY, __DIR__ . '/socio_profile_worker.php', $barrier, $resultFile,
            (string)$company,(string)$site,(string)$actor,(string)$member,(string)$version,$suffix];
        $process = proc_open($command, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, dirname(__DIR__, 2), getenv(), ['bypass_shell'=>true]);
        if (!is_resource($process)) throw new RuntimeException('No se pudo lanzar worker F23.');
        fclose($pipes[0]);
        $processes[] = [$process,$pipes,$resultFile];
    }
    file_put_contents($barrier, 'go', LOCK_EX);
    $results=[];
    foreach ($processes as [$process,$pipes,$resultFile]) {
        $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit=proc_close($process);
        if ($exit !== 0) throw new RuntimeException('Worker F23 falló: ' . $stdout . $stderr);
        $results[] = json_decode((string) file_get_contents($resultFile), true);
    }
    $statuses=array_column($results,'status'); sort($statuses);
    check('dos ediciones simultáneas producen un ganador y un conflicto', $statuses === ['conflict','updated']);
    check('concurrencia incrementa la versión exactamente una vez', (int)$db->query("SELECT profile_version FROM usuario WHERE id_usuario={$member}")->fetchColumn() === $version+1);
    check('concurrencia registra un solo éxito de edición', (int)$db->query("SELECT COUNT(*) FROM log_actividad WHERE id_entidad={$member} AND accion='Edición de socio' AND resultado='exito'")->fetchColumn() === 1);
} catch (Throwable $exception) {
    check('concurrencia F23 completa', false);
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
} finally {
    foreach ($processes as [$process]) { if (is_resource($process)) @proc_terminate($process); }
    foreach ($temps as $temp) if (is_file($temp)) @unlink($temp);
    if ($demo !== null) DemoGymFactory::cleanup($db);
}

finishTests();
