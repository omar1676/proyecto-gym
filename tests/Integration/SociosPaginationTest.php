<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/models/MembresiaModel.php';

$db = Database::getInstance()->getConnection();
$db->beginTransaction();

try {
    $sedeA = $db->query('SELECT id_gimnasio, id_empresa FROM gimnasio ORDER BY id_gimnasio LIMIT 1')->fetch();
    $idEmpresaA = (int) $sedeA['id_empresa'];
    $idSedeA = (int) $sedeA['id_gimnasio'];

    $insertEmpresa = $db->prepare(
        "INSERT INTO empresa (nombre, nombre_comercial, slug, email, telefono, estado)
         VALUES ('FASE7 Empresa B', 'FASE7 Empresa B', 'fase7-empresa-b', 'fase7.b@test.invalid', '600000001', 'activa')"
    );
    $insertEmpresa->execute();
    $idEmpresaB = (int) $db->lastInsertId();

    $insertSede = $db->prepare(
        "INSERT INTO gimnasio (id_empresa, nombre, slug, email_acceso, contrasena_acceso, activo)
         VALUES (:empresa, 'FASE7 Sede B', :slug, :email, :clave, 1)"
    );
    $insertSede->execute([
        ':empresa' => $idEmpresaB,
        ':slug' => 'fase7-test-b',
        ':email' => 'fase7.sede.b@test.invalid',
        ':clave' => password_hash('test-only', PASSWORD_BCRYPT, ['cost' => 4]),
    ]);
    $idSedeB = (int) $db->lastInsertId();

    $insert = $db->prepare(
        "INSERT INTO usuario
         (id_empresa, id_gimnasio, nombre, apellidos, dni, telefono, email,
          nombre_usuario, contrasena, rol, activo)
         VALUES
         (:empresa, :sede, :nombre, :apellidos, :dni, :telefono, :email,
          :usuario, :clave, 'socio', 1)"
    );
    $clave = password_hash('test-only', PASSWORD_BCRYPT, ['cost' => 4]);

    for ($i = 1; $i <= 55; $i++) {
        $insert->execute([
            ':empresa' => $idEmpresaA,
            ':sede' => $idSedeA,
            ':nombre' => $i === 1 ? 'Álvaro FASE7TEST' : 'Socio FASE7TEST',
            ':apellidos' => sprintf('Paginado %03d', $i),
            ':dni' => sprintf('F7A%06d', $i),
            ':telefono' => $i === 1 ? '+34 600-123-456' : '610' . sprintf('%06d', $i),
            ':email' => sprintf('fase7.a.%03d@test.invalid', $i),
            ':usuario' => sprintf('fase7_a_%03d', $i),
            ':clave' => $clave,
        ]);
    }

    // Mismo texto buscable, pero otro tenant: nunca debe entrar en el total A.
    $insert->execute([
        ':empresa' => $idEmpresaB,
        ':sede' => $idSedeB,
        ':nombre' => 'Socio FASE7TEST',
        ':apellidos' => 'Tenant B',
        ':dni' => 'F7B000001',
        ':telefono' => '+34 600-123-456',
        ':email' => 'fase7.b.001@test.invalid',
        ':usuario' => 'fase7_b_001',
        ':clave' => $clave,
    ]);

    $model = new MembresiaModel($idSedeA, $idEmpresaA);
    $pagina1 = $model->paginarSocios('FASE7TEST', 1, 25);
    $pagina2 = $model->paginarSocios('FASE7TEST', 2, 25);
    $pagina3 = $model->paginarSocios('FASE7TEST', 3, 25);

    check('cuenta solo resultados del tenant y sede autorizados', $pagina1['total'] === 55);
    check('primera página carga 25 filas desde SQL', count($pagina1['items']) === 25);
    check('segunda página carga otras 25 filas', count($pagina2['items']) === 25);
    check('última página contiene las 5 filas restantes', count($pagina3['items']) === 5);
    check('informa tres páginas reales', $pagina1['paginas'] === 3);

    $telefono = $model->paginarSocios('600 123', 1, 25);
    check('busca teléfono parcial ignorando espacios y guiones', $telefono['total'] === 1);

    $email = $model->paginarSocios('FASE7.A.001@TEST.INVALID', 1, 25);
    check('búsqueda de email no distingue mayúsculas en la colación actual', $email['total'] === 1);

    $acentos = $model->paginarSocios('alvaro', 1, 25);
    check('búsqueda nominal admite acentos según la colación actual', $acentos['total'] === 1);

    $especial = $model->paginarSocios('%_', 1, 25);
    check('porcentaje y guion bajo se buscan como texto, no como comodines', $especial['total'] === 0);

    $db->rollBack();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    check('la prueba de paginación se ejecuta sin excepción', false);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
}

finishTests();
