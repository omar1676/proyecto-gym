<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/services/PlatformAdminBootstrapService.php';

$db = Database::getInstance()->getConnection();
$original = $db->query(
    "SELECT id_usuario,id_empresa,rol FROM usuario WHERE rol='superadmin' AND id_empresa IS NULL ORDER BY id_usuario LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$created = 0;

try {
    if (!$original) throw new RuntimeException('El fixture no contiene superadmin reversible.');
    $db->prepare("UPDATE usuario SET rol='direccion',id_empresa=1 WHERE id_usuario=:id")
        ->execute([':id' => (int) $original['id_usuario']]);
    $service = new PlatformAdminBootstrapService($db);
    $weakRejected = false;
    try {
        $service->bootstrap([
            'name'=>'Test','surname'=>'Operator','email'=>'platform.weak@test.invalid',
            'username'=>'platform.weak','password'=>'weak',
        ]);
    } catch (InvalidArgumentException) { $weakRejected = true; }
    check('bootstrap rechaza contraseña débil', $weakRejected);

    $plain = 'F22-Bootstrap-Temporary-98!';
    $result = $service->bootstrap([
        'name'=>'Test','surname'=>'Operator','email'=>'platform.f22@test.invalid',
        'username'=>'platform.f22','password'=>$plain,
    ]);
    $created = (int) $result['user_id'];
    check('bootstrap crea una sola identidad global nominal', $created > 0);
    $row = $db->query('SELECT * FROM usuario WHERE id_usuario=' . $created)->fetch(PDO::FETCH_ASSOC);
    check('identidad queda fuera de cualquier tenant y con hash verificable', is_array($row)
        && $row['id_empresa'] === null && $row['rol'] === 'superadmin'
        && !hash_equals($plain, (string) $row['contrasena']) && password_verify($plain, (string) $row['contrasena']));
    $audit = $db->query("SELECT * FROM log_actividad WHERE id_usuario={$created} AND accion='PLATFORM_ADMIN_BOOTSTRAPPED'")
        ->fetch(PDO::FETCH_ASSOC);
    check('bootstrap queda auditado sin contraseña', is_array($audit)
        && !str_contains(json_encode($audit), $plain));

    $secondRejected = false;
    try {
        $service->bootstrap([
            'name'=>'Other','surname'=>'Operator','email'=>'platform.other@test.invalid',
            'username'=>'platform.other','password'=>'F22-Other-Temporary-99!',
        ]);
    } catch (DomainException) { $secondRejected = true; }
    check('bootstrap de un solo uso rechaza una segunda identidad', $secondRejected);
} finally {
    try {
        if ($created > 0) {
            $db->prepare('DELETE FROM log_actividad WHERE id_usuario=:actor OR id_usuario_afectado=:affected')
                ->execute([':actor'=>$created, ':affected'=>$created]);
            $db->prepare('DELETE FROM usuario WHERE id_usuario=:id')->execute([':id'=>$created]);
        }
    } finally {
        if ($original) {
            $db->prepare("UPDATE usuario SET rol='superadmin',id_empresa=NULL WHERE id_usuario=:id")
                ->execute([':id'=>(int)$original['id_usuario']]);
        }
    }
}

finishTests();
