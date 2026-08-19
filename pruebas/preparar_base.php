<?php
/** Reconstruye exclusivamente la base test desde migraciones y datos sintéticos. */
putenv('APP_ENV=test');
require_once __DIR__ . '/../app/config/config.php';

function abortarPreparacion(string $mensaje): void { fwrite(STDERR, "\nNO SE PREPARA LA BASE: {$mensaje}\n"); exit(1); }
if (PHP_SAPI !== 'cli' || APP_ENV !== 'test') abortarPreparacion('requiere CLI y APP_ENV=test.');
if (DB_NAME_PRUEBAS === '' || DB_NAME_PRUEBAS === DB_NAME) abortarPreparacion('la base test coincide con la base de trabajo.');
if (!preg_match('/(?:test|prueba)/i', DB_NAME_PRUEBAS)) abortarPreparacion('el nombre debe contener test o prueba.');

$db = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$nombre = str_replace('`', '', DB_NAME_PRUEBAS);
$destino = '`' . $nombre . '`';
echo "Reconstruyendo fixture independiente en {$nombre}\n";
$db->exec("DROP DATABASE IF EXISTS {$destino}");
$db->exec("CREATE DATABASE {$destino} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->exec("USE {$destino}");

$dir = __DIR__ . '/../app/config';
$scripts = [$dir . '/schema.sql', $dir . '/migracion.sql'];
for ($v = 2; $v <= 999 && is_file($dir . '/migracion_v' . $v . '.sql'); $v++) $scripts[] = $dir . '/migracion_v' . $v . '.sql';
foreach ($scripts as $script) {
    if (!is_file($script)) continue;
    $db->exec(str_replace('portal_de_cursos', $nombre, (string) file_get_contents($script)));
    echo '  aplicada ' . basename($script) . "\n";
}
if ($db->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn()) {
    $version = trim((string) @file_get_contents(__DIR__ . '/../VERSION')) ?: null;
    $track = $db->prepare('INSERT INTO schema_migrations (migration,checksum,release_version) VALUES (?,?,?)');
    foreach ($scripts as $script) $track->execute([basename($script), hash_file('sha256', $script), $version]);
}

$hash1234 = password_hash('1234', PASSWORD_BCRYPT, ['cost' => 4]);
$hashAdmin = password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 4]);
$hashGym1 = password_hash('cleto2026', PASSWORD_BCRYPT, ['cost' => 4]);
$hashGym2 = password_hash('norte2026', PASSWORD_BCRYPT, ['cost' => 4]);
$empresa = (int) $db->query('SELECT MIN(id_empresa) FROM empresa')->fetchColumn();
$db->exec('DELETE FROM suplemento');
$db->exec('DELETE FROM tipo_membresia');
$db->exec('DELETE FROM categoria_producto');
$db->exec('DELETE FROM gimnasio');
$stmt = $db->prepare("INSERT INTO gimnasio (id_gimnasio,id_empresa,nombre,slug,email_acceso,contrasena_acceso,logo,color_primario,color_texto,activo) VALUES (?,?,?,?,?,?,?,?,?,1)");
$stmt->execute([1,$empresa,'Cleto Reyes Villaviciosa','cleto','cleto.reyes.villaviciosa@gmail.com',$hashGym1,'logo_cleto_reyes.png','#4f46e5','#ffffff']);
$stmt->execute([4,$empresa,'Sede Norte','norte','sede.norte@gmail.com',$hashGym2,null,'#111111','#ffffff']);
$stmt = $db->prepare("INSERT INTO usuario (id_usuario,id_empresa,id_gimnasio,nombre,apellidos,dni,email,nombre_usuario,contrasena,rol,activo) VALUES (?,?,?,?,?,?,?,?,?,?,1)");
$stmt->execute([1,$empresa,1,'Daniel','Admin','TEST-DANIEL','daniel@test.invalid','daniel',$hash1234,'admin']);
$stmt->execute([2,$empresa,1,'Kevin','Recepción','TEST-KEVIN','kevin@test.invalid','kevin',$hash1234,'recepcion']);
$stmt->execute([3,$empresa,1,'Omar','Socio','TEST-OMAR','omar@test.invalid','omar',$hashAdmin,'socio']);
$stmt->execute([4,$empresa,1,'Pedro','Recepción','TEST-PEDRO','pedro@test.invalid','pedro',$hash1234,'recepcion']);
$stmt->execute([5,$empresa,4,'Nora','Recepción','TEST-NORA','nora@test.invalid','nora',$hashAdmin,'recepcion']);
$stmt->execute([6,null,null,'Plataforma','Interna','TEST-PLAT','empresa@test.invalid','empresa',$hashAdmin,'superadmin']);
$db->exec("INSERT INTO categoria_producto (id_categoria,nombre_categoria) VALUES (1,'Bebidas'),(2,'Nutrición')");
$db->exec("INSERT INTO producto (id_producto,nombre,precio,stock,stock_minimo,estado,id_categoria,id_gimnasio) VALUES (1,'Agua','1.00',50,5,'activo',1,1),(2,'Bebida isotónica','2.50',20,5,'activo',1,1),(3,'Proteína','24.90',8,2,'activo',2,1),(4,'Barrita energética','2.00',4,2,'activo',2,1)");
$db->exec("INSERT INTO tipo_membresia (id_tipo_membresia,id_empresa,id_gimnasio,nombre,precio,duracion_meses,estado) VALUES (1,{$empresa},NULL,'Mensual','40.00',1,'activo'),(2,{$empresa},NULL,'Trimestral','95.00',3,'activo')");
$db->exec("INSERT INTO suplemento (id_suplemento,id_empresa,id_gimnasio,nombre,precio_mensual,estado) VALUES (1,{$empresa},NULL,'Artes marciales','25.00','activo')");
echo "Fixture listo: datos sintéticos; no se leyó ninguna tabla de " . DB_NAME . ".\n";
