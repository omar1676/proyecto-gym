<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/AccessDecision.php';

/** Persistencia tenant-scoped para identidades, outbox y auditoría de acceso. */
final class AccessControlRepository
{
    private PDO $db;
    private int $maxAttempts;
    private int $backoffSeconds;

    public function __construct(?PDO $db = null, int $maxAttempts = 5, int $backoffSeconds = 60)
    {
        $this->db = $db ?: Database::getInstance()->getConnection();
        $this->maxAttempts = max(1, min(10, $maxAttempts));
        $this->backoffSeconds = max(5, min(3600, $backoffSeconds));
    }

    public function mapIdentity(
        int $empresaId,
        int $sedeId,
        int $socioId,
        string $provider,
        string $externalIdentityId,
        string $status = 'active'
    ): int {
        $provider = $this->provider($provider);
        $externalIdentityId = trim($externalIdentityId);
        if ($externalIdentityId === '' || mb_strlen($externalIdentityId) > 190 || preg_match('/[\x00-\x1F\x7F]/u', $externalIdentityId)) {
            throw new InvalidArgumentException('Identidad externa no válida.');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('Estado de identidad no válido.');
        }
        $this->assertMemberScope($empresaId, $sedeId, $socioId);

        $external = $this->db->prepare(
            'SELECT * FROM access_identity_map
             WHERE id_empresa=:empresa AND provider=:provider AND external_identity_id=:external LIMIT 1'
        );
        $external->execute([':empresa'=>$empresaId, ':provider'=>$provider, ':external'=>$externalIdentityId]);
        $existingExternal = $external->fetch();
        if ($existingExternal && ((int) $existingExternal['id_socio'] !== $socioId || (int) $existingExternal['id_gimnasio'] !== $sedeId)) {
            throw new DomainException('La identidad externa ya pertenece a otro socio o sede del tenant.');
        }

        $member = $this->db->prepare(
            'SELECT * FROM access_identity_map
             WHERE id_empresa=:empresa AND id_gimnasio=:sede AND provider=:provider AND id_socio=:socio LIMIT 1'
        );
        $member->execute([':empresa'=>$empresaId, ':sede'=>$sedeId, ':provider'=>$provider, ':socio'=>$socioId]);
        $existingMember = $member->fetch();
        if ($existingMember) {
            if (!hash_equals((string) $existingMember['external_identity_id'], $externalIdentityId)) {
                throw new DomainException('El socio ya tiene otra identidad externa; el cambio requiere un procedimiento explícito.');
            }
            $update = $this->db->prepare('UPDATE access_identity_map SET status=:status WHERE id_map=:id');
            $update->execute([':status'=>$status, ':id'=>$existingMember['id_map']]);
            return (int) $existingMember['id_map'];
        }

        $insert = $this->db->prepare(
            'INSERT INTO access_identity_map
             (id_empresa,id_gimnasio,provider,id_socio,external_identity_id,status)
             VALUES (:empresa,:sede,:provider,:socio,:external,:status)'
        );
        $insert->execute([
            ':empresa'=>$empresaId, ':sede'=>$sedeId, ':provider'=>$provider,
            ':socio'=>$socioId, ':external'=>$externalIdentityId, ':status'=>$status,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function findActiveIdentity(int $empresaId, int $sedeId, int $socioId, string $provider): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id_map,id_empresa,id_gimnasio,provider,id_socio,external_identity_id,status
             FROM access_identity_map
             WHERE id_empresa=:empresa AND id_gimnasio=:sede AND id_socio=:socio
               AND provider=:provider AND status='active' LIMIT 1"
        );
        $stmt->execute([
            ':empresa'=>$empresaId, ':sede'=>$sedeId, ':socio'=>$socioId,
            ':provider'=>$this->provider($provider),
        ]);
        return $stmt->fetch() ?: null;
    }

    public function findIdentityByExternal(
        int $empresaId,
        int $sedeId,
        string $provider,
        string $externalIdentityId
    ): ?array {
        $stmt = $this->db->prepare(
            'SELECT id_map,id_empresa,id_gimnasio,provider,id_socio,external_identity_id,status
             FROM access_identity_map
             WHERE id_empresa=:empresa AND id_gimnasio=:sede AND provider=:provider
               AND external_identity_id=:external LIMIT 1'
        );
        $stmt->execute([
            ':empresa'=>$empresaId, ':sede'=>$sedeId, ':provider'=>$this->provider($provider),
            ':external'=>trim($externalIdentityId),
        ]);
        return $stmt->fetch() ?: null;
    }

    /** Crea una intención una sola vez aunque el mismo cambio llegue repetido. */
    public function enqueue(AccessDecision $decision, string $provider, ?int $actorId = null): array
    {
        $provider = $this->provider($provider);
        $this->assertMemberScope($decision->empresaId(), $decision->sedeId(), $decision->socioId());
        $this->assertActorScope($decision->empresaId(), $actorId);
        $key = $decision->idempotencyKey($provider);
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO access_sync_job
                 (id_empresa,id_gimnasio,id_socio,id_usuario,provider,decision_state,
                  reason_code,decision_version,decision_at,correlation_id,idempotency_key,max_attempts)
                 VALUES
                 (:empresa,:sede,:socio,:usuario,:provider,:estado,
                  :reason,:version,:decided,:correlation,:idempotency,:max_attempts)'
            );
            $stmt->execute([
                ':empresa'=>$decision->empresaId(), ':sede'=>$decision->sedeId(),
                ':socio'=>$decision->socioId(), ':usuario'=>$actorId ?: null,
                ':provider'=>$provider, ':estado'=>$decision->estado(),
                ':reason'=>$decision->reasonCode(), ':version'=>$decision->decisionVersion(),
                ':decided'=>date('Y-m-d H:i:s', strtotime($decision->decidedAt())),
                ':correlation'=>$decision->correlationId(), ':idempotency'=>$key,
                ':max_attempts'=>$this->maxAttempts,
            ]);
            $id = (int) $this->db->lastInsertId();
            return ['job'=>$this->jobById($id), 'duplicate'=>false];
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            $stmt = $this->db->prepare(
                'SELECT * FROM access_sync_job
                 WHERE id_empresa=:empresa AND provider=:provider AND idempotency_key=:key LIMIT 1'
            );
            $stmt->execute([':empresa'=>$decision->empresaId(), ':provider'=>$provider, ':key'=>$key]);
            $job = $stmt->fetch();
            if (!$job) throw $e;
            return ['job'=>$job, 'duplicate'=>true];
        }
    }

    /** Reclama un trabajo de forma serializable para varios workers MySQL. */
    public function claimNext(string $workerId): ?array
    {
        $workerId = $this->code($workerId, 'worker');
        $this->db->beginTransaction();
        try {
            $row = $this->db->query(
                "SELECT * FROM access_sync_job
                 WHERE status IN ('PENDING','RETRY') AND attempts < max_attempts
                   AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
                 ORDER BY created_at,id_job LIMIT 1 FOR UPDATE"
            )->fetch();
            if (!$row) {
                $this->db->commit();
                return null;
            }
            $stmt = $this->db->prepare(
                "UPDATE access_sync_job
                 SET status='PROCESSING',attempts=attempts+1,locked_at=NOW(),locked_by=:worker
                 WHERE id_job=:id"
            );
            $stmt->execute([':worker'=>$workerId, ':id'=>$row['id_job']]);
            $this->db->commit();
            return $this->jobById((int) $row['id_job']);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function markSynced(int $jobId, string $resultCode): void
    {
        $stmt = $this->db->prepare(
            "UPDATE access_sync_job SET status='SYNCED',provider_result_code=:result,
             last_error_code=NULL,next_attempt_at=NULL,locked_at=NULL,locked_by=NULL
             WHERE id_job=:id AND status='PROCESSING'"
        );
        $stmt->execute([':result'=>$this->code($resultCode, 'result'), ':id'=>$jobId]);
    }

    public function markShadowSynced(int $jobId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE access_sync_job SET status='SYNCED',provider_result_code='SHADOW_SIMULATED',
             last_error_code=NULL,next_attempt_at=NULL,locked_at=NULL,locked_by=NULL
             WHERE id_job=:id AND status IN ('PENDING','RETRY')"
        );
        $stmt->execute([':id'=>$jobId]);
    }

    public function markFailure(int $jobId, string $errorCode): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM access_sync_job WHERE id_job=:id LIMIT 1 FOR UPDATE');
            $stmt->execute([':id'=>$jobId]);
            $job = $stmt->fetch();
            if (!$job) throw new DomainException('Trabajo de acceso inexistente.');
            $attempts = (int) $job['attempts'];
            $failed = $attempts >= (int) $job['max_attempts'];
            $delay = min(3600, $this->backoffSeconds * (2 ** max(0, $attempts - 1)));
            $next = $failed ? null : date('Y-m-d H:i:s', time() + $delay);
            $update = $this->db->prepare(
                "UPDATE access_sync_job SET status=:status,last_error_code=:error,
                 provider_result_code=:result,next_attempt_at=:next,locked_at=NULL,locked_by=NULL
                 WHERE id_job=:id"
            );
            $errorCode = $this->code($errorCode, 'error');
            $update->execute([
                ':status'=>$failed ? 'FAILED' : 'RETRY', ':error'=>$errorCode,
                ':result'=>$errorCode, ':next'=>$next, ':id'=>$jobId,
            ]);
            $this->db->commit();
            return $this->jobById($jobId);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function recoverStale(int $minutes = 10): int
    {
        $minutes = max(1, min(120, $minutes));
        $stmt = $this->db->prepare(
            "UPDATE access_sync_job SET status='RETRY',next_attempt_at=NOW(),
             last_error_code='WORKER_STALE',locked_at=NULL,locked_by=NULL
             WHERE status='PROCESSING' AND locked_at < DATE_SUB(NOW(), INTERVAL :minutes MINUTE)"
        );
        $stmt->bindValue(':minutes', $minutes, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function audit(
        AccessDecision $decision,
        string $provider,
        string $action,
        string $resultCode,
        ?int $actorId = null,
        string $actorProcess = 'application',
        ?int $jobId = null,
        int $latencyMs = 0
    ): int {
        $this->assertMemberScope($decision->empresaId(), $decision->sedeId(), $decision->socioId());
        $this->assertActorScope($decision->empresaId(), $actorId);
        $stmt = $this->db->prepare(
            'INSERT INTO access_control_audit
             (id_empresa,id_gimnasio,id_socio,id_usuario,id_job,actor_process,provider,
              action,decision_state,reason_code,result_code,correlation_id,latency_ms)
             VALUES
             (:empresa,:sede,:socio,:usuario,:job,:process,:provider,
              :action,:state,:reason,:result,:correlation,:latency)'
        );
        $stmt->execute([
            ':empresa'=>$decision->empresaId(), ':sede'=>$decision->sedeId(),
            ':socio'=>$decision->socioId(), ':usuario'=>$actorId ?: null, ':job'=>$jobId ?: null,
            ':process'=>$this->code($actorProcess, 'process'), ':provider'=>$this->provider($provider),
            ':action'=>$this->code($action, 'action'), ':state'=>$decision->estado(),
            ':reason'=>$decision->reasonCode(), ':result'=>$this->code($resultCode, 'result'),
            ':correlation'=>$decision->correlationId(), ':latency'=>max(0, $latencyMs),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function listJobs(int $empresaId, ?int $sedeId = null, int $limit = 100): array
    {
        $sql = 'SELECT * FROM access_sync_job WHERE id_empresa=:empresa';
        $params = [':empresa'=>$empresaId];
        if ($sedeId !== null) { $sql .= ' AND id_gimnasio=:sede'; $params[':sede'] = $sedeId; }
        $sql .= ' ORDER BY created_at DESC,id_job DESC LIMIT :limit';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key=>$value) $stmt->bindValue($key, $value, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function listAudit(int $empresaId, ?int $sedeId = null, int $limit = 100): array
    {
        $sql = 'SELECT * FROM access_control_audit WHERE id_empresa=:empresa';
        $params = [':empresa'=>$empresaId];
        if ($sedeId !== null) { $sql .= ' AND id_gimnasio=:sede'; $params[':sede'] = $sedeId; }
        $sql .= ' ORDER BY created_at DESC,id_audit DESC LIMIT :limit';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key=>$value) $stmt->bindValue($key, $value, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function metrics(int $empresaId, ?int $sedeId = null): array
    {
        $where = 'id_empresa=:empresa';
        $params = [':empresa'=>$empresaId];
        if ($sedeId !== null) { $where .= ' AND id_gimnasio=:sede'; $params[':sede'] = $sedeId; }
        $jobs = $this->db->prepare(
            "SELECT status,COUNT(*) total FROM access_sync_job WHERE {$where} GROUP BY status"
        );
        $jobs->execute($params);
        $statuses = array_fill_keys(['PENDING','PROCESSING','SYNCED','FAILED','RETRY'], 0);
        foreach ($jobs->fetchAll() as $row) $statuses[$row['status']] = (int) $row['total'];
        $audit = $this->db->prepare(
            "SELECT COUNT(*) sync_count,COALESCE(AVG(latency_ms),0) avg_latency_ms
             FROM access_control_audit WHERE {$where}"
        );
        $audit->execute($params);
        $attempts = $this->db->prepare(
            "SELECT COALESCE(SUM(attempts),0) attempts,
                    COALESCE(SUM(CASE WHEN attempts > 1 THEN attempts - 1 ELSE 0 END),0) retries
             FROM access_sync_job WHERE {$where}"
        );
        $attempts->execute($params);
        $decisions = $this->db->prepare(
            "SELECT decision_state,COUNT(*) total FROM access_control_audit
             WHERE {$where} GROUP BY decision_state"
        );
        $decisions->execute($params);
        $decisionCounts = array_fill_keys(['PERMITIDO','BLOQUEADO','REVISAR'], 0);
        foreach ($decisions->fetchAll() as $row) $decisionCounts[$row['decision_state']] = (int) $row['total'];
        return [
            'jobs'=>$statuses,
            'attempts'=>$attempts->fetch() ?: ['attempts'=>0,'retries'=>0],
            'decisions'=>$decisionCounts,
            'audit'=>$audit->fetch() ?: ['sync_count'=>0,'avg_latency_ms'=>0],
        ];
    }

    private function jobById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM access_sync_job WHERE id_job=:id LIMIT 1');
        $stmt->execute([':id'=>$id]);
        return $stmt->fetch() ?: null;
    }

    private function assertMemberScope(int $empresaId, int $sedeId, int $socioId): void
    {
        if ($empresaId <= 0 || $sedeId <= 0 || $socioId <= 0) {
            throw new InvalidArgumentException('Ámbito de acceso incompleto.');
        }
        $stmt = $this->db->prepare(
            "SELECT 1 FROM usuario u
             INNER JOIN gimnasio g ON g.id_gimnasio=u.id_gimnasio AND g.id_empresa=u.id_empresa
             WHERE u.id_usuario=:socio AND u.id_empresa=:empresa AND u.id_gimnasio=:sede
               AND u.rol='socio' LIMIT 1"
        );
        $stmt->execute([':socio'=>$socioId, ':empresa'=>$empresaId, ':sede'=>$sedeId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('Socio fuera del ámbito empresa/sede autorizado.');
        }
    }

    private function assertActorScope(int $empresaId, ?int $actorId): void
    {
        if (!$actorId) return;
        $stmt = $this->db->prepare(
            "SELECT 1 FROM usuario WHERE id_usuario=:actor
             AND (id_empresa=:empresa OR rol='superadmin') LIMIT 1"
        );
        $stmt->execute([':actor'=>$actorId, ':empresa'=>$empresaId]);
        if (!$stmt->fetchColumn()) throw new DomainException('Actor fuera del tenant autorizado.');
    }

    private function provider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $provider)) {
            throw new InvalidArgumentException('Provider no válido.');
        }
        return $provider;
    }

    private function code(string $value, string $fallback): string
    {
        $value = strtoupper(trim($value));
        return preg_match('/^[A-Z0-9_:-]{1,64}$/', $value) ? $value : strtoupper($fallback);
    }
}
