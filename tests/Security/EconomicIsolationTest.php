<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/services/SocioFinancialService.php';
require_once dirname(__DIR__, 2) . '/app/models/CashModel.php';

$db = Database::getInstance()->getConnection();
$db->exec("DELETE FROM usuario WHERE nombre_usuario LIKE 'f9_iso_%'");
$db->exec("DELETE FROM gimnasio WHERE nombre LIKE 'F9 ISO %'");
$db->exec("DELETE FROM empresa WHERE nombre LIKE 'F9 ISO %'");
$db->exec("INSERT INTO empresa (nombre,nombre_comercial) VALUES ('F9 ISO Empresa B','F9 B')");
$empresaB = (int)$db->lastInsertId();
$db->exec("INSERT INTO gimnasio (id_empresa,nombre) VALUES ($empresaB,'F9 ISO Sede B')");
$sedeB = (int)$db->lastInsertId();
$hash = password_hash('Test-F9-123!', PASSWORD_DEFAULT);
$stmt = $db->prepare("INSERT INTO usuario (id_empresa,id_gimnasio,nombre,apellidos,dni,email,nombre_usuario,contrasena,rol,activo) VALUES (:empresa,:sede,'Socio','B','F9ISOB001','f9b@example.test','f9_iso_b',:hash,'socio',1)");
$stmt->execute([':empresa'=>$empresaB, ':sede'=>$sedeB, ':hash'=>$hash]);
$socioB = (int)$db->lastInsertId();
$db->exec("INSERT INTO obligacion_pago (id_empresa,id_gimnasio,id_socio,concepto,importe,fecha_emision,fecha_vencimiento,estado,origen,idempotency_key) VALUES ($empresaB,$sedeB,$socioB,'F9 deuda B',77.77,CURDATE(),CURDATE(),'pendiente','ajuste','f9-iso-deuda-b')");

$servicioA = new SocioFinancialService(1, 1);
$servicioB = new SocioFinancialService($empresaB, $sedeB);
check('Empresa A no consulta estado económico de socio B', $servicioA->estado($socioB) === null);
check('Empresa B sí consulta su deuda', $servicioB->estado($socioB)['deuda'] === '77.77');

$db->exec("INSERT INTO usuario (id_empresa,id_gimnasio,nombre,apellidos,dni,email,nombre_usuario,contrasena,rol,activo) VALUES ($empresaB,$sedeB,'Admin','B','F9ISOB002','f9adminb@example.test','f9_iso_admin_b',".$db->quote($hash).",'admin',1)");
$adminB = (int)$db->lastInsertId();
$cajaB = new CashModel($sedeB, $empresaB); $error = '';
$sesionB = $cajaB->abrir('20.00', $adminB, $error);
$cajaA = new CashModel(1, 1);
check('Empresa A no consulta movimientos de caja B por ID', $cajaA->movimientos((int)$sesionB) === []);
check('caja B permanece ligada solo a su tenant y sede', (int)$db->query('SELECT id_empresa FROM caja_sesion WHERE id_sesion_caja='.(int)$sesionB)->fetchColumn() === $empresaB);

$db->exec("DELETE FROM caja_movimiento WHERE id_sesion_caja = $sesionB");
$db->exec("DELETE FROM caja_sesion WHERE id_sesion_caja = $sesionB");
$db->exec("DELETE FROM obligacion_pago WHERE id_empresa = $empresaB");
$db->exec("DELETE FROM usuario WHERE id_empresa = $empresaB");
$db->exec("DELETE FROM gimnasio WHERE id_empresa = $empresaB");
$db->exec("DELETE FROM empresa WHERE id_empresa = $empresaB");
finishTests();
