<?php
/** Pruebas hostiles de aislamiento entre empresas. */

error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/_arranque.php';

$raiz = dirname(__DIR__);
require_once $raiz . '/app/models/UserModel.php';
require_once $raiz . '/app/models/ProductoModel.php';
require_once $raiz . '/app/models/VentaModel.php';
require_once $raiz . '/app/models/MembresiaModel.php';
require_once $raiz . '/app/models/SepaModel.php';
require_once $raiz . '/app/models/LogModel.php';
require_once $raiz . '/app/helpers/TenantContext.php';

$db = Database::getInstance()->getConnection();
$ok = 0; $fallos = 0;
function comprobarEmpresa(string $descripcion, $esperado, $real): void {
    global $ok, $fallos;
    if ((string) $esperado === (string) $real) {
        $ok++; echo "  OK   {$descripcion}\n";
    } else {
        $fallos++; echo "  FALLO {$descripcion} — esperaba [{$esperado}], obtuvo [{$real}]\n";
    }
}

// Limpieza defensiva de una ejecución interrumpida anterior.
$db->exec("DELETE FROM log_actividad WHERE accion LIKE 'TEST TENANT %'");
pruebasLimpiarVentas($db, "v.id_gimnasio IN (SELECT id_gimnasio FROM gimnasio WHERE nombre LIKE 'TEST TENANT %')");
pruebasLimpiarVentas($db, "v.serie = 'T'");
$db->exec("DELETE FROM producto WHERE nombre LIKE 'TEST TENANT %'");
$db->exec("DELETE FROM usuario WHERE nombre_usuario LIKE 'test_tenant_%'");
$db->exec("DELETE FROM gimnasio WHERE nombre LIKE 'TEST TENANT %'");
$db->exec("DELETE FROM empresa WHERE nombre LIKE 'TEST TENANT %'");

$db->exec("INSERT INTO empresa (nombre, nombre_comercial, slug, estado, onboarding_state) VALUES ('TEST TENANT Empresa A','Empresa A','test-tenant-a','activa','ACTIVE')");
$empresaA = (int) $db->lastInsertId();
$db->exec("INSERT INTO empresa (nombre, nombre_comercial, slug, estado, onboarding_state) VALUES ('TEST TENANT Empresa B','Empresa B','test-tenant-b','activa','ACTIVE')");
$empresaB = (int) $db->lastInsertId();
$db->exec("INSERT INTO gimnasio (id_empresa,nombre) VALUES ($empresaA,'TEST TENANT Sede A1')");
$sedeA = (int) $db->lastInsertId();
$db->exec("INSERT INTO gimnasio (id_empresa,nombre) VALUES ($empresaB,'TEST TENANT Sede B1')");
$sedeB = (int) $db->lastInsertId();

$hash = password_hash('test1234', PASSWORD_BCRYPT);
$stmt = $db->prepare("INSERT INTO usuario
    (id_empresa,id_gimnasio,nombre,apellidos,dni,email,nombre_usuario,contrasena,rol)
    VALUES (?,?,?,?,?,?,?,?,?)");
$stmt->execute([$empresaA,null,'Dirección','A','TENANTA','tenant-a@test.invalid','test_tenant_dir_a',$hash,'direccion']);
$direccionA = (int) $db->lastInsertId();
$stmt->execute([$empresaB,null,'Dirección','B','TENANTDB','tenant-dir-b@test.invalid','test_tenant_dir_b',$hash,'direccion']);
$direccionB = (int) $db->lastInsertId();
$stmt->execute([$empresaB,$sedeB,'Socio','B','TENANTB','socio-b@test.invalid','test_tenant_socio_b',$hash,'socio']);
$socioB = (int) $db->lastInsertId();
$stmt->execute([$empresaA,$sedeB,'Usuario','Inconsistente','TENANTX','tenant-x@test.invalid','test_tenant_inconsistente',$hash,'admin']);
$usuarioInconsistente = (int) $db->lastInsertId();

$productosA = new ProductoModel($sedeA, $empresaA);
$productosB = new ProductoModel($sedeB, $empresaB);
$productosA->crear('TEST TENANT producto A', null, 10, 5, 1, 'activo', null);
$productoA = (int) $db->query("SELECT id_producto FROM producto WHERE nombre='TEST TENANT producto A' AND id_gimnasio={$sedeA}")->fetchColumn();
$productosB->crear('TEST TENANT producto B', null, 20, 7, 1, 'activo', null);
$productoB = (int) $db->query("SELECT id_producto FROM producto WHERE nombre='TEST TENANT producto B' AND id_gimnasio={$sedeB}")->fetchColumn();
$ventasB = new VentaModel($sedeB, $empresaB);
$error = '';
$ventaB = $ventasB->registrar([['id_producto' => $productoB, 'cantidad' => 1]], $socioB, 'efectivo', $direccionB, $error);

echo "== EMPRESA A CONTRA OBJETOS DE EMPRESA B ==\n";
$usuariosA = new UserModel(null, $empresaA);
comprobarEmpresa('A no puede ver socio B cambiando el id de URL', true, $usuariosA->buscarPorId($socioB) === null);
comprobarEmpresa('A no puede acceder a empleado B', true, $usuariosA->buscarPorId($direccionB) === null);
$usuariosA->actualizarDatosSocio($socioB, 'Hack', 'Hack', null, 'hack@test.invalid', null);
comprobarEmpresa('A no puede editar socio B por POST manual', 'Socio', $db->query("SELECT nombre FROM usuario WHERE id_usuario=$socioB")->fetchColumn());
comprobarEmpresa('A no puede leer producto B por id', true, $productosA->buscarPorId($productoB) === null);
$productosA->actualizarStock($productoB, 999);
comprobarEmpresa('A no puede modificar stock de B', 6, $db->query("SELECT stock FROM producto WHERE id_producto=$productoB")->fetchColumn());
$productosA->cambiarEstado($productoB, 'inactivo');
comprobarEmpresa('A no puede eliminar/desactivar producto B', 'activo', $db->query("SELECT estado FROM producto WHERE id_producto=$productoB")->fetchColumn());
comprobarEmpresa('A no puede consultar venta B', true, (new VentaModel(null, $empresaA))->buscarPorId((int) $ventaB) === null);
comprobarEmpresa('el informe de ventas A no suma B', '0.00', number_format((new VentaModel(null, $empresaA))->sumarDelDia(), 2, '.', ''));
comprobarEmpresa('A no puede anonimizar/eliminar socio B', true, $usuariosA->anonimizar($socioB) === null);
comprobarEmpresa('socio B conserva sus datos tras la baja hostil', 'Socio', $db->query("SELECT nombre FROM usuario WHERE id_usuario=$socioB")->fetchColumn());
$error = '';
$ventaA = new VentaModel($sedeA, $empresaA);
comprobarEmpresa(
    'A no puede asociar socio B a una venta mediante POST',
    true,
    $ventaA->registrar([['id_producto' => $productoA, 'cantidad' => 1]], $socioB, 'efectivo', $direccionA, $error) === null
);
$membresiasA = new MembresiaModel($sedeA, $empresaA);
$error = '';
comprobarEmpresa('A no puede contratar una cuota para socio B', true, $membresiasA->contratar($socioB, 1, 'efectivo', $error) === null);
$error = '';
comprobarEmpresa('A no puede iniciar una prueba para socio B', true, $membresiasA->iniciarPrueba($socioB, $error) === null);
$sepaA = new SepaModel($sedeA, $empresaA);
$error = '';
comprobarEmpresa(
    'A no puede crear un mandato para socio B',
    true,
    $sepaA->crearMandato($socioB, 'ES9121000418450200051332', date('Y-m-d'), $error) === null
);

echo "\n== MANIPULACIÓN DE EMPRESA Y SEDE ==\n";
$_SESSION = [
    'logueado' => true,
    'usuario_id' => $direccionA,
    'usuario_rol' => 'direccion',
    'empresa_id' => $empresaA,
    'gimnasio_activo' => $sedeB,       // manipulación deliberada
];
$_POST['empresa_id'] = $empresaB;       // el contexto nunca lee este campo
$ctx = TenantContext::desdeSesion();
comprobarEmpresa('empresa sale del usuario autenticado, no del POST', $empresaA, $ctx->empresaId());
comprobarEmpresa('sede B manipulada se descarta', true, $ctx->sedeId() === null);
comprobarEmpresa('A no puede seleccionar sede B', false, $ctx->seleccionarSede($sedeB));
comprobarEmpresa('A sí puede seleccionar sede A1', true, $ctx->seleccionarSede($sedeA));
$_SESSION = ['logueado' => true, 'usuario_id' => $usuarioInconsistente];
comprobarEmpresa(
    'una relación empresa/sede inconsistente invalida la sesión',
    false,
    TenantContext::desdeSesion()->autenticado()
);

echo "\n== CONSULTAS DE EMPRESA COMPLETA ==\n";
$productosEmpresaA = new ProductoModel(null, $empresaA);
$productosEmpresaB = new ProductoModel(null, $empresaB);
comprobarEmpresa('dirección A ve su producto', 1, count($productosEmpresaA->listarTodos('TEST TENANT')));
comprobarEmpresa('dirección A no infiere productos B', 'TEST TENANT producto A', $productosEmpresaA->listarTodos('TEST TENANT')[0]['nombre'] ?? '');
comprobarEmpresa('dirección B ve solo el suyo', 'TEST TENANT producto B', $productosEmpresaB->listarTodos('TEST TENANT')[0]['nombre'] ?? '');

$logA = new LogModel($empresaA);
$logB = new LogModel($empresaB);
$logA->registrarCambio($direccionA, 'TEST TENANT acción A', 'solo A', null, 'producto', $productoA, null, null, $sedeA);
comprobarEmpresa('B no puede leer logs de A', 0, count($logB->listar(20, null, ['buscar' => 'TEST TENANT acción A'])));
$db->exec("UPDATE empresa SET estado='inactiva' WHERE id_empresa=$empresaA");
$_SESSION = ['logueado' => true, 'usuario_id' => $direccionA];
comprobarEmpresa('una empresa inactiva no puede conservar acceso', false, TenantContext::desdeSesion()->autenticado());
$db->exec("UPDATE empresa SET estado='activa' WHERE id_empresa=$empresaA");

// Limpieza en orden de dependencias.
$db->exec("DELETE FROM log_actividad WHERE accion LIKE 'TEST TENANT %'");
pruebasLimpiarVentas($db, "v.id_gimnasio IN (SELECT id_gimnasio FROM gimnasio WHERE nombre LIKE 'TEST TENANT %')");
$db->exec("DELETE FROM producto WHERE nombre LIKE 'TEST TENANT %'");
$db->exec("DELETE FROM usuario WHERE nombre_usuario LIKE 'test_tenant_%'");
$db->exec("DELETE FROM gimnasio WHERE nombre LIKE 'TEST TENANT %'");
$db->exec("DELETE FROM empresa WHERE nombre LIKE 'TEST TENANT %'");

echo "\n== RESUMEN: {$ok} correctas, {$fallos} fallidas ==\n";
exit($fallos > 0 ? 1 : 0);
