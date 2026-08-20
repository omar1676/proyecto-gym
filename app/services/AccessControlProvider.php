<?php

require_once __DIR__ . '/AccessDecision.php';
require_once __DIR__ . '/AccessControlResult.php';

/**
 * Puerto entre el SaaS y cualquier infraestructura de acceso autorizada.
 * Deliberadamente no contiene openDoor(), biometría ni comandos propietarios.
 */
interface AccessControlProvider
{
    public function name(): string;

    public function healthCheck(): AccessControlResult;

    public function findCredential(
        int $empresaId,
        int $sedeId,
        string $externalIdentityId
    ): AccessControlResult;

    public function syncAccessDecision(
        AccessDecision $decision,
        string $externalIdentityId,
        string $idempotencyKey
    ): AccessControlResult;

    public function getLastEvents(
        int $empresaId,
        int $sedeId,
        ?string $cursor = null,
        int $limit = 100
    ): AccessControlResult;
}
