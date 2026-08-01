<?php
/**
 * SepaXml — genera el fichero de adeudos directos SEPA (pain.008.001.02, CORE).
 *
 * Es el formato que acepta la banca electrónica española para lanzar recibos.
 * No hace falta ninguna integración con el banco: se descarga este XML y se
 * sube a la banca online del gimnasio.
 *
 * Detalle que importa: los recibos se agrupan en bloques <PmtInf> separados por
 * secuencia (FRST el primer adeudo de un mandato, RCUR los siguientes). Los
 * bancos rechazan la remesa si se mezclan ambos tipos en el mismo bloque.
 *
 * Referencia: EPC — SEPA Core Direct Debit Scheme, norma ISO 20022.
 */

require_once __DIR__ . '/Iban.php';

class SepaXml
{
    /**
     * @param array $acreedor  nombre, iban, bic, identificador_acreedor
     * @param array $remesa    concepto, fecha_cobro (Y-m-d), id_remesa
     * @param array $recibos   filas de remesa_recibo
     */
    public static function generar(array $acreedor, array $remesa, array $recibos): string
    {
        $idMensaje = 'REM' . str_pad((string) ($remesa['id_remesa'] ?? 0), 8, '0', STR_PAD_LEFT)
                   . '-' . date('YmdHis');

        // Un bloque de pago por cada secuencia presente.
        $porSecuencia = ['FRST' => [], 'RCUR' => []];
        foreach ($recibos as $r) {
            $seq = ($r['secuencia'] ?? 'RCUR') === 'FRST' ? 'FRST' : 'RCUR';
            $porSecuencia[$seq][] = $r;
        }

        $totalNum     = count($recibos);
        $totalImporte = 0.0;
        foreach ($recibos as $r) $totalImporte += (float) $r['importe'];

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $documento = $doc->createElementNS('urn:iso:std:iso:20022:tech:xsd:pain.008.001.02', 'Document');
        $doc->appendChild($documento);

        $inicio = $doc->createElement('CstmrDrctDbtInitn');
        $documento->appendChild($inicio);

        // --- Cabecera del mensaje -------------------------------------------
        $cabecera = $doc->createElement('GrpHdr');
        $inicio->appendChild($cabecera);
        $cabecera->appendChild($doc->createElement('MsgId', self::limpiar($idMensaje, 35)));
        $cabecera->appendChild($doc->createElement('CreDtTm', date('Y-m-d\TH:i:s')));
        $cabecera->appendChild($doc->createElement('NbOfTxs', (string) $totalNum));
        $cabecera->appendChild($doc->createElement('CtrlSum', number_format($totalImporte, 2, '.', '')));
        $iniciador = $doc->createElement('InitgPty');
        $cabecera->appendChild($iniciador);
        $iniciador->appendChild(self::texto($doc, 'Nm', self::limpiar($acreedor['nombre'] ?? 'Gimnasio', 70)));

        // --- Un bloque por secuencia ----------------------------------------
        foreach ($porSecuencia as $secuencia => $lista) {
            if (empty($lista)) continue;

            $sumaBloque = 0.0;
            foreach ($lista as $r) $sumaBloque += (float) $r['importe'];

            $bloque = $doc->createElement('PmtInf');
            $inicio->appendChild($bloque);

            $bloque->appendChild($doc->createElement('PmtInfId', self::limpiar($idMensaje . '-' . $secuencia, 35)));
            $bloque->appendChild($doc->createElement('PmtMtd', 'DD'));
            $bloque->appendChild($doc->createElement('NbOfTxs', (string) count($lista)));
            $bloque->appendChild($doc->createElement('CtrlSum', number_format($sumaBloque, 2, '.', '')));

            $tipoPago = $doc->createElement('PmtTpInf');
            $bloque->appendChild($tipoPago);
            $nivelServicio = $doc->createElement('SvcLvl');
            $tipoPago->appendChild($nivelServicio);
            $nivelServicio->appendChild($doc->createElement('Cd', 'SEPA'));
            $instrumento = $doc->createElement('LclInstrm');
            $tipoPago->appendChild($instrumento);
            $instrumento->appendChild($doc->createElement('Cd', 'CORE'));
            $tipoPago->appendChild($doc->createElement('SeqTp', $secuencia));

            $bloque->appendChild($doc->createElement('ReqdColltnDt', $remesa['fecha_cobro']));

            $acreedorNodo = $doc->createElement('Cdtr');
            $bloque->appendChild($acreedorNodo);
            $acreedorNodo->appendChild(self::texto($doc, 'Nm', self::limpiar($acreedor['nombre'] ?? 'Gimnasio', 70)));

            $cuentaAcreedor = $doc->createElement('CdtrAcct');
            $bloque->appendChild($cuentaAcreedor);
            $idCuenta = $doc->createElement('Id');
            $cuentaAcreedor->appendChild($idCuenta);
            $idCuenta->appendChild($doc->createElement('IBAN', Iban::normalizar($acreedor['iban'] ?? '')));

            $bancoAcreedor = $doc->createElement('CdtrAgt');
            $bloque->appendChild($bancoAcreedor);
            $institucion = $doc->createElement('FinInstnId');
            $bancoAcreedor->appendChild($institucion);
            if (!empty($acreedor['bic'])) {
                $institucion->appendChild($doc->createElement('BIC', strtoupper(trim($acreedor['bic']))));
            } else {
                $otro = $doc->createElement('Othr');
                $institucion->appendChild($otro);
                $otro->appendChild($doc->createElement('Id', 'NOTPROVIDED'));
            }

            $bloque->appendChild($doc->createElement('ChrgBr', 'SLEV'));

            // Identificador de acreedor SEPA.
            $esquema = $doc->createElement('CdtrSchmeId');
            $bloque->appendChild($esquema);
            $idEsquema = $doc->createElement('Id');
            $esquema->appendChild($idEsquema);
            $privado = $doc->createElement('PrvtId');
            $idEsquema->appendChild($privado);
            $otroId = $doc->createElement('Othr');
            $privado->appendChild($otroId);
            $otroId->appendChild($doc->createElement('Id', self::limpiar($acreedor['identificador_acreedor'] ?? '', 35)));
            $nombreEsquema = $doc->createElement('SchmeNm');
            $otroId->appendChild($nombreEsquema);
            $nombreEsquema->appendChild($doc->createElement('Prtry', 'SEPA'));

            // --- Un nodo por recibo ------------------------------------------
            foreach ($lista as $r) {
                $operacion = $doc->createElement('DrctDbtTxInf');
                $bloque->appendChild($operacion);

                $idPago = $doc->createElement('PmtId');
                $operacion->appendChild($idPago);
                $idPago->appendChild($doc->createElement(
                    'EndToEndId',
                    self::limpiar('REC' . ($r['id_recibo'] ?? '0'), 35)
                ));

                $importe = $doc->createElement('InstdAmt', number_format((float) $r['importe'], 2, '.', ''));
                $importe->setAttribute('Ccy', 'EUR');
                $operacion->appendChild($importe);

                $adeudo = $doc->createElement('DrctDbtTx');
                $operacion->appendChild($adeudo);
                $infoMandato = $doc->createElement('MndtRltdInf');
                $adeudo->appendChild($infoMandato);
                $infoMandato->appendChild($doc->createElement('MndtId', self::limpiar($r['referencia_mandato'], 35)));
                $infoMandato->appendChild($doc->createElement('DtOfSgntr', $r['fecha_firma_mandato']));
                $infoMandato->appendChild($doc->createElement('AmdmntInd', 'false'));

                // El banco del deudor se deduce del IBAN: no hace falta el BIC.
                $bancoDeudor = $doc->createElement('DbtrAgt');
                $operacion->appendChild($bancoDeudor);
                $institucionDeudor = $doc->createElement('FinInstnId');
                $bancoDeudor->appendChild($institucionDeudor);
                $otroDeudor = $doc->createElement('Othr');
                $institucionDeudor->appendChild($otroDeudor);
                $otroDeudor->appendChild($doc->createElement('Id', 'NOTPROVIDED'));

                $deudor = $doc->createElement('Dbtr');
                $operacion->appendChild($deudor);
                $deudor->appendChild(self::texto($doc, 'Nm', self::limpiar($r['nombre_socio'], 70)));

                $cuentaDeudor = $doc->createElement('DbtrAcct');
                $operacion->appendChild($cuentaDeudor);
                $idCuentaDeudor = $doc->createElement('Id');
                $cuentaDeudor->appendChild($idCuentaDeudor);
                $idCuentaDeudor->appendChild($doc->createElement('IBAN', Iban::normalizar($r['iban'])));

                $concepto = $doc->createElement('RmtInf');
                $operacion->appendChild($concepto);
                $concepto->appendChild(self::texto($doc, 'Ustrd', self::limpiar($r['concepto'], 140)));
            }
        }

        return $doc->saveXML();
    }

    /** Nodo de texto creado aparte para que DOM escape el contenido. */
    private static function texto(DOMDocument $doc, string $etiqueta, string $valor): DOMElement
    {
        $nodo = $doc->createElement($etiqueta);
        $nodo->appendChild($doc->createTextNode($valor));
        return $nodo;
    }

    /**
     * El juego de caracteres SEPA no admite acentos ni ñ: se transliteran para
     * que el banco no rechace el fichero por un nombre con tilde.
     */
    private static function limpiar(string $texto, int $maximo): string
    {
        $texto = strtr($texto, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N','Ç'=>'C',
            'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u','º'=>'.','ª'=>'.',
        ]);
        $texto = preg_replace('/[^A-Za-z0-9\/\-?:().,\'+ ]/', ' ', $texto);
        $texto = trim(preg_replace('/\s+/', ' ', $texto));
        return mb_substr($texto, 0, $maximo);
    }
}
