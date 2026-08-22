<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/models/UserModel.php';

$db = Database::getInstance()->getConnection();
$model = new UserModel();
$userId = 1;
$token = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', time() + 900);
$original = (string) $db->query('SELECT contrasena FROM usuario WHERE id_usuario = 1')->fetchColumn();
check('se prepara token sintético para carrera', $model->guardarTokenReset($userId, $token, $expires));

$barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gimnera_f211_reset_' . bin2hex(random_bytes(8));
$worker = dirname(__DIR__) . '/Support/password_reset_worker.php';
$processes = [];
for ($variant = 0; $variant < 2; $variant++) {
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        [PHP_BINARY, $worker, $barrier, (string) $variant],
        $spec,
        $pipes,
        dirname(__DIR__, 2),
        null,
        ['bypass_shell' => true]
    );
    if (is_resource($process)) {
        fwrite($pipes[0], json_encode(['token' => $token]));
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
}
touch($barrier);
$results = [];
foreach ($processes as [$process, $pipes]) {
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $results[] = ['exit' => proc_close($process), 'data' => json_decode($stdout, true), 'stderr' => $stderr];
}
@unlink($barrier);

$successes = array_values(array_filter($results, static fn(array $result): bool => !empty($result['data']['success'])));
check('dos reset concurrentes terminan controladamente', count($results) === 2 && count(array_filter($results, static fn(array $r): bool => $r['exit'] === 0)) === 2);
check('exactamente un reset concurrente consume el token', count($successes) === 1);

$row = $db->query('SELECT contrasena,reset_token,reset_expira FROM usuario WHERE id_usuario = 1')->fetch(PDO::FETCH_ASSOC);
$passwords = ['F21.1-Atomic-Reset-A!72', 'F21.1-Atomic-Reset-B!93'];
$winner = count($successes) === 1 ? (int) $successes[0]['data']['variant'] : -1;
$matches = array_map(static fn(string $password): bool => password_verify($password, (string) $row['contrasena']), $passwords);
check('la contraseña final corresponde únicamente al proceso ganador', $winner >= 0 && $matches[$winner] && count(array_filter($matches)) === 1);
check('token y expiración desaparecen en el mismo commit', $row['reset_token'] === null && $row['reset_expira'] === null);
$beforeReuse = (string) $row['contrasena'];
check('reutilizar token consumido es rechazado', $model->consumirTokenReset($token, 'F21.1-Reuse-Must-Fail!') === null);
$afterReuse = (string) $db->query('SELECT contrasena FROM usuario WHERE id_usuario = 1')->fetchColumn();
check('reutilización rechazada no cambia la contraseña', hash_equals($beforeReuse, $afterReuse));

$expired = bin2hex(random_bytes(32));
check('se prepara token expirado sintético', $model->guardarTokenReset($userId, $expired, date('Y-m-d H:i:s', time() - 60)));
check('token expirado no puede consumirse', $model->consumirTokenReset($expired, 'F21.1-Expired-Must-Fail!') === null);

$db->prepare('UPDATE usuario SET contrasena=:password,reset_token=NULL,reset_expira=NULL WHERE id_usuario=:id')
    ->execute([':password' => $original, ':id' => $userId]);
finishTests();
