<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/models/BlackList.php';
$db = Database::getInstance()->getConnection();
$db->exec("DELETE FROM intentos_login WHERE usuario LIKE 'ratelimit-%'");
$limiter = new Blacklist($db);
$ip = '192.0.2.50';
for ($i = 0; $i < 5; $i++) $limiter->registrarIntentoFallido($ip, $i % 2 ? 'RATELIMIT-USER' : 'ratelimit-user');
check('mayúsculas no evaden el contador de cuenta', $limiter->estaBloqueado('198.51.100.9', 'RateLimit-User'));
$limiter->limpiarIntentos($ip, 'RATELIMIT-USER');
check('login correcto puede reiniciar la cuenta', !$limiter->estaBloqueado('198.51.100.9', 'ratelimit-user'));
for ($i = 0; $i < 25; $i++) $limiter->registrarIntentoFallido($ip, 'ratelimit-' . $i);
check('ataque distribuido por cuentas se bloquea por IP', $limiter->estaBloqueado($ip, 'otra-cuenta'));
$db->exec("DELETE FROM intentos_login WHERE usuario LIKE 'ratelimit-%'");
finishTests();
