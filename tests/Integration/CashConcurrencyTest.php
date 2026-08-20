<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/models/CashModel.php';
$db = Database::getInstance()->getConnection();
$db->exec("DELETE cm FROM caja_movimiento cm INNER JOIN caja_sesion cs ON cs.id_sesion_caja=cm.id_sesion_caja WHERE cs.id_empresa=1 AND cs.id_gimnasio=1");
$db->exec('DELETE FROM caja_sesion WHERE id_empresa=1 AND id_gimnasio=1');

function ejecutarCajaConcurrente(string $operacion): array {
    $barrera = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'f9_cash_' . bin2hex(random_bytes(8));
    $worker = dirname(__DIR__) . '/Support/cash_concurrency_worker.php';
    $procesos = [];
    for ($i=0; $i<2; $i++) {
        $pipes=[];
        $p=proc_open([PHP_BINARY,$worker,$operacion,$barrera],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,2));
        if (is_resource($p)) $procesos[]=[$p,$pipes];
    }
    touch($barrera); $resultados=[];
    foreach ($procesos as [$p,$pipes]) {
        $salida=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($p);
        $resultados[]=['data'=>json_decode($salida,true),'exit'=>$exit,'stderr'=>$error];
    }
    @unlink($barrera); return $resultados;
}

$aperturas = ejecutarCajaConcurrente('abrir');
check('arrancan dos aperturas independientes', count($aperturas) === 2 && count(array_filter($aperturas, fn($r)=>$r['exit']===0)) === 2);
check('solo una apertura concurrente gana', count(array_filter($aperturas, fn($r)=>!empty($r['data']['ok']))) === 1);
check('la base conserva una sola caja abierta', (int)$db->query("SELECT COUNT(*) FROM caja_sesion WHERE id_empresa=1 AND id_gimnasio=1 AND estado='abierta'")->fetchColumn() === 1);

$cierres = ejecutarCajaConcurrente('cerrar');
check('solo un cierre concurrente gana', count(array_filter($cierres, fn($r)=>!empty($r['data']['ok']))) === 1);
check('la sesión termina cerrada una sola vez', (int)$db->query("SELECT COUNT(*) FROM caja_sesion WHERE id_empresa=1 AND id_gimnasio=1 AND estado='cerrada'")->fetchColumn() === 1);
$db->exec("DELETE cm FROM caja_movimiento cm INNER JOIN caja_sesion cs ON cs.id_sesion_caja=cm.id_sesion_caja WHERE cs.id_empresa=1 AND cs.id_gimnasio=1");
$db->exec('DELETE FROM caja_sesion WHERE id_empresa=1 AND id_gimnasio=1');
finishTests();
