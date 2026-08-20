<?php

require_once __DIR__ . '/AccessControlProvider.php';

/** Provider determinista y completamente local para pruebas y modo shadow. */
final class MockAccessControlProvider implements AccessControlProvider
{
    private bool $available;
    private array $credentials = [];
    private array $processed = [];
    private array $events = [];
    private array $syncOutcomes = [];

    public function __construct(bool $available = true)
    {
        $this->available = $available;
    }

    public function name(): string { return 'mock'; }
    public function setAvailable(bool $available): void { $this->available = $available; }

    public function addCredential(int $empresaId, int $sedeId, int $socioId, string $externalIdentityId): void
    {
        $this->credentials[$this->credentialKey($empresaId, $sedeId, $externalIdentityId)] = [
            'empresa_id' => $empresaId,
            'sede_id' => $sedeId,
            'socio_id' => $socioId,
            'external_identity_id' => $externalIdentityId,
        ];
    }

    /** Programa respuestas como TIMEOUT, ERROR o SUCCESS sin esperas reales. */
    public function queueSyncOutcome(string $code): void
    {
        // Valida el código reutilizando el value object.
        new AccessControlResult($code);
        $this->syncOutcomes[] = $code;
    }

    public function healthCheck(): AccessControlResult
    {
        return new AccessControlResult($this->available ? AccessControlResult::SUCCESS : AccessControlResult::UNAVAILABLE);
    }

    public function findCredential(int $empresaId, int $sedeId, string $externalIdentityId): AccessControlResult
    {
        if (!$this->available) return new AccessControlResult(AccessControlResult::UNAVAILABLE);
        $credential = $this->credentials[$this->credentialKey($empresaId, $sedeId, $externalIdentityId)] ?? null;
        return $credential
            ? new AccessControlResult(AccessControlResult::SUCCESS, 0, ['credential' => $credential])
            : new AccessControlResult(AccessControlResult::NOT_FOUND);
    }

    public function syncAccessDecision(
        AccessDecision $decision,
        string $externalIdentityId,
        string $idempotencyKey
    ): AccessControlResult {
        if (!$this->available) return new AccessControlResult(AccessControlResult::UNAVAILABLE);
        if (isset($this->processed[$idempotencyKey])) {
            return new AccessControlResult(AccessControlResult::DUPLICATE);
        }
        if ($this->syncOutcomes) {
            $outcome = array_shift($this->syncOutcomes);
            if ($outcome !== AccessControlResult::SUCCESS) {
                return new AccessControlResult($outcome);
            }
        }
        $credential = $this->findCredential($decision->empresaId(), $decision->sedeId(), $externalIdentityId);
        if (!$credential->successful()) return $credential;

        $this->processed[$idempotencyKey] = $decision->toArray();
        $this->events[] = [
            'type' => $decision->estado() === AccessDecision::PERMITIDO ? 'ACCESS_GRANTED'
                : ($decision->estado() === AccessDecision::BLOQUEADO ? 'ACCESS_DENIED' : 'ACCESS_REVIEW'),
            'empresa_id' => $decision->empresaId(),
            'sede_id' => $decision->sedeId(),
            'socio_id' => $decision->socioId(),
            'correlation_id' => $decision->correlationId(),
            // Es un evento simulado de sincronización, nunca una entrada física.
            'source' => 'MOCK_SYNC',
        ];
        return new AccessControlResult(AccessControlResult::SUCCESS);
    }

    public function getLastEvents(int $empresaId, int $sedeId, ?string $cursor = null, int $limit = 100): AccessControlResult
    {
        if (!$this->available) return new AccessControlResult(AccessControlResult::UNAVAILABLE);
        $events = array_values(array_filter($this->events, static fn(array $event): bool =>
            $event['empresa_id'] === $empresaId && $event['sede_id'] === $sedeId
        ));
        return new AccessControlResult(AccessControlResult::SUCCESS, 0, [
            'events' => array_slice($events, -max(1, min(500, $limit))),
            'cursor' => $cursor,
        ]);
    }

    public function processedCount(): int { return count($this->processed); }

    private function credentialKey(int $empresaId, int $sedeId, string $externalIdentityId): string
    {
        return $empresaId . '|' . $sedeId . '|' . $externalIdentityId;
    }
}
