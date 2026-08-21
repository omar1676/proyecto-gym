<?php
/**
 * Renovación automática de cuotas: lo que hace `cron/tareas.php renovar`.
 *
 * Es la pieza de la que vive el negocio, así que lo que se comprueba aquí es
 * sobre todo lo que NO debe pasar: cobrar dos veces, renovar al que paga en
 * efectivo, o seguir cobrando a quien se dio de baja.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/_arranque.php';   // base de pruebas, nunca la de trabajo

$raiz = dirname(__DIR__);
require_once $raiz . '/app/models/MembresiaModel.php';
require_once $raiz . '/app/models/UserModel.php';

$db = Database::getInstance()->getConnection();
$ok = 0; $fallos = 0;
function comprobar(string $d, $esperado, $real) {
    global $ok, $fallos;
    if ((string) $esperado === (string) $real) { $ok++; echo "  OK   $d\n"; }
    else { $fallos++; echo "  FALLO $d — esperaba [$esperado], obtuve [$real]\n"; }
}

// --- Estado de partida -------------------------------------------------------
pruebasLimpiarMembresias($db, "sm.id_socio IN (SELECT id_usuario FROM usuario WHERE nombre_usuario LIKE 'test_%')");
$db->exec("DELETE FROM usuario WHERE nombre_usuario LIKE 'test_%'");

$membresias = new MembresiaModel(1);
$usuarios   = new UserModel(1);

$idTipo = (int) $db->query("SELECT id_tipo_membresia FROM tipo_membresia WHERE estado = 'activo' LIMIT 1")->fetchColumn();

/** Da de alta un socio con una cuota que vence en $diasHastaVencer días. */
function socioConCuota(PDO $db, UserModel $usuarios, string $usuario, string $metodo, int $diasHastaVencer, int $idTipo): int {
    $usuarios->crear('Test', ucfirst($usuario), strtoupper($usuario) . '1T', null,
        $usuario . '@test.es', $usuario, 'clave12345');
    $id = (int) $db->query("SELECT id_usuario FROM usuario WHERE nombre_usuario = " . $db->quote($usuario))->fetchColumn();
    if ($metodo === 'transferencia') {
        $db->prepare('UPDATE usuario SET iban = :iban WHERE id_usuario = :id')
            ->execute([':iban' => 'ES9121000418450200051332', ':id' => $id]);
    }

    $db->prepare(
        "INSERT INTO socio_membresia
         (id_socio, id_gimnasio, id_tipo_membresia, nombre_tipo, precio_pagado, precio_suplemento,
          metodo_pago, fecha_inicio, fecha_fin, renovar_auto)
         VALUES (:s, 1, :t, 'Cuota de prueba', 30.00, 0.00, :m,
                 DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_ADD(CURDATE(), INTERVAL :d DAY), :auto)"
    )->execute([
        ':s' => $id, ':t' => $idTipo, ':m' => $metodo,
        ':d' => $diasHastaVencer, ':auto' => $metodo === 'transferencia' ? 1 : 0,
    ]);
    return $id;
}

echo "== A QUIÉN LE TOCA RENOVAR ==\n";
$idDomiciliado = socioConCuota($db, $usuarios, 'test_domi',     'transferencia', 2,  $idTipo);
$idEfectivo    = socioConCuota($db, $usuarios, 'test_efectivo', 'efectivo',      2,  $idTipo);
$idLejano      = socioConCuota($db, $usuarios, 'test_lejano',   'transferencia', 20, $idTipo);

$aRenovar = array_column($membresias->listarParaRenovar(3), 'id_socio');
comprobar('entra el domiciliado que vence en 2 días', true,  in_array($idDomiciliado, $aRenovar));
comprobar('NO entra el que paga en efectivo',         false, in_array($idEfectivo, $aRenovar));
comprobar('NO entra el que vence dentro de 20 días',  false, in_array($idLejano, $aRenovar));

echo "\n== LA RENOVACIÓN ENCADENA SIN REGALAR DÍAS ==\n";
$antes  = $membresias->vigenteDeSocio($idDomiciliado);
$error  = '';
$claveRenovacion = 'auto-renovacion:' . (int) $antes['id_socio_membresia'];
$idRenovacion = $membresias->contratar(
    $idDomiciliado,
    $idTipo,
    'transferencia',
    $error,
    null,
    'automatica',
    $claveRenovacion
);
$despues = $membresias->vigenteDeSocio($idDomiciliado);

comprobar('la cuota nueva empieza al día siguiente del vencimiento',
    date('Y-m-d', strtotime($antes['fecha_fin'] . ' +1 day')), $despues['fecha_inicio']);
comprobar('queda marcada como automática', 'automatica', $despues['origen']);
comprobar('sigue domiciliada para la próxima vez', 1, $despues['renovar_auto']);

$reenvioRenovacion = $membresias->contratar(
    $idDomiciliado,
    $idTipo,
    'transferencia',
    $error,
    null,
    'automatica',
    $claveRenovacion
);
comprobar('reintentar la misma renovación devuelve la misma operación', $idRenovacion, $reenvioRenovacion);

echo "\n== NO SE COBRA DOS VECES ==\n";
$aRenovar = array_column($membresias->listarParaRenovar(3), 'id_socio');
comprobar('ya renovado, deja de salir en la lista', false, in_array($idDomiciliado, $aRenovar));

// Lo mismo si la renovación la hizo una persona en el mostrador.
$idManual = socioConCuota($db, $usuarios, 'test_manual', 'transferencia', 2, $idTipo);
$membresias->contratar($idManual, $idTipo, 'transferencia', $error);   // renovación de mostrador
comprobar('renovado a mano, el cron no lo vuelve a renovar', false,
    in_array($idManual, array_column($membresias->listarParaRenovar(3), 'id_socio')));

echo "\n== BAJAS Y RENUNCIAS ==\n";
$idBaja = socioConCuota($db, $usuarios, 'test_baja', 'transferencia', 2, $idTipo);
$db->exec("UPDATE usuario SET activo = 0 WHERE id_usuario = $idBaja");
comprobar('al socio bloqueado no se le cobra', false,
    in_array($idBaja, array_column($membresias->listarParaRenovar(3), 'id_socio')));

$idRenuncia  = socioConCuota($db, $usuarios, 'test_renuncia', 'transferencia', 2, $idTipo);
$contrato    = $membresias->vigenteDeSocio($idRenuncia);
comprobar('el socio puede pedir que dejen de renovarle', true,
    $membresias->desactivarRenovacion((int) $contrato['id_socio_membresia']));
comprobar('y deja de salir en la lista', false,
    in_array($idRenuncia, array_column($membresias->listarParaRenovar(3), 'id_socio')));

echo "\n== AISLAMIENTO ENTRE SEDES ==\n";
$membresiasB = new MembresiaModel(999);
comprobar('otra sede no ve estas renovaciones', 0, count($membresiasB->listarParaRenovar(3)));

// --- Limpieza ----------------------------------------------------------------
pruebasLimpiarMembresias($db, "sm.id_socio IN (SELECT id_usuario FROM usuario WHERE nombre_usuario LIKE 'test_%')");
$db->exec("DELETE FROM usuario WHERE nombre_usuario LIKE 'test_%'");

echo "\n== RESUMEN: $ok correctas, $fallos fallidas ==\n";
exit($fallos === 0 ? 0 : 1);
