<?php

require_once dirname(__DIR__) . '/helpers/AppLogger.php';
require_once __DIR__ . '/AccessControlMode.php';
require_once __DIR__ . '/AccessControlProvider.php';
require_once __DIR__ . '/AccessControlRepository.php';

/** Orquesta decisiones, outbox y provider sin conocer ningún fabricante. */
final class AccessControlSyncService
{
    private string $mode;
    private AccessControlProvider $provider;
    private AccessControlRepository $repository;

    public function __construct(
        string $configuredMode,
        bool $activeConfirmed,
        AccessControlProvider $provider,
        AccessControlRepository $repository
    ) {
        $this->mode = AccessControlMode::resolve($configuredMode, $activeConfirmed);
        $this->provider = $provider;
        $this->repository = $repository;
    }

    public function mode(): string { return $this->mode; }

    /** Registra la intención. Disabled no crea cola; shadow nunca llama al provider. */
    public function request(AccessDecision $decision, ?int $actorId = null, string $actorProcess = 'application'): array
    {
        if ($this->mode === AccessControlMode::DISABLED) {
            $this->repository->audit(
                $decision, $this->provider->name(), 'DECISION_EVALUATED', 'DISABLED',
                $actorId, $actorProcess
            );
            return ['status'=>'DISABLED', 'queued'=>false, 'duplicate'=>false];
        }

        $queued = $this->repository->enqueue($decision, $this->provider->name(), $actorId);
        $job = $queued['job'];
        if ($queued['duplicate']) {
            $this->repository->audit(
                $decision, $this->provider->name(), 'SYNC_REQUEST', 'DUPLICATE',
                $actorId, $actorProcess, (int) $job['id_job']
            );
            return ['status'=>$job['status'], 'queued'=>false, 'duplicate'=>true, 'job_id'=>(int) $job['id_job']];
        }

        if ($this->mode === AccessControlMode::SHADOW) {
            // No se consulta ni modifica el provider. Solo se deja evidencia de
            // lo que el SaaS habría intentado sincronizar.
            $this->repository->markShadowSynced((int) $job['id_job']);
            $this->repository->audit(
                $decision, $this->provider->name(), 'SYNC_REQUEST', 'SHADOW_SIMULATED',
                $actorId, $actorProcess, (int) $job['id_job']
            );
            return ['status'=>'SYNCED', 'queued'=>false, 'duplicate'=>false, 'job_id'=>(int) $job['id_job']];
        }

        $this->repository->audit(
            $decision, $this->provider->name(), 'SYNC_REQUEST', 'QUEUED',
            $actorId, $actorProcess, (int) $job['id_job']
        );
        return ['status'=>'PENDING', 'queued'=>true, 'duplicate'=>false, 'job_id'=>(int) $job['id_job']];
    }

    public function processOne(string $workerId = 'access-cron'): array
    {
        if ($this->mode === AccessControlMode::DISABLED) {
            return ['status'=>'DISABLED'];
        }
        $job = $this->repository->claimNext($workerId);
        if (!$job) return ['status'=>'EMPTY'];

        $decision = new AccessDecision(
            (int) $job['id_empresa'], (int) $job['id_gimnasio'], (int) $job['id_socio'],
            $job['decision_state'], $job['reason_code'], $job['decision_at'],
            $job['correlation_id'], $job['decision_version']
        );

        if ($this->mode === AccessControlMode::SHADOW) {
            $this->repository->markSynced((int) $job['id_job'], 'SHADOW_SIMULATED');
            $this->repository->audit(
                $decision, $this->provider->name(), 'SYNC_PROCESS', 'SHADOW_SIMULATED',
                null, $workerId, (int) $job['id_job']
            );
            return ['status'=>'SYNCED', 'result'=>'SHADOW_SIMULATED', 'job_id'=>(int) $job['id_job']];
        }

        $map = $this->repository->findActiveIdentity(
            $decision->empresaId(), $decision->sedeId(), $decision->socioId(), $this->provider->name()
        );
        if (!$map) return $this->failure($decision, $job, 'IDENTITY_NOT_FOUND', $workerId);

        $credential = $this->provider->findCredential(
            $decision->empresaId(), $decision->sedeId(), (string) $map['external_identity_id']
        );
        if (!$credential->successful()) {
            return $this->failure($decision, $job, $credential->code(), $workerId, $credential->latencyMs());
        }

        $result = $this->provider->syncAccessDecision(
            $decision, (string) $map['external_identity_id'], (string) $job['idempotency_key']
        );
        if (!$result->successful()) {
            return $this->failure($decision, $job, $result->code(), $workerId, $result->latencyMs());
        }

        $this->repository->markSynced((int) $job['id_job'], $result->code());
        $this->repository->audit(
            $decision, $this->provider->name(), 'SYNC_PROCESS', $result->code(),
            null, $workerId, (int) $job['id_job'], $result->latencyMs()
        );
        return ['status'=>'SYNCED', 'result'=>$result->code(), 'job_id'=>(int) $job['id_job']];
    }

    private function failure(
        AccessDecision $decision,
        array $job,
        string $code,
        string $workerId,
        int $latencyMs = 0
    ): array {
        $updated = $this->repository->markFailure((int) $job['id_job'], $code);
        $this->repository->audit(
            $decision, $this->provider->name(), 'SYNC_PROCESS', $code,
            null, $workerId, (int) $job['id_job'], $latencyMs
        );
        AppLogger::warning('access_control_sync_failed', [
            'company_id'=>$decision->empresaId(), 'site_id'=>$decision->sedeId(),
            'member_id'=>$decision->socioId(), 'provider'=>$this->provider->name(),
            'result_code'=>$code, 'correlation_id'=>$decision->correlationId(),
            'job_id'=>(int) $job['id_job'], 'status'=>$updated['status'],
        ]);
        return ['status'=>$updated['status'], 'result'=>$code, 'job_id'=>(int) $job['id_job']];
    }
}
