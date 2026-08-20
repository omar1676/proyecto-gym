<?php
/** Amplía la empresa inicial hasta 5.000 socios sintéticos para Fase 7. */

require_once __DIR__ . '/_arranque.php';

set_time_limit(0);
$db = Database::getInstance()->getConnection();
$idEmpresa = (int) $db->query('SELECT MIN(id_empresa) FROM empresa')->fetchColumn();
$stmt = $db->prepare('SELECT id_gimnasio FROM gimnasio WHERE id_empresa = :empresa AND activo = 1 ORDER BY id_gimnasio');
$stmt->execute([':empresa' => $idEmpresa]);
$sedes = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
if ($idEmpresa <= 0 || !$sedes) {
    fwrite(STDERR, "No hay empresa/sede inicial en la base de pruebas.\n");
    exit(1);
}

$stmt = $db->prepare("SELECT COUNT(*) FROM usuario WHERE rol = 'socio' AND id_empresa = :empresa");
$stmt->execute([':empresa' => $idEmpresa]);
$actual = (int) $stmt->fetchColumn();
if ($actual >= 5000) {
    echo "La empresa {$idEmpresa} ya tiene {$actual} socios; no se duplica la carga.\n";
    exit(0);
}

$faltan = 5000 - $actual;
$clave = password_hash('fase7-test-only', PASSWORD_BCRYPT, ['cost' => 4]);
$insert = $db->prepare(
    "INSERT INTO usuario
     (id_empresa, id_gimnasio, nombre, apellidos, dni, telefono, email,
      nombre_usuario, contrasena, rol, activo)
     VALUES
     (:empresa, :sede, :nombre, :apellidos, :dni, :telefono, :email,
      :usuario, :clave, 'socio', 1)"
);

$inicio = microtime(true);
try {
    $db->beginTransaction();
    for ($i = 1; $i <= $faltan; $i++) {
        $grupo = $i <= 50 ? 'F7V050' : ($i <= 550 ? 'F7V500' : 'F7VREST');
        $secuencia = $actual + $i;
        $insert->execute([
            ':empresa' => $idEmpresa,
            ':sede' => $sedes[($i - 1) % count($sedes)],
            ':nombre' => $grupo . ' Socio',
            ':apellidos' => sprintf('Rendimiento %05d', $secuencia),
            ':dni' => sprintf('F7%08d', $secuencia),
            ':telefono' => '+34 600-' . sprintf('%03d-%03d', intdiv($secuencia, 1000) % 1000, $secuencia % 1000),
            ':email' => sprintf('fase7.volumen.%05d@test.invalid', $secuencia),
            ':usuario' => sprintf('fase7_volumen_%05d', $secuencia),
            ':clave' => $clave,
        ]);
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'Carga Fase 7 cancelada: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$stmt->execute([':empresa' => $idEmpresa]);
$final = (int) $stmt->fetchColumn();
printf(
    "Carga Fase 7: empresa=%d; antes=%d; añadidos=%d; final=%d; tiempo=%.2f s\n",
    $idEmpresa,
    $actual,
    $faltan,
    $final,
    microtime(true) - $inicio
);
exit($final === 5000 ? 0 : 1);
