<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/_arranque.php';   // base de pruebas, nunca la de trabajo

$raiz = dirname(__DIR__);
require_once $raiz . '/app/models/MembresiaModel.php';
$m = new MembresiaModel();

$ok = 0; $fallos = 0;
function comprobar(string $d, $esperado, $real) {
    global $ok, $fallos;
    if ((string) $esperado === (string) $real) { $ok++; echo "  OK   $d\n"; }
    else { $fallos++; echo "  FALLO $d — esperaba [$esperado], obtuve [$real]\n"; }
}

// Limpia las membresías del socio de pruebas para partir de cero.
$db = Database::getInstance()->getConnection();
pruebasLimpiarMembresias($db, 'sm.id_socio = 3');

$tipos = $m->listarTiposActivos();
$sups  = $m->listarSuplementosActivos();
$mensual = null; $trimestral = null;
foreach ($tipos as $t) {
    if ($t['nombre'] === 'Mensual')    $mensual    = $t;
    if ($t['nombre'] === 'Trimestral') $trimestral = $t;
}
$plus = $sups[0] ?? null;

echo "== CATALOGO ==\n";
comprobar('cuota base mensual = 40 €', '40.00', $mensual['precio']);
comprobar('suplemento = 25 €/mes',     '25.00', $plus['precio_mensual']);

echo "\n== SOLO CUOTA BASE ==\n";
$err = '';
$m->contratar(3, (int) $mensual['id_tipo_membresia'], 'efectivo', $err);
$v = $m->vigenteDeSocio(3);
comprobar('base cobrada',       '40.00', $v['precio_pagado']);
comprobar('sin suplemento',     '0.00',  $v['precio_suplemento']);
comprobar('nombre suplemento vacio', '', (string) $v['nombre_suplemento']);

echo "\n== MENSUAL + ARTES MARCIALES ==\n";
pruebasLimpiarMembresias($db, 'sm.id_socio = 3');
$err = '';
$m->contratar(3, (int) $mensual['id_tipo_membresia'], 'datafono', $err, (int) $plus['id_suplemento']);
$v = $m->vigenteDeSocio(3);
comprobar('base',                '40.00', $v['precio_pagado']);
comprobar('plus 25 x 1 mes',     '25.00', $v['precio_suplemento']);
comprobar('TOTAL 65 €',          '65.00', number_format((float) $v['precio_pagado'] + (float) $v['precio_suplemento'], 2, '.', ''));
comprobar('nombre del plus congelado', $plus['nombre'], $v['nombre_suplemento']);

echo "\n== TRIMESTRAL + PLUS (el plus se multiplica por los meses) ==\n";
pruebasLimpiarMembresias($db, 'sm.id_socio = 3');
$err = '';
$m->contratar(3, (int) $trimestral['id_tipo_membresia'], 'efectivo', $err, (int) $plus['id_suplemento']);
$v = $m->vigenteDeSocio(3);
comprobar('base trimestral',     '95.00',  $v['precio_pagado']);
comprobar('plus 25 x 3 meses',   '75.00',  $v['precio_suplemento']);
comprobar('TOTAL 170 €',         '170.00', number_format((float) $v['precio_pagado'] + (float) $v['precio_suplemento'], 2, '.', ''));

echo "\n== LISTADO Y REPORTES ==\n";
$socios = $m->listarSocios();
$fila = null;
foreach ($socios as $s) { if ((int) $s['id_usuario'] === 3) $fila = $s; }
comprobar('el listado muestra el plus', $plus['nombre'], $fila['nombre_suplemento']);
comprobar('ingresos del mes incluyen el plus', true, $m->sumarIngresosDelMes() >= 170.00);

// Deja el socio de pruebas limpio.
pruebasLimpiarMembresias($db, 'sm.id_socio = 3');

echo "\n== RESUMEN: $ok correctas, $fallos fallidas ==\n";
