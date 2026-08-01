<?php
/**
 * Facturación: numeración, desglose de IVA y anulación sin borrado.
 *
 * Lo que se comprueba aquí no es que la venta funcione (de eso va negocio.php),
 * sino las tres reglas que hacen que el registro sirva como documento:
 *
 *   - cada venta lleva número correlativo por sede, serie y año;
 *   - el precio de catálogo es PVP con IVA incluido y el desglose cuadra;
 *   - anular NO borra: la venta se queda, deja de sumar y devuelve el stock.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/_arranque.php';   // base de pruebas, nunca la de trabajo

$raiz = dirname(__DIR__);
require_once $raiz . '/app/models/ProductoModel.php';
require_once $raiz . '/app/models/VentaModel.php';
require_once $raiz . '/app/models/GimnasioModel.php';

$db = Database::getInstance()->getConnection();
$ok = 0; $fallos = 0;
function comprobar(string $d, $esperado, $real) {
    global $ok, $fallos;
    if ((string) $esperado === (string) $real) { $ok++; echo "  OK   $d\n"; }
    else { $fallos++; echo "  FALLO $d — esperaba [$esperado], obtuve [$real]\n"; }
}

// --- Estado de partida -------------------------------------------------------
$db->exec("DELETE FROM venta_linea WHERE id_venta IN (SELECT id_venta FROM venta WHERE id_gimnasio = 1)");
$db->exec("DELETE FROM venta WHERE id_gimnasio = 1");
$db->exec("DELETE FROM producto WHERE nombre LIKE 'TEST %'");

$producto = new ProductoModel(1);
$venta    = new VentaModel(1);

// 12,10 € con IVA al 21 % → base 10,00 y cuota 2,10. Números redondos a
// propósito: si el desglose se hiciera al revés, saltaría a la vista.
$producto->crear('TEST Bebida', null, 12.10, 100, 5, 'activo', null, 21.0);
$idProducto = (int) $db->query("SELECT id_producto FROM producto WHERE nombre = 'TEST Bebida'")->fetchColumn();

echo "== NUMERACIÓN ==\n";
$error = '';
$id1 = $venta->registrar([['id_producto' => $idProducto, 'cantidad' => 1]], null, 'efectivo', 1, $error);
comprobar('se registra la venta', true, $id1 !== null);

$v1 = $venta->buscarPorId($id1);
comprobar('empieza por el número 1', 1, $v1['numero']);
comprobar('lleva el ejercicio en curso', (int) date('Y'), $v1['ejercicio']);
comprobar('la referencia se lee', 'A-' . date('Y') . '-000001', VentaModel::referencia($v1));

$id2 = $venta->registrar([['id_producto' => $idProducto, 'cantidad' => 1]], null, 'efectivo', 1, $error);
comprobar('la siguiente venta es la 2', 2, $venta->buscarPorId($id2)['numero']);

// Otra sede lleva su propia numeración: los dos mostradores emiten a la vez.
$gim    = new GimnasioModel();
$idSedeB = $gim->crear(['nombre' => 'TEST Sede facturación', 'razon_social' => '', 'cif' => '',
                        'direccion' => '', 'telefono' => '', 'email' => '']);
$productoB = new ProductoModel($idSedeB);
$productoB->crear('TEST Bebida B', null, 5.00, 10, 1, 'activo', null, 21.0);
$idProductoB = (int) $db->query("SELECT id_producto FROM producto WHERE nombre = 'TEST Bebida B'")->fetchColumn();
$ventaB = new VentaModel($idSedeB);
$idB = $ventaB->registrar([['id_producto' => $idProductoB, 'cantidad' => 1]], null, 'efectivo', 1, $error);
comprobar('la otra sede empieza también por el 1', 1, $ventaB->buscarPorId($idB)['numero']);

echo "\n== DESGLOSE DE IVA ==\n";
comprobar('el total es el PVP',        '12.10', $v1['total']);
comprobar('la base sale hacia atrás',  '10.00', $v1['base_imponible']);
comprobar('la cuota de IVA cuadra',    '2.10',  $v1['total_iva']);
comprobar('base + IVA = total', (string) $v1['total'],
    number_format((float) $v1['base_imponible'] + (float) $v1['total_iva'], 2, '.', ''));

$linea = $venta->listarLineas($id1)[0];
comprobar('la línea guarda el tipo aplicado', '21.00', $linea['iva']);
comprobar('la línea guarda su base',          '10.00', $linea['base_linea']);

echo "\n== ANULAR NO BORRA ==\n";
$stockAntes = (int) $db->query("SELECT stock FROM producto WHERE id_producto = $idProducto")->fetchColumn();
$cajaAntes  = $venta->sumarDelDia();

comprobar('se anula', true, $venta->anular($id1, 1, 'Prueba automática'));

$v1 = $venta->buscarPorId($id1);
comprobar('la venta SIGUE existiendo', true,  $v1 !== null);
comprobar('queda marcada como anulada', 'anulada', $v1['estado']);
comprobar('conserva su número',          1,        $v1['numero']);
comprobar('guarda el motivo', 'Prueba automática', $v1['motivo_anulacion']);
comprobar('guarda quién la anuló',       1,        $v1['id_usuario_anulacion']);

comprobar('devuelve el stock', $stockAntes + 1,
    (int) $db->query("SELECT stock FROM producto WHERE id_producto = $idProducto")->fetchColumn());
comprobar('deja de sumar en la caja del día',
    number_format($cajaAntes - 12.10, 2, '.', ''),
    number_format($venta->sumarDelDia(), 2, '.', ''));
comprobar('tampoco cuenta como venta del día', 1, $venta->contarDelDia());

comprobar('no se puede anular dos veces', false, $venta->anular($id1, 1, 'otra vez'));
comprobar('sigue apareciendo en el listado del día', true,
    in_array($id1, array_column($venta->listarDelDia(), 'id_venta')));

echo "\n== UNA SEDE NO ANULA VENTAS DE OTRA ==\n";
comprobar('la sede B no puede anular la venta de A', false, $ventaB->anular($id2, 1, 'intento'));
comprobar('la venta de A sigue activa', 'activa', $venta->buscarPorId($id2)['estado']);

// --- Limpieza ----------------------------------------------------------------
$db->exec("DELETE FROM venta_linea WHERE id_venta IN (SELECT id_venta FROM venta WHERE id_gimnasio IN (1, $idSedeB))");
$db->exec("DELETE FROM venta    WHERE id_gimnasio IN (1, $idSedeB)");
$db->exec("DELETE FROM producto WHERE nombre LIKE 'TEST %'");
$db->exec("DELETE FROM gimnasio WHERE id_gimnasio = $idSedeB");

echo "\n== RESUMEN: $ok correctas, $fallos fallidas ==\n";
