<?php
/**
 * Periodo de prueba gratuito y exento: no genera deuda ni remesa y caduca solo.
 *
 * Lo importante que se comprueba aquí es que el cierre NO depende de que nadie
 * ejecute nada: al pasar la fecha, el socio deja de tener acceso por el simple
 * hecho de comparar fecha_fin con hoy.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/_arranque.php';   // base de pruebas, nunca la de trabajo

$raiz = dirname(__DIR__);
require_once $raiz . '/app/models/MembresiaModel.php';

$db = Database::getInstance()->getConnection();
$m  = new MembresiaModel(1);

$ok = 0; $fallos = 0;
function comprobar(string $d, $esperado, $real) {
    global $ok, $fallos;
    if ((string) $esperado === (string) $real) { $ok++; echo "  OK   $d\n"; }
    else { $fallos++; echo "  FALLO $d — esperaba [$esperado], obtuve [$real]\n"; }
}

$idSocio = 3;
pruebasLimpiarMembresias($db, "sm.id_socio = $idSocio");

$dias = MembresiaModel::DIAS_PRUEBA;
echo "== APERTURA DE PRUEBA ($dias días) ==\n";

$err = '';
$clavePrueba = 'f20-trial-' . bin2hex(random_bytes(8));
$idPrueba = $m->iniciarPrueba($idSocio, $err, 1, $clavePrueba);
comprobar('la prueba se abre', true, $idPrueba !== null);

$p = $m->pruebaVigenteDeSocio($idSocio);
comprobar('empieza hoy',            date('Y-m-d'), $p['fecha_inicio']);
comprobar('caduca al 5º día',       date('Y-m-d', strtotime('+' . ($dias - 1) . ' days')), $p['fecha_fin']);
comprobar('marcada como prueba',    1,           $p['es_prueba']);
comprobar('compatibilidad la refleja satisfecha', 'pagado', $p['estado_pago']);
comprobar('sin coste',              '0.00',      $p['precio_pagado']);
comprobar('el acceso está abierto', true,        $m->vigenteDeSocio($idSocio) !== null);
$obligacion = $db->query('SELECT * FROM obligacion_pago WHERE id_socio_membresia=' . (int) $idPrueba)->fetch();
comprobar('genera obligación trazable exenta', 'exenta', $obligacion['estado'] ?? '');
comprobar('la obligación no suma deuda', '0.00', $obligacion['importe'] ?? '');
comprobar('no genera cobro', 0, (int) $db->query('SELECT COUNT(*) FROM cobro WHERE id_socio_membresia=' . (int) $idPrueba)->fetchColumn());
comprobar('no es candidata a remesa', 0, (int) $db->query('SELECT COUNT(*) FROM remesa_recibo WHERE id_socio_membresia=' . (int) $idPrueba)->fetchColumn());

$fila = null;
foreach ($m->listarSocios() as $s) { if ((int) $s['id_usuario'] === $idSocio) $fila = $s; }
comprobar('el listado la marca como prueba', 'prueba', $fila['estado_membresia']);
comprobar('aparece entre las pendientes',    1, count($m->listarPruebasPendientes()));
$err2 = '';
$reenvio = $m->iniciarPrueba($idSocio, $err2, 1, $clavePrueba);
comprobar('doble submit devuelve la misma prueba', $idPrueba, $reenvio);
comprobar('doble submit conserva una sola prueba', 1, (int) $db->query('SELECT COUNT(*) FROM socio_membresia WHERE id_socio=' . $idSocio . ' AND es_prueba=1')->fetchColumn());

echo "\n== NO SE PUEDEN ENCADENAR PRUEBAS ==\n";
$err2 = '';
comprobar('no deja abrir otra con acceso vigente', true, $m->iniciarPrueba($idSocio, $err2) === null);
echo "  motivo: $err2\n";

echo "\n== CADUCA SOLA: pasan los $dias días sin que nadie toque nada ==\n";
// Se simula el paso del tiempo retrasando las fechas: es lo mismo que vería el
// sistema mañana, sin ejecutar ningún proceso de cierre.
$db->exec("UPDATE socio_membresia
              SET fecha_inicio = DATE_SUB(fecha_inicio, INTERVAL $dias DAY),
                  fecha_fin    = DATE_SUB(fecha_fin,    INTERVAL $dias DAY)
            WHERE id_socio = $idSocio AND es_prueba = 1");

comprobar('el acceso queda cerrado',        true, $m->vigenteDeSocio($idSocio) === null);
comprobar('ya no consta como prueba viva',  true, $m->pruebaVigenteDeSocio($idSocio) === null);
comprobar('desaparece de las pendientes',   0,    count($m->listarPruebasPendientes()));

$fila = null;
foreach ($m->listarSocios() as $s) { if ((int) $s['id_usuario'] === $idSocio) $fila = $s; }
comprobar('el listado la marca caducada', 'prueba_caducada', $fila['estado_membresia']);

echo "\n== CONVERSIÓN: el trabajador contrata una cuota dentro de plazo ==\n";
pruebasLimpiarMembresias($db, "sm.id_socio = $idSocio");
$err = '';
$m->iniciarPrueba($idSocio, $err);

$tipos = $m->listarTiposActivos();
$mensual = null;
foreach ($tipos as $t) { if ($t['nombre'] === 'Mensual') $mensual = $t; }

$err = '';
$m->contratar($idSocio, (int) $mensual['id_tipo_membresia'], 'efectivo', $err);
$v = $m->vigenteDeSocio($idSocio);

comprobar('la cuota empieza hoy, no tras la prueba', date('Y-m-d'), $v['fecha_inicio']);
comprobar('no es prueba',                            0,   $v['es_prueba']);
comprobar('queda como pagada',                       'pagado', $v['estado_pago']);
comprobar('la prueba deja de estar pendiente',       0,   count($m->listarPruebasPendientes()));

$fila = null;
foreach ($m->listarSocios() as $s) { if ((int) $s['id_usuario'] === $idSocio) $fila = $s; }
comprobar('el socio pasa a activa', 'activa', $fila['estado_membresia']);

pruebasLimpiarMembresias($db, "sm.id_socio = $idSocio");

echo "\n== RESUMEN: $ok correctas, $fallos fallidas ==\n";
exit($fallos === 0 ? 0 : 1);
