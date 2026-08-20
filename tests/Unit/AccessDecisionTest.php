<?php

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/services/AccessDecision.php';
require_once dirname(__DIR__, 2) . '/app/services/AccessControlMode.php';

$allowed = new AccessDecision(1, 10, 100, AccessDecision::PERMITIDO, 'MEMBERSHIP_ACTIVE', null, null, 'v1');
$blocked = new AccessDecision(1, 10, 100, AccessDecision::BLOQUEADO, 'MEMBER_INACTIVE', null, null, 'v2');
$review = new AccessDecision(1, 10, 100, AccessDecision::REVISAR, 'PAYMENT_REVIEW', null, null, 'v3');

check('formaliza estado permitido', $allowed->estado() === 'PERMITIDO');
check('formaliza estado bloqueado', $blocked->estado() === 'BLOQUEADO');
check('formaliza estado revisar', $review->estado() === 'REVISAR');
check('incluye tenant, sede y socio', $allowed->empresaId() === 1 && $allowed->sedeId() === 10 && $allowed->socioId() === 100);
check('incluye reason_code normalizado', $allowed->reasonCode() === 'MEMBERSHIP_ACTIVE');
check('genera correlation_id UUID v4', (bool) preg_match('/^[a-f0-9-]{36}$/', $allowed->correlationId()));
check('incluye timestamp', strtotime($allowed->decidedAt()) !== false);

$same = new AccessDecision(1, 10, 100, AccessDecision::PERMITIDO, 'MEMBERSHIP_ACTIVE', null, null, 'v1');
check('idempotencia no depende de correlation_id', $same->correlationId() !== $allowed->correlationId() && $same->idempotencyKey('mock') === $allowed->idempotencyKey('mock'));
check('cambiar versión lógica cambia idempotencia', $allowed->idempotencyKey('mock') !== (new AccessDecision(1, 10, 100, AccessDecision::PERMITIDO, 'MEMBERSHIP_ACTIVE', null, null, 'v9'))->idempotencyKey('mock'));

$keys = array_keys($allowed->toArray());
check('la decisión no contiene biometría', count(array_filter($keys, fn($key) => preg_match('/huella|biometr|finger|template|minut/i', $key))) === 0);
check('la decisión no contiene datos financieros', count(array_filter($keys, fn($key) => preg_match('/deuda|importe|iban|pago/i', $key))) === 0);

$invalid = false;
try { new AccessDecision(0, 10, 100, 'PERMITIDO', 'MEMBERSHIP_ACTIVE'); } catch (InvalidArgumentException $e) { $invalid = true; }
check('rechaza ámbito incompleto', $invalid);
$invalid = false;
try { new AccessDecision(1, 10, 100, 'ABRIR', 'MEMBERSHIP_ACTIVE'); } catch (InvalidArgumentException $e) { $invalid = true; }
check('rechaza estados físicos inventados', $invalid);

check('modo inválido cae a disabled', AccessControlMode::resolve('unexpected', true) === 'disabled');
check('active sin confirmación cae a disabled', AccessControlMode::resolve('active', false) === 'disabled');
check('active exige confirmación explícita', AccessControlMode::resolve('active', true) === 'active');
check('shadow se conserva', AccessControlMode::resolve('shadow', false) === 'shadow');

finishTests();
