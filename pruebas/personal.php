<?php
/**
 * Sedes y personal: alta, edición y reglas de permisos.
 *
 * Lo que se comprueba aquí no es que el CRUD funcione, sino que las reglas de
 * quién puede tocar a quién se cumplen a nivel de modelo, y que no se puede
 * dejar el panel sin nadie capaz de administrarlo.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/_arranque.php';   // base de pruebas, nunca la de trabajo

$raiz = dirname(__DIR__);
require_once $raiz . '/app/models/GimnasioModel.php';
require_once $raiz . '/app/models/UserModel.php';

$db = Database::getInstance()->getConnection();
$ok = 0; $fallos = 0;
function comprobar(string $d, $esperado, $real) {
    global $ok, $fallos;
    if ((string) $esperado === (string) $real) { $ok++; echo "  OK   $d\n"; }
    else { $fallos++; echo "  FALLO $d — esperaba [$esperado], obtuve [$real]\n"; }
}

$idEmpresa = (int) $db->query('SELECT MIN(id_empresa) FROM empresa')->fetchColumn();
$gim = new GimnasioModel($idEmpresa);

// --- Estado de partida -------------------------------------------------------
$db->exec("DELETE FROM usuario  WHERE nombre_usuario LIKE 'test_%'");
$db->exec("DELETE FROM gimnasio WHERE nombre LIKE 'TEST %'");

echo "== SEDES ==\n";
$idSedeA = $gim->crear([
    'nombre' => 'TEST Sede A', 'razon_social' => 'Test A SL', 'cif' => 'B00000001',
    'direccion' => 'C/ Uno 1', 'telefono' => '900000001', 'email' => 'a@test.es',
]);
comprobar('se crea la sede', true, $idSedeA !== null);

$sede = $gim->buscarPorId($idSedeA);
comprobar('nace abierta',      1,            $sede['activo']);
comprobar('guarda razón social', 'Test A SL', $sede['razon_social']);
comprobar('detecta nombre repetido', true,   $gim->nombreExiste('TEST Sede A'));
comprobar('permite el mismo nombre al editarse a sí misma', false, $gim->nombreExiste('TEST Sede A', $idSedeA));

$idSedeB = $gim->crear(['nombre' => 'TEST Sede B', 'razon_social' => '', 'cif' => '', 'direccion' => '', 'telefono' => '', 'email' => '']);

$gim->actualizar($idSedeA, [
    'nombre' => 'TEST Sede A renombrada', 'razon_social' => 'Test A SL', 'cif' => 'B00000001',
    'direccion' => '', 'telefono' => '', 'email' => '',
]);
comprobar('se edita el nombre', 'TEST Sede A renombrada', $gim->buscarPorId($idSedeA)['nombre']);

$gim->toggleActivo($idSedeB);
comprobar('se cierra una sede', 0, $gim->buscarPorId($idSedeB)['activo']);
$activas = array_column($gim->listarActivas(), 'nombre');
comprobar('la cerrada desaparece de las altas', false, in_array('TEST Sede B', $activas, true));

echo "\n== ALTA DE PERSONAL ==\n";
$userA = new UserModel($idSedeA);

$idRecep = $userA->crearEmpleado('Test', 'Recepcion', 'X0000001T', 'test_recep@test.es', null,
    'test_recep', 'clave12345', 'recepcion', $idSedeA);
comprobar('se crea recepción', true, $idRecep !== null);

$idAdmin = $userA->crearEmpleado('Test', 'Admin', 'X0000002T', 'test_admin@test.es', null,
    'test_admin', 'clave12345', 'admin', $idSedeA);
comprobar('se crea administrador', true, $idAdmin !== null);

comprobar('rechaza un rol inventado', true,
    $userA->crearEmpleado('Test', 'Malo', 'X0000003T', 'test_malo@test.es', null,
        'test_malo', 'clave12345', 'superusuario', $idSedeA) === null);

comprobar('rechaza dar de alta como socio por esta vía', true,
    $userA->crearEmpleado('Test', 'Socio', 'X0000004T', 'test_socio@test.es', null,
        'test_socio', 'clave12345', 'socio', $idSedeA) === null);

$creado = $userA->buscarPorId($idRecep);
comprobar('queda en su sede',   $idSedeA, $creado['id_gimnasio']);
comprobar('nace con acceso',    1,        $creado['activo']);
comprobar('la contraseña se guarda cifrada', true, password_verify('clave12345', $creado['contrasena']));

echo "\n== AISLAMIENTO ENTRE SEDES ==\n";
$userB = new UserModel($idSedeB);
$nombresB = array_column($userB->listarEmpleados(), 'nombre_usuario');
comprobar('la sede B no ve al personal de A', false, in_array('test_recep', $nombresB, true));

$nombresA = array_column($userA->listarEmpleados(), 'nombre_usuario');
comprobar('la sede A sí ve a los suyos', true, in_array('test_recep', $nombresA, true));

$todos = array_column((new UserModel(null))->listarEmpleados(), 'nombre_usuario');
comprobar('la empresa ve a todos', true, in_array('test_recep', $todos, true));

// El aislamiento tiene que valer también cuando llega un id concreto, que es
// como se colaba antes: buscarPorId() era la comprobación de permisos de media
// docena de acciones del panel y no miraba la sede.
comprobar('la sede B no alcanza a un empleado de A por su id', true,
    $userB->buscarPorId($idRecep) === null);
comprobar('la sede B no puede editarlo', false,
    $userB->actualizarEmpleado($idRecep, 'Robado', 'Robado', 'robado@test.es', null, 'recepcion', $idSedeB)
        && $userA->buscarPorId($idRecep)['nombre'] === 'Robado');
comprobar('la sede B no puede bloquearlo', false, $userB->toggleActivo($idRecep));
comprobar('sigue con su acceso intacto', 1, $userA->buscarPorId($idRecep)['activo']);

echo "\n== EDICIÓN Y CAMBIO DE ROL ==\n";
$userA->actualizarEmpleado($idRecep, 'Test', 'Recepcion Editada', 'test_recep2@test.es', '600111222', 'recepcion', $idSedeA);
$editado = $userA->buscarPorId($idRecep);
comprobar('se editan los datos', 'Recepcion Editada', $editado['apellidos']);
comprobar('se actualiza el email', 'test_recep2@test.es', $editado['email']);

$userA->actualizarEmpleado($idRecep, 'Test', 'Recepcion Editada', 'test_recep2@test.es', null, 'admin', $idSedeA);
comprobar('se puede ascender a admin', 'admin', $userA->buscarPorId($idRecep)['rol']);

// Ascender a dirección deja al usuario sin sede, así que a partir de ahí ya no
// es "de la sede A" y solo lo alcanza un modelo sin filtro. Es justo lo que
// pasa en el panel: los roles solo los cambia dirección, que trabaja sin sede.
$userEmpresa = new UserModel(null, $idEmpresa);
$userEmpresa->actualizarEmpleado($idRecep, 'Test', 'Recepcion Editada', 'test_recep2@test.es', null, 'direccion', $idSedeA);
$tras = $userEmpresa->buscarPorId($idRecep);
comprobar('dirección queda sin sede', true, $tras['id_gimnasio'] === null);

// Volvemos a dejarlo como recepción de la sede A.
$userEmpresa->actualizarEmpleado($idRecep, 'Test', 'Recepcion Editada', 'test_recep2@test.es', null, 'recepcion', $idSedeA);
comprobar('vuelve a ser de la sede A', $idSedeA, $userA->buscarPorId($idRecep)['id_gimnasio']);

echo "\n== NO QUEDARSE SIN ADMINISTRADORES ==\n";
$gestores = $userA->contarGestoresActivos();
comprobar('hay gestores activos en el sistema', true, $gestores > 0);
comprobar('excluyendo al único admin de la sede no queda otro', 0, $userA->contarGestoresActivos($idAdmin));

// Bloqueamos a todos menos al admin de prueba para simular el caso límite.
$db->exec("UPDATE usuario SET activo = 0 WHERE rol IN ('superadmin','direccion','admin') AND id_usuario <> $idAdmin");
comprobar('si solo queda uno, el contador excluyéndolo es 0', 0, $userA->contarGestoresActivos($idAdmin));
$db->exec("UPDATE usuario SET activo = 1 WHERE rol IN ('superadmin','direccion','admin')");

echo "\n== BLOQUEO DE ACCESO ==\n";
$userA->toggleActivo($idRecep);
comprobar('el empleado queda bloqueado', 0, $userA->buscarPorId($idRecep)['activo']);
comprobar('sigue apareciendo en el listado', true,
    in_array('test_recep', array_column($userA->listarEmpleados(), 'nombre_usuario'), true));
$userA->toggleActivo($idRecep);
comprobar('se le restablece el acceso', 1, $userA->buscarPorId($idRecep)['activo']);

// --- Limpieza ----------------------------------------------------------------
$db->exec("DELETE FROM usuario  WHERE nombre_usuario LIKE 'test_%'");
$db->exec("DELETE FROM gimnasio WHERE nombre LIKE 'TEST %'");

echo "\n== RESUMEN: $ok correctas, $fallos fallidas ==\n";
exit($fallos === 0 ? 0 : 1);
