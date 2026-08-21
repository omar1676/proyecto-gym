<?php
/**
 * Domiciliación SEPA: mandatos, remesa, fichero XML y devoluciones.
 *
 * Lo que más importa aquí es que el XML salga conforme a pain.008.001.02: si
 * un campo falta o los totales no cuadran, el banco rechaza la remesa entera
 * y no hay forma de saberlo hasta que la subes.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/_arranque.php';   // base de pruebas, nunca la de trabajo

$raiz = dirname(__DIR__);
require_once $raiz . '/app/models/SepaModel.php';
require_once $raiz . '/app/models/MembresiaModel.php';
require_once $raiz . '/app/models/UserModel.php';
require_once $raiz . '/app/helpers/SepaXml.php';

$db = Database::getInstance()->getConnection();
$ok = 0; $fallos = 0;
function comprobar(string $d, $esperado, $real) {
    global $ok, $fallos;
    if ((string) $esperado === (string) $real) { $ok++; echo "  OK   $d\n"; }
    else { $fallos++; echo "  FALLO $d — esperaba [$esperado], obtuve [$real]\n"; }
}

$sede    = 1;
$idSocio = 3;
$sepa    = new SepaModel($sede);
$memb    = new MembresiaModel($sede);
$user    = new UserModel($sede);

// --- Estado de partida limpio ------------------------------------------------
pruebasLimpiarRemesas($db, "r.concepto LIKE 'TEST %'");
$db->exec("DELETE FROM mandato_sepa WHERE id_socio = $idSocio");
pruebasLimpiarMembresias($db, "sm.id_socio = $idSocio");

echo "== DATOS DEL ACREEDOR ==\n";
$sepa->guardarAcreedor($sede, [
    'razon_social'           => 'Gimnasio de Pruebas SL',
    'cif'                    => 'B12345678',
    'iban'                   => 'ES9121000418450200051332',
    'bic'                    => 'CAIXESBBXXX',
    'identificador_acreedor' => 'ES00ZZZB12345678',
]);
comprobar('los datos quedan completos', true, $sepa->acreedorCompleto());

echo "\n== MANDATO ==\n";
$err = '';
comprobar('rechaza un IBAN inválido', true,
    $sepa->crearMandato($idSocio, 'ES0000000000000000000000', date('Y-m-d'), $err) === null);

$err = '';
$idMandato = $sepa->crearMandato($idSocio, 'ES9121000418450200051332', '2026-01-15', $err);
comprobar('se firma el mandato', true, $idMandato !== null);

$mandato = $sepa->mandatoActivo($idSocio);
comprobar('queda activo',            'activo', $mandato['estado']);
comprobar('sin primer cobro aún',    0,        $mandato['primer_cobro_hecho']);
comprobar('genera referencia única', true,     !empty($mandato['referencia']));

// Firmar otro revoca el anterior: es lo que pasa al cambiar de banco.
$err = '';
$sepa->crearMandato($idSocio, 'DE89370400440532013000', date('Y-m-d'), $err);
comprobar('el mandato nuevo revoca al anterior', 1,
    (int) $db->query("SELECT COUNT(*) FROM mandato_sepa WHERE id_socio = $idSocio AND estado = 'activo'")->fetchColumn());

echo "\n== COBRO PENDIENTE DE DOMICILIAR ==\n";
$tipos = $memb->listarTiposActivos();
$mensual = null;
foreach ($tipos as $t) { if ($t['nombre'] === 'Mensual') $mensual = $t; }

$err = '';
$memb->contratar($idSocio, (int) $mensual['id_tipo_membresia'], 'transferencia', $err);
$pendientes = $sepa->listarDomiciliablesPendientes();
$mio = null;
foreach ($pendientes as $p) { if ((int) $p['id_socio'] === $idSocio) $mio = $p; }
comprobar('la cuota aparece como domiciliable', true, $mio !== null);
comprobar('con el importe correcto', '40.00', number_format((float) $mio['importe'], 2, '.', ''));

// Una cuota en efectivo no debe entrar en la remesa.
pruebasLimpiarMembresias($db, "sm.id_socio = $idSocio");
$err = '';
$memb->contratar($idSocio, (int) $mensual['id_tipo_membresia'], 'efectivo', $err);
$hayEfectivo = false;
foreach ($sepa->listarDomiciliablesPendientes() as $p) { if ((int) $p['id_socio'] === $idSocio) $hayEfectivo = true; }
comprobar('lo pagado en efectivo no se domicilia', false, $hayEfectivo);

// Volvemos a dejar una cuota por transferencia para la remesa.
pruebasLimpiarMembresias($db, "sm.id_socio = $idSocio");
$err = '';
$memb->contratar($idSocio, (int) $mensual['id_tipo_membresia'], 'transferencia', $err);
$mio = null;
foreach ($sepa->listarDomiciliablesPendientes() as $p) { if ((int) $p['id_socio'] === $idSocio) $mio = $p; }

echo "\n== REMESA ==\n";
$err = '';
$idRemesa = $sepa->crearRemesa([(int) $mio['id_socio_membresia']], 'TEST Cuota 08/2026', '2026-08-01', 1, $err);
comprobar('la remesa se crea', true, $idRemesa !== null);

$remesa = $sepa->buscarRemesa($idRemesa);
comprobar('un recibo',            1,       $remesa['num_recibos']);
comprobar('importe total',        '40.00', $remesa['importe_total']);
comprobar('empieza en borrador',  'borrador', $remesa['estado']);

$recibos = $sepa->listarRecibos($idRemesa);
comprobar('el primer adeudo va como FRST', 'FRST', $recibos[0]['secuencia']);

// El mismo cobro no puede colarse dos veces en otra remesa.
$hayDuplicado = false;
foreach ($sepa->listarDomiciliablesPendientes() as $p) {
    if ((int) $p['id_socio_membresia'] === (int) $mio['id_socio_membresia']) $hayDuplicado = true;
}
comprobar('un cobro ya remesado no se repite', false, $hayDuplicado);

echo "\n== FICHERO XML (pain.008.001.02) ==\n";
$acreedor = $sepa->acreedor();
$xml = SepaXml::generar(
    [
        'nombre'                 => $acreedor['razon_social'],
        'iban'                   => $acreedor['iban'],
        'bic'                    => $acreedor['bic'],
        'identificador_acreedor' => $acreedor['identificador_acreedor'],
    ],
    $remesa,
    $recibos
);

$doc = new DOMDocument();
comprobar('el XML es válido', true, $doc->loadXML($xml) !== false);

$xp = new DOMXPath($doc);
$xp->registerNamespace('s', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02');

comprobar('espacio de nombres correcto', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02',
    $doc->documentElement->getAttribute('xmlns'));
comprobar('número de operaciones',  '1',     $xp->query('//s:GrpHdr/s:NbOfTxs')->item(0)->nodeValue);
comprobar('suma de control',        '40.00', $xp->query('//s:GrpHdr/s:CtrlSum')->item(0)->nodeValue);
comprobar('método de pago DD',      'DD',    $xp->query('//s:PmtInf/s:PmtMtd')->item(0)->nodeValue);
comprobar('esquema CORE',           'CORE',  $xp->query('//s:LclInstrm/s:Cd')->item(0)->nodeValue);
comprobar('secuencia FRST',         'FRST',  $xp->query('//s:SeqTp')->item(0)->nodeValue);
comprobar('fecha de cobro',         '2026-08-01', $xp->query('//s:ReqdColltnDt')->item(0)->nodeValue);
comprobar('IBAN del acreedor',      'ES9121000418450200051332', $xp->query('//s:CdtrAcct/s:Id/s:IBAN')->item(0)->nodeValue);
comprobar('identificador acreedor', 'ES00ZZZB12345678', $xp->query('//s:CdtrSchmeId//s:Othr/s:Id')->item(0)->nodeValue);
comprobar('importe con divisa',     'EUR',   $xp->query('//s:InstdAmt')->item(0)->getAttribute('Ccy'));
comprobar('referencia del mandato', $recibos[0]['referencia_mandato'], $xp->query('//s:MndtId')->item(0)->nodeValue);
comprobar('fecha de firma',         $recibos[0]['fecha_firma_mandato'], $xp->query('//s:DtOfSgntr')->item(0)->nodeValue);
comprobar('IBAN del deudor',        $recibos[0]['iban'], $xp->query('//s:DbtrAcct/s:Id/s:IBAN')->item(0)->nodeValue);

echo "\n== ENVÍO Y COBRO ==\n";
$sepa->marcarEnviada($idRemesa);
comprobar('la remesa queda enviada', 'enviada', $sepa->buscarRemesa($idRemesa)['estado']);
comprobar('el mandato pasa a recurrente', 1, $sepa->mandatoActivo($idSocio)['primer_cobro_hecho']);

$sepa->marcarCobrada($idRemesa);
comprobar('la remesa queda cobrada', 'cobrada', $sepa->buscarRemesa($idRemesa)['estado']);
comprobar('sus recibos quedan cobrados', 'cobrado', $sepa->listarRecibos($idRemesa)[0]['estado']);

echo "\n== DEVOLUCIÓN ==\n";
$idRecibo = (int) $sepa->listarRecibos($idRemesa)[0]['id_recibo'];
$sepa->marcarDevuelto($idRecibo, 'Cuenta sin fondos');
comprobar('el recibo consta devuelto', 'devuelto', $sepa->listarRecibos($idRemesa)[0]['estado']);
comprobar('guarda el motivo', 'Cuenta sin fondos', $sepa->listarRecibos($idRemesa)[0]['motivo_devolucion']);

$vuelve = false;
foreach ($sepa->listarDomiciliablesPendientes() as $p) {
    if ((int) $p['id_socio'] === $idSocio) $vuelve = true;
}
comprobar('vuelve a estar pendiente de cobro', true, $vuelve);

// --- Limpieza ----------------------------------------------------------------
pruebasLimpiarRemesas($db, "r.concepto LIKE 'TEST %'");
$db->exec("DELETE FROM mandato_sepa WHERE id_socio = $idSocio");
pruebasLimpiarMembresias($db, "sm.id_socio = $idSocio");
$db->exec("UPDATE gimnasio SET razon_social = NULL, cif = NULL, iban = NULL, bic = NULL, identificador_acreedor = NULL WHERE id_gimnasio = $sede");

echo "\n== RESUMEN: $ok correctas, $fallos fallidas ==\n";
exit($fallos === 0 ? 0 : 1);
