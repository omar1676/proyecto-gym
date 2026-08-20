<?php

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/services/MockAccessControlProvider.php';

$provider = new MockAccessControlProvider();
$decision = new AccessDecision(1, 10, 100, 'PERMITIDO', 'MEMBERSHIP_ACTIVE', null, null, 'v1');

check('mock disponible responde healthy', $provider->healthCheck()->code() === AccessControlResult::SUCCESS);
check('credencial inexistente se informa sin crearla', $provider->findCredential(1, 10, 'opaque-100')->code() === AccessControlResult::NOT_FOUND);
$provider->addCredential(1, 10, 100, 'opaque-100');
check('encuentra credencial opaca en su tenant/sede', $provider->findCredential(1, 10, 'opaque-100')->successful());
check('otra sede no encuentra la credencial', $provider->findCredential(1, 11, 'opaque-100')->code() === AccessControlResult::NOT_FOUND);

$key = $decision->idempotencyKey('mock');
check('sincroniza una decisión en memoria', $provider->syncAccessDecision($decision, 'opaque-100', $key)->code() === AccessControlResult::SUCCESS);
check('operación repetida se reconoce como duplicada', $provider->syncAccessDecision($decision, 'opaque-100', $key)->code() === AccessControlResult::DUPLICATE);
check('solo se procesa una vez', $provider->processedCount() === 1);

$timeoutDecision = new AccessDecision(1, 10, 100, 'REVISAR', 'PAYMENT_REVIEW', null, null, 'v2');
$provider->queueSyncOutcome(AccessControlResult::TIMEOUT);
check('simula timeout sin espera ni red', $provider->syncAccessDecision($timeoutDecision, 'opaque-100', $timeoutDecision->idempotencyKey('mock'))->code() === AccessControlResult::TIMEOUT);
$provider->queueSyncOutcome(AccessControlResult::ERROR);
check('simula error del proveedor', $provider->syncAccessDecision($timeoutDecision, 'opaque-100', $timeoutDecision->idempotencyKey('mock'))->code() === AccessControlResult::ERROR);

$provider->setAvailable(false);
check('simula proveedor caído', $provider->healthCheck()->code() === AccessControlResult::UNAVAILABLE);
check('caída afecta la búsqueda', $provider->findCredential(1, 10, 'opaque-100')->code() === AccessControlResult::UNAVAILABLE);
$provider->setAvailable(true);

$blocked = new AccessDecision(1, 10, 100, 'BLOQUEADO', 'MEMBER_INACTIVE', null, null, 'v3');
$review = new AccessDecision(1, 10, 100, 'REVISAR', 'PAYMENT_REVIEW', null, null, 'v4');
check('mock actualiza un bloqueo lógico', $provider->syncAccessDecision($blocked, 'opaque-100', $blocked->idempotencyKey('mock'))->successful());
check('mock conserva un estado revisar', $provider->syncAccessDecision($review, 'opaque-100', $review->idempotencyKey('mock'))->successful());

$events = $provider->getLastEvents(1, 10)->data()['events'] ?? [];
check('devuelve eventos mock normalizados', array_column($events, 'type') === ['ACCESS_GRANTED','ACCESS_DENIED','ACCESS_REVIEW']);
check('eventos no afirman una entrada física', count(array_filter($events, fn($event) => ($event['source'] ?? '') === 'MOCK_SYNC')) === 3);

finishTests();
