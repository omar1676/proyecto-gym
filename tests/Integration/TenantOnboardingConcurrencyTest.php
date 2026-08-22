<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/TenantOnboardingFactory.php';

$db = Database::getInstance()->getConnection();
$worker = dirname(__DIR__) . '/Support/tenant_provision_worker.php';

function f22Race(array $payloads): array {
    global $worker;
    $barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'f22_onboarding_' . bin2hex(random_bytes(8));
    $running = [];
    foreach ($payloads as $payload) {
        $pipes = [];
        $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, $worker, $barrier, $encoded], [1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__, 2));
        if (is_resource($process)) $running[] = [$process,$pipes];
    }
    touch($barrier);
    $result = [];
    foreach ($running as [$process,$pipes]) {
        $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $result[]=['exit'=>proc_close($process),'data'=>json_decode($stdout,true),'stderr'=>$stderr];
    }
    @unlink($barrier);
    return $result;
}

$same = TenantOnboardingFactory::input('Concurrent Same');
$results = f22Race([$same,$same]);
check('dos procesos concurrentes terminan de forma controlada', count($results) === 2 && count(array_filter($results, fn($r)=>$r['exit']===0)) === 2);
check('solo una petición crea y la otra reentra', count(array_filter($results, fn($r)=>!empty($r['data']['created']))) === 1
    && count(array_filter($results, fn($r)=>isset($r['data']['created']) && !$r['data']['created'])) === 1);
$companyId = (int) ($results[0]['data']['company_id'] ?? $results[1]['data']['company_id'] ?? 0);
check('ambos procesos devuelven la misma empresa', $companyId > 0 && count(array_unique(array_map(fn($r)=>(int)($r['data']['company_id']??0),$results))) === 1);
check('carrera conserva una empresa sede y owner',
    (int)$db->query("SELECT COUNT(*) FROM empresa WHERE id_empresa={$companyId}")->fetchColumn()===1
    && (int)$db->query("SELECT COUNT(*) FROM gimnasio WHERE id_empresa={$companyId}")->fetchColumn()===1
    && (int)$db->query("SELECT COUNT(*) FROM usuario WHERE id_empresa={$companyId} AND rol='direccion'")->fetchColumn()===1);
check('carrera deja estado completo y nunca activación falsa', (string)$db->query("SELECT CONCAT(estado,':',onboarding_state) FROM empresa WHERE id_empresa={$companyId}")->fetchColumn()==='inactiva:READY_FOR_REVIEW');

$first = TenantOnboardingFactory::input('Concurrent Conflict A', ['company_name'=>'TEST F22 Same Legal Name']);
$second = TenantOnboardingFactory::input('Concurrent Conflict B', ['company_name'=>'TEST F22 Same Legal Name']);
$conflict = f22Race([$first,$second]);
check('nombre legal concurrente produce un ganador y un rechazo explícito', count(array_filter($conflict,fn($r)=>$r['exit']===0))===1
    && count(array_filter($conflict,fn($r)=>$r['exit']===10))===1);
check('conflicto no duplica empresa', (int)$db->query("SELECT COUNT(*) FROM empresa WHERE nombre='TEST F22 Same Legal Name'")->fetchColumn()===1);
$winner = (int)$db->query("SELECT id_empresa FROM empresa WHERE nombre='TEST F22 Same Legal Name'")->fetchColumn();
check('ganador no queda huérfano', (int)$db->query("SELECT COUNT(*) FROM gimnasio WHERE id_empresa={$winner}")->fetchColumn()===1
    && (int)$db->query("SELECT COUNT(*) FROM usuario WHERE id_empresa={$winner} AND rol='direccion'")->fetchColumn()===1);

finishTests();
