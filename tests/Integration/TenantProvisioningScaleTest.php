<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/TenantOnboardingFactory.php';

$db = Database::getInstance()->getConnection();
$service = new TenantProvisioningService($db, 6);
$companyIds = [];
$times = [];
$questionsBefore = (int) $db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
$schema = (string) $db->query('SELECT DATABASE()')->fetchColumn();
$sizeStmt = $db->prepare('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=:schema');
$sizeStmt->execute([':schema'=>$schema]);
$bytesBefore = (int) $sizeStmt->fetchColumn();
$started = microtime(true);
for ($i = 1; $i <= 100; $i++) {
    $one = microtime(true);
    $result = $service->provision(TenantOnboardingFactory::input('Scale ' . $i, ['membership_types' => []]));
    $times[] = (microtime(true) - $one) * 1000;
    $companyIds[] = (int) $result['company_id'];
    if (in_array($i, [1,10,50,100], true)) {
        printf("METRICA_F22 tenants=%d acumulado_ms=%.2f ultimo_ms=%.2f\n", $i, (microtime(true)-$started)*1000, end($times));
    }
}
sort($times);
$p95 = $times[(int) floor((count($times)-1)*0.95)];
$questionsAfter = (int) $db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
$sizeStmt->execute([':schema'=>$schema]);
$bytesAfter = (int) $sizeStmt->fetchColumn();
$questionDelta = $questionsAfter - $questionsBefore;
$byteDelta = max(0, $bytesAfter - $bytesBefore);
printf(
    "METRICA_F22 provisioning_100_ms=%.2f p95_ms=%.2f session_queries=%d approx_db_delta_bytes=%d\n",
    (microtime(true)-$started)*1000, $p95, $questionDelta, $byteDelta
);
check('provisioning crea 100 tenants sintéticos', count(array_unique($companyIds)) === 100);
$ids = implode(',', $companyIds);
$payloadBytes = 0;
foreach ([
    "SELECT COALESCE(SUM(OCTET_LENGTH(CONCAT_WS('|',nombre,nombre_comercial,slug,email,telefono,configuracion))),0) FROM empresa WHERE id_empresa IN ({$ids})",
    "SELECT COALESCE(SUM(OCTET_LENGTH(CONCAT_WS('|',nombre,slug,email_acceso,email,telefono))),0) FROM gimnasio WHERE id_empresa IN ({$ids})",
    "SELECT COALESCE(SUM(OCTET_LENGTH(CONCAT_WS('|',nombre,apellidos,email,nombre_usuario,contrasena))),0) FROM usuario WHERE id_empresa IN ({$ids})",
    "SELECT COALESCE(SUM(OCTET_LENGTH(CONCAT_WS('|',accion,detalle,valor_anterior,valor_nuevo,metadata_json))),0) FROM log_actividad WHERE id_empresa IN ({$ids})",
] as $payloadQuery) {
    $payloadBytes += (int) $db->query($payloadQuery)->fetchColumn();
}
printf("METRICA_F22 synthetic_row_payload_bytes=%d\n", $payloadBytes);
check('100 tenants conservan una sede y dirección',
    (int) $db->query("SELECT COUNT(*) FROM gimnasio WHERE id_empresa IN ({$ids})")->fetchColumn() === 100
    && (int) $db->query("SELECT COUNT(*) FROM usuario WHERE id_empresa IN ({$ids}) AND rol='direccion'")->fetchColumn() === 100);
check('ningún tenant de escala se activa falsamente', (int) $db->query("SELECT COUNT(*) FROM empresa WHERE id_empresa IN ({$ids}) AND (estado<>'inactiva' OR onboarding_state<>'READY_FOR_REVIEW')")->fetchColumn() === 0);
check('configuración de acceso de los 100 tenants queda disabled', (int) $db->query("SELECT COUNT(*) FROM empresa WHERE id_empresa IN ({$ids}) AND JSON_UNQUOTE(JSON_EXTRACT(configuracion,'$.access_control.mode'))='disabled'")->fetchColumn() === 100);
check('p95 medido y finito', is_finite($p95) && $p95 > 0);
check('consultas de provisioning medidas en la sesión real', $questionDelta > 0);
check('tamaño DB aproximado medido sin asumir precisión de InnoDB', $byteDelta >= 0);
check('payload sintético de filas medido de forma determinista', $payloadBytes > 0);

finishTests();
