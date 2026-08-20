<?php
/**
 * instalar.php — script de instalación inicial.
 *
 * Ejecuta una vez desde la línea de comandos (`php instalar.php`) para:
 *   1. Aplicar la migración base (app/config/migracion.sql).
 *   2. Crear/actualizar tres usuarios iniciales: admin, recepción y socio
 *      con la contraseña recibida exclusivamente por variable de proceso.
 *
 * BÓRRALO del servidor cuando termines. El acceso web está bloqueado y solo se
 * permite ejecutarlo por CLI.
 *
 * Las migraciones posteriores (v2 a v6) hay que aplicarlas a mano desde
 * phpMyAdmin — ver app/config/migracion_v2.sql … migracion_v6.sql.
 *
 * IMPORTANTE: los roles de abajo (admin/recepcion/socio) son los que define la
 * migración v6. Aplica esa migración ANTES de ejecutar este script, o el ENUM
 * de `usuario`.`rol` rechazará los valores.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$initialPassword = (string) getenv('INSTALL_ADMIN_PASSWORD');
if (strlen($initialPassword) < 12) {
    fwrite(
        STDERR,
        "Define INSTALL_ADMIN_PASSWORD con al menos 12 caracteres antes de ejecutar el instalador.\n"
    );
    exit(1);
}

require_once __DIR__ . '/app/config/database.php';

$db  = Database::getInstance()->getConnection();

/*
 * Cerrojo de seguridad.
 *
 * Este script define las contraseñas de admin, recepción y socio con un secreto
 * suministrado por el operador. El valor no vive en Git ni se imprime.
 *
 * A partir de la primera instalación se bloquea solo: si ya hay usuarios de
 * gestión creados, deja de funcionar. Para reinstalar a propósito, vacía la
 * tabla `usuario` o borra este archivo y créalo de nuevo.
 */
try {
    $yaInstalado = (int) $db->query(
        "SELECT COUNT(*) FROM usuario WHERE rol IN ('propietario','admin','recepcion')"
    )->fetchColumn() > 0;
} catch (PDOException $e) {
    $yaInstalado = false;   // la tabla aún no existe: es una instalación nueva
}

if ($yaInstalado) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
       . '<title>Instalación bloqueada</title></head><body '
       . 'style="font-family:sans-serif;max-width:600px;margin:3rem auto;padding:0 1rem">'
       . '<h1 style="color:#dc2626">Instalación ya realizada</h1>'
       . '<p>Este panel ya tiene usuarios de gestión, así que el instalador queda '
       . 'bloqueado: si siguiera activo, cualquiera podría restablecer las '
       . 'contraseñas de administración.</p>'
       . '<p><strong>Borra este archivo del servidor.</strong></p>'
       . '</body></html>';
    exit;
}
$sql = file_get_contents(__DIR__ . '/app/config/migracion.sql');

$pasos = array_filter(
    array_map('trim', explode(';', $sql)),
    function($s) { return $s !== '' && substr(ltrim($s), 0, 2) !== '--'; }
);

$errores = [];
foreach ($pasos as $paso) {
    try { $db->exec($paso); } catch (PDOException $e) { $errores[] = $e->getMessage(); }
}

$hash = password_hash($initialPassword, PASSWORD_BCRYPT, ['cost' => 12]);
$usuarios = [
    ['Admin',     'Sistema', '00000000A', 'admin@gimnasio.es',     'admin',     'admin'],
    ['Recepción', 'Prueba',  '11111111B', 'recepcion@gimnasio.es', 'recepcion', 'recepcion'],
    ['Socio',     'Prueba',  '22222222C', 'socio@gimnasio.es',     'socio',     'socio'],
];
$stmt = $db->prepare(
    "INSERT INTO usuario (nombre, apellidos, dni, email, nombre_usuario, contrasena, rol)
     VALUES (?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE rol = VALUES(rol), contrasena = VALUES(contrasena)"
);
foreach ($usuarios as $u) {
    try { $stmt->execute([$u[0],$u[1],$u[2],$u[3],$u[4],$hash,$u[5]]); }
    catch (PDOException $e) { $errores[] = $e->getMessage(); }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Instalación</title>
    <style>
        body{font-family:sans-serif;max-width:600px;margin:3rem auto;padding:0 1rem}
        h1{color:#111111}.ok{color:#5a9e0f;margin:.4rem 0}.err{color:#d9534f;margin:.4rem 0}
        table{border-collapse:collapse;width:100%;margin-top:1.5rem}
        th,td{border:1px solid #ddd;padding:.6rem 1rem;text-align:left}
        th{background:#e6e6e6}a{display:inline-block;margin-top:1.5rem;color:#404040}
        .warn{background:#fff3cd;border:1px solid #ffc107;padding:.8rem 1rem;border-radius:8px;margin-top:1.5rem;font-size:.9rem}
    </style>
</head>
<body>
    <h1>Instalación del Panel del Gimnasio</h1>
    <?php if (empty($errores)): ?>
        <p class="ok">Migración aplicada correctamente.</p>
        <p class="ok">Usuarios creados o actualizados.</p>
    <?php else: ?>
        <?php foreach ($errores as $e): ?><p class="err"><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    <?php endif; ?>
    <table>
        <tr><th>Usuario</th><th>Contraseña</th><th>Panel</th></tr>
        <tr><td>admin</td><td>Definida externamente</td><td>Gestión completa</td></tr>
        <tr><td>recepcion</td><td>Definida externamente</td><td>Ventas y socios</td></tr>
        <tr><td>socio</td><td>Definida externamente</td><td>Dashboard</td></tr>
    </table>
    <a href="public/?action=login">Ir al login</a>
    <div class="warn">Borra este archivo cuando termines.</div>
</body>
</html>
