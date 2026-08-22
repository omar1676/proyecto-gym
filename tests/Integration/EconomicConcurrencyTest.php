<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/models/MembresiaModel.php';
require_once dirname(__DIR__, 2) . '/app/models/SepaModel.php';

$db = Database::getInstance()->getConnection();
$socio = 3;
$worker = dirname(__DIR__) . '/Support/economic_concurrency_worker.php';

function f20Concurrent(string $operation, int $resourceId, array $keys): array {
    global $worker;
    $barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'f20_economic_' . bin2hex(random_bytes(8));
    $processes = [];
    foreach ($keys as $i => $key) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, $worker, $operation, $barrier, (string) $resourceId, $key, (string) $i], [1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__, 2));
        if (is_resource($process)) $processes[] = [$process, $pipes];
    }
    touch($barrier);
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $results[] = ['exit'=>proc_close($process), 'data'=>json_decode($stdout, true), 'stderr'=>$stderr];
    }
    @unlink($barrier);
    return $results;
}

pruebasLimpiarRemesas($db, "r.concepto LIKE 'F20 %'");
$db->exec("DELETE FROM mandato_sepa WHERE id_socio=$socio");
pruebasLimpiarMembresias($db, "sm.id_socio=$socio");

$trial = f20Concurrent('trial', $socio, ['f20-trial-race-a', 'f20-trial-race-b']);
check('dos procesos de trial terminan controladamente', count($trial) === 2 && count(array_filter($trial, fn($r)=>$r['exit']===0)) === 2);
check('solo un trial concurrente gana', count(array_filter($trial, fn($r)=>!empty($r['data']['success']))) === 1);
check('queda un único trial y una obligación exenta',
    (int)$db->query("SELECT COUNT(*) FROM socio_membresia WHERE id_socio=$socio AND es_prueba=1")->fetchColumn() === 1
    && (int)$db->query("SELECT COUNT(*) FROM obligacion_pago o JOIN socio_membresia sm ON sm.id_socio_membresia=o.id_socio_membresia WHERE sm.id_socio=$socio AND o.estado='exenta'")->fetchColumn() === 1);
pruebasLimpiarMembresias($db, "sm.id_socio=$socio");

$mandates = f20Concurrent('mandate', $socio, ['f20-mandate-race-a', 'f20-mandate-race-b']);
check('dos firmas concurrentes se serializan sin error interno', count($mandates) === 2 && count(array_filter($mandates, fn($r)=>$r['exit']===0 && !empty($r['data']['success']))) === 2);
check('solo queda un mandato activo compatible', (int)$db->query("SELECT COUNT(*) FROM mandato_sepa WHERE id_socio=$socio AND estado='activo'")->fetchColumn() === 1);
$same = f20Concurrent('mandate', $socio, ['f20-mandate-same-key', 'f20-mandate-same-key']);
$sameIds = array_values(array_unique(array_map(fn($r)=>(int)($r['data']['id'] ?? 0), $same)));
check('doble submit de mandato devuelve el mismo mandato', count($sameIds) === 1 && $sameIds[0] > 0);
check('doble submit de mandato conserva un solo activo', (int)$db->query("SELECT COUNT(*) FROM mandato_sepa WHERE id_socio=$socio AND estado='activo'")->fetchColumn() === 1);
$constraintMandate = false;
try {
    $db->exec("INSERT INTO mandato_sepa (id_socio,id_gimnasio,referencia,iban,fecha_firma,tipo,estado) VALUES ($socio,1,'F20-DIRECT-DUP','ES9121000418450200051332','2026-08-21','recurrente','activo')");
} catch (PDOException $e) { $constraintMandate = $e->getCode() === '23000'; }
check('DB impide dos mandatos activos aunque se omita el modelo', $constraintMandate);

$tipo = (int)$db->query("SELECT id_tipo_membresia FROM tipo_membresia WHERE id_empresa=1 AND estado='activo' ORDER BY id_tipo_membresia LIMIT 1")->fetchColumn();
$error = '';
$membership = (new MembresiaModel(1, 1))->contratar($socio, $tipo, 'transferencia', $error, null, 'mostrador', 'f20-remittance-membership', 1);
$remittances = f20Concurrent('remittance', (int)$membership, ['f20-remittance-race-a', 'f20-remittance-race-b']);
check('dos procesos de remesa terminan controladamente', count($remittances) === 2 && count(array_filter($remittances, fn($r)=>$r['exit']===0)) === 2);
check('solo una remesa concurrente reclama la obligación', count(array_filter($remittances, fn($r)=>!empty($r['data']['success']))) === 1);
check('existe un solo recibo activo para la membresía', (int)$db->query('SELECT COUNT(*) FROM remesa_recibo WHERE id_socio_membresia='.(int)$membership." AND estado<>'devuelto'")->fetchColumn() === 1);
check('no queda remesa vacía por la carrera', (int)$db->query("SELECT COUNT(*) FROM remesa WHERE concepto='F20 concurrente' AND num_recibos=0")->fetchColumn() === 0);
$firstReceipt = (int)$db->query('SELECT id_recibo FROM remesa_recibo WHERE id_socio_membresia='.(int)$membership.' LIMIT 1')->fetchColumn();
$sepa = new SepaModel(1, 1);
check('devolución libera el claim para una nueva presentación', $sepa->marcarDevuelto($firstReceipt, 'F20 reintento sintético', 1));
$sameRemittance = f20Concurrent('remittance', (int)$membership, ['f20-remittance-same-key', 'f20-remittance-same-key']);
$sameRemittanceIds = array_values(array_unique(array_map(fn($r)=>(int)($r['data']['id'] ?? 0), $sameRemittance)));
$sameRemittanceOk = count($sameRemittanceIds) === 1 && $sameRemittanceIds[0] > 0;
if (!$sameRemittanceOk) fwrite(STDERR, 'diagnóstico ids remesa idempotente: ' . json_encode($sameRemittanceIds) . "\n");
check('doble submit de remesa devuelve la misma remesa', $sameRemittanceOk);
check('doble submit de remesa crea un solo recibo nuevo', (int)$db->query('SELECT COUNT(*) FROM remesa_recibo WHERE id_socio_membresia='.(int)$membership." AND estado='pendiente'")->fetchColumn() === 1);
$constraintReceipt = false;
try {
    $db->exec("INSERT INTO remesa_recibo (id_remesa,id_socio,id_socio_membresia,nombre_socio,referencia_mandato,fecha_firma_mandato,iban,importe,concepto,secuencia,estado)
               SELECT id_remesa,id_socio,id_socio_membresia,nombre_socio,referencia_mandato,fecha_firma_mandato,iban,importe,CONCAT(concepto,' dup'),secuencia,'pendiente'
               FROM remesa_recibo WHERE id_socio_membresia=".(int)$membership." AND estado='pendiente' LIMIT 1");
} catch (PDOException $e) { $constraintReceipt = $e->getCode() === '23000'; }
check('DB impide dos claims activos aunque se omita el modelo', $constraintReceipt);

pruebasLimpiarRemesas($db, "r.concepto LIKE 'F20 %'");
$db->exec("DELETE FROM mandato_sepa WHERE id_socio=$socio");
pruebasLimpiarMembresias($db, "sm.id_socio=$socio");
finishTests();
