<?php
/**
 * Prueba de la lógica de negocio: ventas con descuento de stock,
 * control de stock insuficiente y contratación/renovación de membresías.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/_arranque.php';   // base de pruebas, nunca la de trabajo

$raiz = dirname(__DIR__);
require_once $raiz . '/app/models/ProductoModel.php';
require_once $raiz . '/app/models/VentaModel.php';
require_once $raiz . '/app/models/MembresiaModel.php';

$productos = new ProductoModel();
$ventas    = new VentaModel();
$membres   = new MembresiaModel();

$ok = 0; $fallos = 0;
function comprobar(string $desc, $esperado, $real) {
    global $ok, $fallos;
    if ($esperado == $real) { $ok++;   echo "  OK   $desc\n"; }
    else { $fallos++; echo "  FALLO $desc — esperaba [$esperado], obtuve [$real]\n"; }
}

echo "== VENTA NORMAL ==\n";
$antes1 = (int) $productos->buscarPorId(1)['stock'];
$antes3 = (int) $productos->buscarPorId(3)['stock'];
echo "  stock inicial: agua=$antes1, proteina=$antes3\n";

$error = '';
$idVenta = $ventas->registrar(
    [['id_producto' => 1, 'cantidad' => 2], ['id_producto' => 3, 'cantidad' => 1]],
    null, 'efectivo', 1, $error
);
comprobar('la venta se registra', true, $idVenta !== null);
if ($idVenta === null) { echo "  motivo: $error\n"; }

comprobar('stock agua descontado en 2',      $antes1 - 2, (int) $productos->buscarPorId(1)['stock']);
comprobar('stock proteina descontado en 1',  $antes3 - 1, (int) $productos->buscarPorId(3)['stock']);

$venta = $ventas->buscarPorId($idVenta);
comprobar('total calculado (2x1.00 + 24.90)', '26.90', $venta['total']);
comprobar('lineas guardadas', 2, count($ventas->listarLineas($idVenta)));

echo "\n== STOCK INSUFICIENTE (debe rechazar sin tocar nada) ==\n";
$antesA = (int) $productos->buscarPorId(1)['stock'];
$antesB = (int) $productos->buscarPorId(4)['stock'];
$error2 = '';
$idMala = $ventas->registrar(
    [['id_producto' => 1, 'cantidad' => 1], ['id_producto' => 4, 'cantidad' => 9999]],
    null, 'datafono', 1, $error2
);
comprobar('la venta se rechaza', true, $idMala === null);
comprobar('mensaje de error presente', true, $error2 !== '');
echo "  mensaje: $error2\n";
comprobar('rollback: stock agua intacto',    $antesA, (int) $productos->buscarPorId(1)['stock']);
comprobar('rollback: stock barrita intacto', $antesB, (int) $productos->buscarPorId(4)['stock']);

echo "\n== ANULACION (devuelve stock) ==\n";
$antesAnular = (int) $productos->buscarPorId(1)['stock'];
comprobar('venta anulada', true, $ventas->anular($idVenta));
comprobar('stock devuelto', $antesAnular + 2, (int) $productos->buscarPorId(1)['stock']);

echo "\n== MEMBRESIAS ==\n";
// Parte de cero: las renovaciones encadenan, así que sin limpiar las
// contrataciones previas las fechas esperadas se desplazarían en cada pasada.
Database::getInstance()->getConnection()->exec("DELETE FROM socio_membresia WHERE id_socio = 3");
$err = '';
$idM = $membres->contratar(3, 1, 'efectivo', $err);   // socio 3, tipo Mensual
comprobar('membresia contratada', true, $idM !== null);
if ($idM === null) echo "  motivo: $err\n";

$vig = $membres->vigenteDeSocio(3);
$finEsperado = date('Y-m-d', strtotime(date('Y-m-d') . ' +1 month -1 day'));
comprobar('fecha_inicio es hoy',  date('Y-m-d'), $vig['fecha_inicio']);
comprobar('fecha_fin a 1 mes',    $finEsperado,  $vig['fecha_fin']);
comprobar('precio congelado (cuota base)', '40.00', $vig['precio_pagado']);

$err2 = '';
$membres->contratar(3, 2, 'datafono', $err2);         // renueva con Trimestral
$vig2 = $membres->vigenteDeSocio(3);
$inicioEncadenado = date('Y-m-d', strtotime($finEsperado . ' +1 day'));
comprobar('renovacion encadena tras el vencimiento', $inicioEncadenado, $vig2['fecha_inicio']);

comprobar('socio cuenta como activo', 1, $membres->contarActivas());
$socios = $membres->listarSocios();
$estado = '';
foreach ($socios as $s) { if ((int) $s['id_usuario'] === 3) $estado = $s['estado_membresia']; }
comprobar('estado calculado = activa', 'activa', $estado);

echo "\n== RESUMEN: $ok correctas, $fallos fallidas ==\n";
