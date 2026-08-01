<?php
/**
 * Aislamiento entre gimnasios y trazabilidad del historial.
 *
 * Lo que se comprueba aquí es lo único que hace segura la multi-sede: que un
 * gimnasio no vea ni pueda modificar los datos de otro, ni siquiera pasando
 * a mano el id de un registro ajeno.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/_arranque.php';   // base de pruebas, nunca la de trabajo

$raiz = dirname(__DIR__);
require_once $raiz . '/app/models/ProductoModel.php';
require_once $raiz . '/app/models/VentaModel.php';
require_once $raiz . '/app/models/MembresiaModel.php';
require_once $raiz . '/app/models/UserModel.php';
require_once $raiz . '/app/models/LogModel.php';

$db = Database::getInstance()->getConnection();
$ok = 0; $fallos = 0;
function comprobar(string $d, $esperado, $real) {
    global $ok, $fallos;
    if ((string) $esperado === (string) $real) { $ok++; echo "  OK   $d\n"; }
    else { $fallos++; echo "  FALLO $d — esperaba [$esperado], obtuve [$real]\n"; }
}

// --- Preparación: dos sedes limpias -----------------------------------------
$db->exec("DELETE FROM venta_linea WHERE id_venta IN (SELECT id_venta FROM venta WHERE id_gimnasio > 1)");
$db->exec("DELETE FROM venta     WHERE id_gimnasio > 1");
$db->exec("DELETE FROM producto  WHERE nombre LIKE 'TEST %'");
$db->exec("DELETE FROM log_actividad WHERE accion LIKE 'TEST %'");
$db->exec("DELETE FROM gimnasio  WHERE nombre = 'Sede de pruebas'");
$db->exec("INSERT INTO gimnasio (nombre) VALUES ('Sede de pruebas')");
$sedeB = (int) $db->lastInsertId();
$sedeA = (int) $db->query("SELECT MIN(id_gimnasio) FROM gimnasio")->fetchColumn();

echo "== SEDES: A=$sedeA  B=$sedeB ==\n\n";

$prodA = new ProductoModel($sedeA);
$prodB = new ProductoModel($sedeB);

echo "== PRODUCTOS: cada sede ve solo lo suyo ==\n";
$prodA->crear('TEST agua sede A', null, 1.00, 10, 2, 'activo', null);
$prodB->crear('TEST agua sede B', null, 2.00, 20, 2, 'activo', null);

$nombresA = array_column($prodA->listarTodos('TEST'), 'nombre');
$nombresB = array_column($prodB->listarTodos('TEST'), 'nombre');
comprobar('A ve 1 producto TEST',      1, count($nombresA));
comprobar('A ve el suyo',              'TEST agua sede A', $nombresA[0] ?? '');
comprobar('B ve 1 producto TEST',      1, count($nombresB));
comprobar('B ve el suyo',              'TEST agua sede B', $nombresB[0] ?? '');

// Id del producto de B, para intentar accesos cruzados desde A.
$idProdB = (int) $db->query("SELECT id_producto FROM producto WHERE nombre = 'TEST agua sede B'")->fetchColumn();
$idProdA = (int) $db->query("SELECT id_producto FROM producto WHERE nombre = 'TEST agua sede A'")->fetchColumn();

echo "\n== ACCESO CRUZADO: A intenta llegar a un producto de B ==\n";
comprobar('A no puede leer el producto de B', true, $prodA->buscarPorId($idProdB) === null);

$prodA->actualizarStock($idProdB, 999);
$stockRealB = (int) $db->query("SELECT stock FROM producto WHERE id_producto = $idProdB")->fetchColumn();
comprobar('A no puede cambiar el stock de B', 20, $stockRealB);

$prodA->cambiarEstado($idProdB, 'inactivo');
$estadoRealB = (string) $db->query("SELECT estado FROM producto WHERE id_producto = $idProdB")->fetchColumn();
comprobar('A no puede desactivar el producto de B', 'activo', $estadoRealB);

echo "\n== VENTAS: A no puede vender stock de B ==\n";
$ventaA = new VentaModel($sedeA);
$err = '';
$idVenta = $ventaA->registrar([['id_producto' => $idProdB, 'cantidad' => 1]], null, 'efectivo', 1, $err);
comprobar('la venta cruzada se rechaza', true, $idVenta === null);
comprobar('stock de B intacto tras el intento', 20, (int) $db->query("SELECT stock FROM producto WHERE id_producto = $idProdB")->fetchColumn());

$err = '';
$idVentaOk = $ventaA->registrar([['id_producto' => $idProdA, 'cantidad' => 2]], null, 'efectivo', 1, $err);
comprobar('la venta en su propia sede sí funciona', true, $idVentaOk !== null);
comprobar('stock de A descontado', 8, (int) $db->query("SELECT stock FROM producto WHERE id_producto = $idProdA")->fetchColumn());

$ventaB = new VentaModel($sedeB);
comprobar('B no ve la venta de A', true, $ventaB->buscarPorId((int) $idVentaOk) === null);
comprobar('la caja de B no suma la venta de A', '0', number_format($ventaB->sumarDelDia(), 0, '', ''));

echo "\n== PROPIETARIO: sin filtro, lo ve todo ==\n";
$prodTodos = new ProductoModel(null);
comprobar('la empresa ve los productos de ambas sedes', 2, count($prodTodos->listarTodos('TEST')));

echo "\n== HISTORIAL: quién, sobre quién y qué cambió ==\n";
$log = new LogModel();
$log->registrarCambio(
    1, 'TEST Cambio de vencimiento', 'Membresía de Omar',
    3, 'socio', 3, '2026-08-30', '2026-09-30', $sedeA
);
$entradas = $log->listar(10, $sedeA, ['buscar' => 'TEST Cambio']);
$e = $entradas[0] ?? [];
comprobar('queda registrado quién actúa',   'admin', $e['autor_usuario'] ?? '');
comprobar('queda registrado sobre quién',   'Socio', $e['afectado_nombre'] ?? '');
comprobar('guarda el valor anterior',       '2026-08-30', $e['valor_anterior'] ?? '');
comprobar('guarda el valor nuevo',          '2026-09-30', $e['valor_nuevo'] ?? '');
comprobar('guarda la sede',                 $sedeA, $e['id_gimnasio'] ?? '');

$entradasB = $log->listar(10, $sedeB, ['buscar' => 'TEST Cambio']);
comprobar('el historial de B no ve la entrada de A', 0, count($entradasB));

// --- Limpieza ---------------------------------------------------------------
$db->exec("DELETE FROM venta_linea WHERE id_venta IN (SELECT id_venta FROM venta WHERE id_gimnasio = $sedeB OR id_venta = " . (int) $idVentaOk . ")");
$db->exec("DELETE FROM venta WHERE id_gimnasio = $sedeB OR id_venta = " . (int) $idVentaOk);
$db->exec("DELETE FROM producto WHERE nombre LIKE 'TEST %'");
$db->exec("DELETE FROM log_actividad WHERE accion LIKE 'TEST %'");
$db->exec("DELETE FROM gimnasio WHERE id_gimnasio = $sedeB");

echo "\n== RESUMEN: $ok correctas, $fallos fallidas ==\n";
