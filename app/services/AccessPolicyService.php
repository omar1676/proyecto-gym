<?php

require_once dirname(__DIR__) . '/helpers/Authorization.php';
require_once dirname(__DIR__) . '/helpers/TenantLifecyclePolicy.php';
require_once dirname(__DIR__) . '/helpers/RequestContext.php';
require_once dirname(__DIR__) . '/helpers/AuditPolicy.php';
require_once dirname(__DIR__) . '/models/LogModel.php';
require_once __DIR__ . '/AccessEligibilityService.php';
require_once __DIR__ . '/AccessControlRepository.php';
require_once __DIR__ . '/AccessControlSyncService.php';
require_once __DIR__ . '/MockAccessControlProvider.php';

/**
 * Política lógica de acceso, independiente de cualquier fabricante.
 *
 * El tiempo se compara en cada decisión. El job de expiración materializa el
 * estado para informes y sincronización, pero nunca es la barrera de seguridad.
 */
final class AccessPolicyService
{
    public const ALLOWED = 'ALLOWED';
    public const TEMPORARY = 'TEMPORARY';
    public const SUSPENDED = 'SUSPENDED';
    public const DENIED = 'DENIED';
    public const PERMANENT_BLOCK = 'PERMANENT_BLOCK';

    private const REASONS = [
        'TEMPORARY_VISIT', 'TRIAL', 'MANUAL_EXCEPTION',
        'INCIDENT_REVIEW', 'POLICY_REVIEW', 'ADMINISTRATIVE_REVIEW',
        'MEMBERSHIP_REQUIRED', 'PAYMENT_REVIEW', 'POLICY_DENIED',
        'SAFETY_BLOCK', 'FRAUD_BLOCK', 'ADMINISTRATIVE_BLOCK',
        'MANUAL_RESTORE', 'MEMBERSHIP_CONVERTED', 'TEMPORARY_EXPIRED',
    ];

    private PDO $db;
    private int $empresaId;
    private ?int $sedeId;
    private ?int $actorId;
    private string $actorRole;
    private Closure $clock;
    private Closure $baseEligibility;
    private int $receptionMaxDays;
    private ?bool $tenantOperationalCache = null;

    public function __construct(
        PDO $db,
        int $empresaId,
        ?int $sedeId,
        ?int $actorId,
        string $actorRole,
        ?Closure $clock = null,
        ?Closure $baseEligibility = null,
        int $receptionMaxDays = 3
    ) {
        if ($empresaId <= 0) throw new InvalidArgumentException('Empresa de acceso no válida.');
        $this->db = $db;
        $this->empresaId = $empresaId;
        $this->sedeId = $sedeId;
        $this->actorId = $actorId;
        $this->actorRole = strtolower(trim($actorRole));
        $this->clock = $clock ?: static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->baseEligibility = $baseEligibility ?: function (int $memberId): array {
            return (new AccessEligibilityService($this->empresaId, $this->sedeId))->evaluar($memberId);
        };
        $this->receptionMaxDays = max(1, min(31, $receptionMaxDays));
    }

    /** Decisión en tiempo real; nunca depende de que cron haya materializado la caducidad. */
    public function canAccess(int $memberId): array
    {
        $now = $this->now();
        $member = $this->member($memberId, false);
        $base = $member !== null ? ($this->baseEligibility)($memberId) : [];
        return $this->resolve($member, $this->current($memberId), $base, $now);
    }

    /** Resuelve un listado con una sola consulta de políticas (evita N+1). */
    public function evaluateBatch(array $members, array $baseByMember): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(array $member): int => (int)($member['id_usuario'] ?? 0),
            $members
        ))));
        if ($ids === []) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $scopeSql = "SELECT id_usuario,id_empresa,id_gimnasio,activo,rol FROM usuario "
            . "WHERE id_empresa=? AND rol='socio' AND id_usuario IN ({$marks})";
        $scopeParams = array_merge([$this->empresaId], $ids);
        if ($this->sedeId !== null) { $scopeSql .= ' AND id_gimnasio=?'; $scopeParams[]=$this->sedeId; }
        $scopeStmt = $this->db->prepare($scopeSql);
        $scopeStmt->execute($scopeParams);
        $scopedMembers = [];
        foreach ($scopeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $scopedMembers[(int)$row['id_usuario']]=$row;

        $sql = "SELECT * FROM access_policy WHERE id_empresa=? AND id_socio IN ({$marks})";
        $params = array_merge([$this->empresaId], $ids);
        if ($this->sedeId !== null) { $sql .= ' AND id_gimnasio=?'; $params[]=$this->sedeId; }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $policies = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $policies[(int)$row['id_socio']]=$row;
        $now = $this->now();
        $result = [];
        foreach ($members as $member) {
            $id=(int)($member['id_usuario'] ?? 0);
            $scopedMember = $scopedMembers[$id] ?? null;
            $result[$id]=$this->resolve(
                $scopedMember,
                $scopedMember !== null ? ($policies[$id] ?? null) : null,
                $scopedMember !== null ? ($baseByMember[$id] ?? []) : [],
                $now
            );
        }
        return $result;
    }

    public function current(int $memberId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM access_policy WHERE id_empresa=:empresa AND id_socio=:socio'
            . ($this->sedeId !== null ? ' AND id_gimnasio=:sede' : '') . ' LIMIT 1'
        );
        $params = [':empresa'=>$this->empresaId, ':socio'=>$memberId];
        if ($this->sedeId !== null) $params[':sede'] = $this->sedeId;
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function history(int $memberId, int $limit = 50): array
    {
        $this->member($memberId, true);
        $sql = 'SELECT event_id,correlation_id,id_actor,actor_role,origin,action,previous_state,new_state,'
            . 'starts_at_utc,expires_at_utc,reason_code,result,created_at_utc '
            . 'FROM access_policy_event WHERE id_empresa=:empresa AND id_socio=:socio';
        $params = [':empresa'=>$this->empresaId, ':socio'=>$memberId];
        if ($this->sedeId !== null) { $sql .= ' AND id_gimnasio=:sede'; $params[':sede']=$this->sedeId; }
        $sql .= ' ORDER BY id_access_policy_event DESC LIMIT :limit';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key=>$value) $stmt->bindValue($key, $value, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function grantTemporary(
        int $memberId,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $expiresAt,
        string $reasonCode,
        ?string $note,
        string $idempotencyKey,
        ?int $expectedVersion = null
    ): array {
        $this->requirePermission('access.temporary');
        $startsAt = $startsAt->setTimezone(new DateTimeZone('UTC'));
        $expiresAt = $expiresAt->setTimezone(new DateTimeZone('UTC'));
        if ($expiresAt <= $startsAt || $expiresAt <= $this->now()) {
            throw new DomainException('La caducidad temporal debe ser posterior al inicio y al instante actual.');
        }
        $maxDays = $this->actorRole === 'recepcion' ? $this->receptionMaxDays
            : ($this->actorRole === 'admin' ? 90 : 366);
        if ($expiresAt->getTimestamp() - $this->now()->getTimestamp() > $maxDays * 86400) {
            throw new DomainException('La duración temporal supera el máximo autorizado para este rol.');
        }
        return $this->mutate(
            $memberId, self::TEMPORARY, 'ACCESS_TEMPORARY_GRANTED', $reasonCode, $note,
            $idempotencyKey, $startsAt, $expiresAt, null, $expectedVersion
        );
    }

    public function extendTemporary(
        int $memberId,
        DateTimeImmutable $expiresAt,
        string $reasonCode,
        ?string $note,
        string $idempotencyKey,
        ?int $expectedVersion = null
    ): array {
        $this->requirePermission('access.temporary');
        $current = $this->current($memberId);
        if ($current === null || $current['state'] !== self::TEMPORARY) {
            throw new DomainException('Solo puede ampliarse un acceso temporal vigente.');
        }
        $starts = $this->utc((string) ($current['starts_at_utc'] ?: $this->now()->format('Y-m-d H:i:s')));
        return $this->grantTemporary($memberId, $starts, $expiresAt, $reasonCode, $note, $idempotencyKey, $expectedVersion);
    }

    public function suspend(
        int $memberId,
        ?DateTimeImmutable $until,
        string $reasonCode,
        ?string $note,
        string $idempotencyKey,
        ?int $expectedVersion = null
    ): array {
        $this->requirePermission('access.suspend');
        $until = $until?->setTimezone(new DateTimeZone('UTC'));
        if ($until !== null && $until <= $this->now()) throw new DomainException('La suspensión debe terminar en el futuro.');
        return $this->mutate(
            $memberId, self::SUSPENDED, 'ACCESS_SUSPENDED', $reasonCode, $note,
            $idempotencyKey, null, null, $until, $expectedVersion
        );
    }

    public function deny(int $memberId, string $reasonCode, ?string $note, string $idempotencyKey, ?int $expectedVersion = null): array
    {
        $this->requirePermission('access.deny');
        return $this->mutate($memberId, self::DENIED, 'ACCESS_DENIED', $reasonCode, $note, $idempotencyKey, null, null, null, $expectedVersion);
    }

    public function blockPermanently(int $memberId, string $reasonCode, ?string $note, string $idempotencyKey, ?int $expectedVersion = null): array
    {
        $this->requirePermission('access.permanent');
        return $this->mutate($memberId, self::PERMANENT_BLOCK, 'ACCESS_PERMANENTLY_BLOCKED', $reasonCode, $note, $idempotencyKey, null, null, null, $expectedVersion);
    }

    public function restore(int $memberId, string $reasonCode, ?string $note, string $idempotencyKey, ?int $expectedVersion = null): array
    {
        $this->requirePermission('access.restore');
        $current = $this->current($memberId);
        if (($current['state'] ?? null) === self::PERMANENT_BLOCK && $this->actorRole !== 'direccion') {
            throw new DomainException('Solo dirección puede retirar un bloqueo permanente.');
        }
        return $this->mutate($memberId, self::ALLOWED, 'ACCESS_RESTORED', $reasonCode, $note, $idempotencyKey, null, null, null, $expectedVersion);
    }

    /** Materializa expiraciones de una empresa. Es idempotente y seguro frente a extensiones concurrentes. */
    public function expireDue(int $limit = 500): array
    {
        $now = $this->now();
        $stmt = $this->db->prepare(
            "SELECT id_socio FROM access_policy WHERE id_empresa=:empresa AND state='TEMPORARY' "
            . 'AND expires_at_utc<=:now ORDER BY expires_at_utc,id_access_policy LIMIT :limit'
        );
        $stmt->bindValue(':empresa', $this->empresaId, PDO::PARAM_INT);
        $stmt->bindValue(':now', $now->format('Y-m-d H:i:s'));
        $stmt->bindValue(':limit', max(1, min(5000, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $expired = $converted = $skipped = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $memberId) {
            $result = $this->expireOne((int) $memberId, $now);
            $expired += $result === 'EXPIRED' ? 1 : 0;
            $converted += $result === 'CONVERTED' ? 1 : 0;
            $skipped += $result === 'SKIPPED' ? 1 : 0;
        }
        return ['expired'=>$expired, 'converted'=>$converted, 'skipped'=>$skipped];
    }

    public function dashboard(?int $siteId = null): array
    {
        if (!Authorization::can($this->actorRole, 'access.audit')) throw new DomainException('Sin permiso para consultar acceso.');
        $where = 'id_empresa=:empresa';
        $params = [':empresa'=>$this->empresaId];
        $scopeSite = $this->sedeId ?? $siteId;
        if ($scopeSite !== null) { $where .= ' AND id_gimnasio=:sede'; $params[':sede']=$scopeSite; }
        $q = $this->db->prepare("SELECT state,COUNT(*) total FROM access_policy WHERE {$where} GROUP BY state");
        $q->execute($params);
        $states = array_fill_keys([self::ALLOWED,self::TEMPORARY,self::SUSPENDED,self::DENIED,self::PERMANENT_BLOCK], 0);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $states[$row['state']] = (int) $row['total'];
        $now = $this->now();
        $madrid = new DateTimeZone('Europe/Madrid');
        $todayLocal = $now->setTimezone($madrid)->setTime(0, 0);
        $tomorrowLocal = $todayLocal->modify('+1 day');
        $dayAfterLocal = $tomorrowLocal->modify('+1 day');
        $expiry = $this->db->prepare(
            "SELECT "
            . "SUM(expires_at_utc>:expiry_now AND expires_at_utc<=:expiry_72h) expiring_72h, "
            . "SUM(expires_at_utc>=:today_start AND expires_at_utc<:tomorrow_start) expiring_today, "
            . "SUM(expires_at_utc>=:tomorrow_start_2 AND expires_at_utc<:day_after_start) expiring_tomorrow "
            . "FROM access_policy WHERE {$where} AND state='TEMPORARY'"
        );
        $expiry->execute(array_merge($params, [
            ':expiry_now'=>$now->format('Y-m-d H:i:s'),
            ':expiry_72h'=>$now->modify('+72 hours')->format('Y-m-d H:i:s'),
            ':today_start'=>$todayLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ':tomorrow_start'=>$tomorrowLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ':tomorrow_start_2'=>$tomorrowLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ':day_after_start'=>$dayAfterLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]));
        $expiryMetrics = $expiry->fetch(PDO::FETCH_ASSOC) ?: [];
        $jobs = $this->db->prepare("SELECT status,COUNT(*) total FROM access_sync_job WHERE {$where} GROUP BY status");
        $jobs->execute($params);
        $sync = array_fill_keys(['PENDING','PROCESSING','SYNCED','FAILED','RETRY'], 0);
        foreach ($jobs->fetchAll(PDO::FETCH_ASSOC) as $row) $sync[$row['status']] = (int) $row['total'];
        return [
            'states'=>$states,
            'expiring_today'=>(int)($expiryMetrics['expiring_today'] ?? 0),
            'expiring_tomorrow'=>(int)($expiryMetrics['expiring_tomorrow'] ?? 0),
            'expiring_72h'=>(int)($expiryMetrics['expiring_72h'] ?? 0),
            'sync'=>$sync,
        ];
    }

    public function listPolicies(?int $siteId = null, int $limit = 100): array
    {
        if (!Authorization::can($this->actorRole, 'access.view')) throw new DomainException('Sin permiso para consultar acceso.');
        $scopeSite = $this->sedeId ?? $siteId;
        $sql = 'SELECT p.*,u.nombre,u.apellidos FROM access_policy p INNER JOIN usuario u '
            . 'ON u.id_usuario=p.id_socio AND u.id_empresa=p.id_empresa AND u.id_gimnasio=p.id_gimnasio '
            . 'WHERE p.id_empresa=:empresa';
        $params = [':empresa'=>$this->empresaId];
        if ($scopeSite !== null) { $sql.=' AND p.id_gimnasio=:sede'; $params[':sede']=$scopeSite; }
        $sql.=' ORDER BY p.updated_at_utc DESC,p.id_access_policy DESC LIMIT :limit';
        $stmt=$this->db->prepare($sql);
        foreach ($params as $key=>$value) $stmt->bindValue($key,$value,PDO::PARAM_INT);
        $stmt->bindValue(':limit',max(1,min(500,$limit)),PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function mutate(
        int $memberId,
        string $newState,
        string $action,
        string $reasonCode,
        ?string $note,
        string $idempotencyKey,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $suspendedUntil,
        ?int $expectedVersion
    ): array {
        if ($expectedVersion === null || $expectedVersion < 0) {
            throw new DomainException('La versión de política es obligatoria para evitar sobrescrituras concurrentes.');
        }
        $reasonCode = $this->reason($reasonCode);
        $note = $this->note($note);
        $key = $this->idempotency($idempotencyKey);
        $lease = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->empresaId);
        try {
            $this->db->beginTransaction();
            $duplicate = $this->eventByKey($key);
            if ($duplicate !== null) {
                $this->db->commit();
                return ['duplicate'=>true, 'event'=>$duplicate, 'policy'=>$this->current($memberId)];
            }
            $member = $this->member($memberId, true, true);
            if (in_array($newState, [self::ALLOWED,self::TEMPORARY], true) && (int)$member['activo'] !== 1) {
                throw new DomainException('No puede concederse acceso a un socio inactivo.');
            }
            $this->assertActor();
            $current = $this->currentForUpdate($memberId);
            // Un bloqueo permanente ordenado por dirección tiene prioridad
            // sobre una concesión concurrente. El resto sí usa CAS estricto.
            if ($newState !== self::PERMANENT_BLOCK
                && $expectedVersion !== null && (int)($current['version'] ?? 0) !== $expectedVersion) {
                throw new DomainException('La política cambió mientras se editaba. Recarga la ficha.');
            }
            if (($current['state'] ?? null) === self::PERMANENT_BLOCK
                && $newState !== self::PERMANENT_BLOCK && $action !== 'ACCESS_RESTORED') {
                throw new DomainException('El bloqueo permanente prevalece hasta una restauración explícita de dirección.');
            }
            if (($current['state'] ?? null) === self::SUSPENDED
                && $newState === self::TEMPORARY) {
                throw new DomainException('Una suspensión debe restaurarse explícitamente antes de conceder acceso.');
            }
            if ($this->actorRole === 'recepcion' && in_array(($current['state'] ?? null), [self::SUSPENDED,self::DENIED,self::PERMANENT_BLOCK], true)) {
                throw new DomainException('Recepción no puede sobreescribir una denegación o suspensión existente.');
            }
            $now = $this->now();
            $policy = $this->writePolicy(
                $member, $current, $newState, $reasonCode, $note,
                $startsAt, $expiresAt, $suspendedUntil, $now
            );
            $event = $this->insertEvent($policy, $current, $action, $reasonCode, $key, $now, 'SUCCESS');
            $this->writeCommonAudit($memberId, $action, $current, $policy, $reasonCode);
            $this->publishDecision($policy, $action);
            $this->db->commit();
            return ['duplicate'=>false, 'event'=>$event, 'policy'=>$policy];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        } finally {
            $lease->release();
        }
    }

    private function expireOne(int $memberId, DateTimeImmutable $now): string
    {
        $lease = TenantLifecyclePolicy::acquirePlatformTransition($this->db, $this->empresaId);
        try {
            $this->db->beginTransaction();
            $current = $this->currentForUpdate($memberId);
            if ($current === null || $current['state'] !== self::TEMPORARY
                || $this->utc((string)$current['expires_at_utc']) > $now) {
                $this->db->commit();
                return 'SKIPPED';
            }
            $member = $this->member($memberId, true, true);
            $base = ($this->baseEligibility)($memberId);
            $converted = ($base['estado'] ?? '') === 'PERMITIDO';
            $state = $converted ? self::ALLOWED : self::DENIED;
            $reason = $converted ? 'MEMBERSHIP_CONVERTED' : 'TEMPORARY_EXPIRED';
            $action = $converted ? 'ACCESS_TEMPORARY_CONVERTED' : 'ACCESS_EXPIRED';
            $policy = $this->writePolicy($member, $current, $state, $reason, null, null, null, null, $now, true);
            $key = hash('sha256', 'access-expire-v1|' . $this->empresaId . '|' . $current['id_access_policy'] . '|' . $current['version'] . '|' . $current['expires_at_utc']);
            if ($this->eventByKey($key) === null) {
                $this->insertEvent($policy, $current, $action, $reason, $key, $now, 'SUCCESS', 'CRON');
                $this->writeCommonAudit($memberId, $action, $current, $policy, $reason, 'system', 'CRON');
                $this->publishDecision($policy, $action, 'access-policy-expiry');
            }
            $this->db->commit();
            return $converted ? 'CONVERTED' : 'EXPIRED';
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        } finally {
            $lease->release();
        }
    }

    private function writePolicy(
        array $member,
        ?array $current,
        string $state,
        string $reason,
        ?string $note,
        ?DateTimeImmutable $starts,
        ?DateTimeImmutable $expires,
        ?DateTimeImmutable $suspendedUntil,
        DateTimeImmutable $now,
        bool $system = false
    ): array {
        $params = [
            ':empresa'=>$this->empresaId, ':sede'=>(int)$member['id_gimnasio'], ':socio'=>(int)$member['id_usuario'],
            ':state'=>$state, ':starts'=>$starts?->format('Y-m-d H:i:s'), ':expires'=>$expires?->format('Y-m-d H:i:s'),
            ':suspended'=>$suspendedUntil?->format('Y-m-d H:i:s'), ':reason'=>$reason, ':note'=>$note,
            ':actor'=>$system ? null : $this->actorId, ':now'=>$now->format('Y-m-d H:i:s'),
        ];
        if ($current === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO access_policy (id_empresa,id_gimnasio,id_socio,state,starts_at_utc,expires_at_utc,'
                . 'suspended_until_utc,reason_code,reason_note,version,created_by,updated_by,created_at_utc,updated_at_utc) '
                . 'VALUES (:empresa,:sede,:socio,:state,:starts,:expires,:suspended,:reason,:note,1,'
                . ':created_actor,:updated_actor,:created_at,:updated_at)'
            );
            $insertParams=$params;
            unset($insertParams[':actor'],$insertParams[':now']);
            $insertParams[':created_actor']=$system ? null : $this->actorId;
            $insertParams[':updated_actor']=$system ? null : $this->actorId;
            $insertParams[':created_at']=$now->format('Y-m-d H:i:s');
            $insertParams[':updated_at']=$now->format('Y-m-d H:i:s');
            $stmt->execute($insertParams);
            $id = (int)$this->db->lastInsertId();
        } else {
            $id = (int)$current['id_access_policy'];
            $params[':id']=$id; $params[':version']=(int)$current['version'];
            $stmt = $this->db->prepare(
                'UPDATE access_policy SET state=:state,starts_at_utc=:starts,expires_at_utc=:expires,'
                . 'suspended_until_utc=:suspended,reason_code=:reason,reason_note=:note,version=version+1,'
                . 'updated_by=:actor,updated_at_utc=:now WHERE id_access_policy=:id AND id_empresa=:empresa '
                . 'AND id_gimnasio=:sede AND id_socio=:socio AND version=:version'
            );
            $stmt->execute($params);
            if ($stmt->rowCount() !== 1) throw new DomainException('Conflicto concurrente al actualizar la política de acceso.');
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM access_policy WHERE id_access_policy=:id AND id_empresa=:empresa '
            . 'AND id_gimnasio=:sede AND id_socio=:socio LIMIT 1'
        );
        $stmt->execute([
            ':id'=>$id, ':empresa'=>$this->empresaId,
            ':sede'=>(int)$member['id_gimnasio'], ':socio'=>(int)$member['id_usuario'],
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function insertEvent(
        array $policy,
        ?array $previous,
        string $action,
        string $reason,
        string $key,
        DateTimeImmutable $now,
        string $result,
        ?string $origin = null
    ): array {
        $eventId = RequestContext::newId();
        $correlation = RequestContext::correlationId();
        $origin = strtoupper($origin ?: RequestContext::origin());
        if (!in_array($origin, ['WEB','CRON','SYSTEM','API','MOBILE'], true)) $origin='SYSTEM';
        $stmt = $this->db->prepare(
            'INSERT INTO access_policy_event (event_id,correlation_id,id_access_policy,id_empresa,id_gimnasio,id_socio,'
            . 'id_actor,actor_role,origin,action,previous_state,new_state,starts_at_utc,expires_at_utc,reason_code,result,'
            . 'idempotency_key,created_at_utc) VALUES (:event,:correlation,:policy,:empresa,:sede,:socio,:actor,:role,'
            . ':origin,:action,:previous,:new_state,:starts,:expires,:reason,:result,:key,:created)'
        );
        $stmt->execute([
            ':event'=>$eventId, ':correlation'=>$correlation, ':policy'=>$policy['id_access_policy'],
            ':empresa'=>$this->empresaId, ':sede'=>$policy['id_gimnasio'], ':socio'=>$policy['id_socio'],
            ':actor'=>$this->actorId, ':role'=>$this->actorId ? $this->actorRole : 'system', ':origin'=>$origin,
            ':action'=>$action, ':previous'=>$previous['state'] ?? null, ':new_state'=>$policy['state'],
            ':starts'=>$policy['starts_at_utc'], ':expires'=>$policy['expires_at_utc'], ':reason'=>$reason,
            ':result'=>$result, ':key'=>$key, ':created'=>$now->format('Y-m-d H:i:s'),
        ]);
        return ['event_id'=>$eventId, 'correlation_id'=>$correlation, 'action'=>$action, 'result'=>$result];
    }

    private function writeCommonAudit(
        int $memberId,
        string $action,
        ?array $previous,
        array $policy,
        string $reason,
        string $actorType = 'usuario',
        ?string $origin = null
    ): void {
        $ok = (new LogModel($this->empresaId, $this->db))->registrarCambio(
            $this->actorId, $action, 'Cambio de política lógica de acceso', $memberId,
            'access_policy', (int)$policy['id_access_policy'], $previous['state'] ?? null,
            $policy['state'], (int)$policy['id_gimnasio'], 'exito', $reason,
            ['policy_version'=>(int)$policy['version']], $actorType, $origin,
            AuditPolicy::REQUIRED
        );
        if (!$ok) throw new AuditUnavailableException('Required access audit unavailable.');
    }

    private function publishDecision(array $policy, string $action, string $process = 'access-policy-web'): void
    {
        if (!defined('ACCESS_CONTROL_MODE')) return;
        $allowed = in_array($policy['state'], [self::ALLOWED,self::TEMPORARY], true);
        // La frontera física recibe solo la decisión mínima. Motivos internos
        // (incidente, fraude, disciplina o notas) permanecen en auditoría SaaS.
        $providerReason = $allowed ? 'ACCESS_POLICY_ALLOWED' : 'ACCESS_POLICY_DENIED';
        $decision = new AccessDecision(
            $this->empresaId, (int)$policy['id_gimnasio'], (int)$policy['id_socio'],
            $allowed ? AccessDecision::PERMITIDO : AccessDecision::BLOQUEADO,
            $providerReason, null, null,
            'policy-v' . (int)$policy['version']
        );
        $repository = new AccessControlRepository(
            $this->db,
            defined('ACCESS_CONTROL_MAX_ATTEMPTS') ? ACCESS_CONTROL_MAX_ATTEMPTS : 5,
            defined('ACCESS_CONTROL_BACKOFF_SECONDS') ? ACCESS_CONTROL_BACKOFF_SECONDS : 60
        );
        $service = new AccessControlSyncService(
            ACCESS_CONTROL_MODE,
            defined('ACCESS_CONTROL_ACTIVE_CONFIRM') && ACCESS_CONTROL_ACTIVE_CONFIRM,
            new MockAccessControlProvider(),
            $repository
        );
        $service->request($decision, $this->actorId, $process . ':' . strtolower($action));
    }

    private function currentForUpdate(int $memberId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM access_policy WHERE id_empresa=:empresa AND id_gimnasio=:sede AND id_socio=:socio LIMIT 1 FOR UPDATE'
        );
        $member = $this->member($memberId, true);
        $stmt->execute([':empresa'=>$this->empresaId, ':sede'=>$member['id_gimnasio'], ':socio'=>$memberId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function member(int $memberId, bool $throw, bool $forUpdate = false): ?array
    {
        $sql = "SELECT id_usuario,id_empresa,id_gimnasio,activo,rol FROM usuario "
            . "WHERE id_usuario=:socio AND id_empresa=:empresa AND rol='socio'";
        $params = [':socio'=>$memberId, ':empresa'=>$this->empresaId];
        if ($this->sedeId !== null) { $sql .= ' AND id_gimnasio=:sede'; $params[':sede']=$this->sedeId; }
        $sql .= ' LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row === null && $throw) throw new DomainException('Socio fuera del ámbito empresa/sede autorizado.');
        return $row;
    }

    private function assertActor(): void
    {
        if ($this->actorId === null || $this->actorId <= 0) throw new DomainException('La operación requiere actor humano.');
        $stmt = $this->db->prepare(
            'SELECT 1 FROM usuario WHERE id_usuario=:actor AND id_empresa=:empresa AND rol=:role AND activo=1 LIMIT 1'
        );
        $stmt->execute([':actor'=>$this->actorId, ':empresa'=>$this->empresaId, ':role'=>$this->actorRole]);
        if (!$stmt->fetchColumn()) throw new DomainException('Actor fuera del tenant o rol autorizado.');
    }

    private function tenantOperational(): bool
    {
        if ($this->tenantOperationalCache !== null) return $this->tenantOperationalCache;
        $company = TenantLifecyclePolicy::companyState($this->db, $this->empresaId);
        return $this->tenantOperationalCache = $company !== null
            && TenantLifecyclePolicy::allows($company, TenantLifecyclePolicy::WRITE);
    }

    private function requirePermission(string $permission): void
    {
        if (!Authorization::can($this->actorRole, $permission)) throw new DomainException('Sin permiso para esta operación de acceso.');
    }

    private function reason(string $reason): string
    {
        $reason = strtoupper(trim($reason));
        if (!in_array($reason, self::REASONS, true)) throw new InvalidArgumentException('Motivo de acceso no válido.');
        return $reason;
    }

    private function note(?string $note): ?string
    {
        $note = trim((string)$note);
        if ($note === '') return null;
        $note = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $note) ?? '';
        if (mb_strlen($note) > 255) throw new InvalidArgumentException('La nota supera 255 caracteres.');
        return $note;
    }

    private function idempotency(string $key): string
    {
        $key = strtolower(trim($key));
        if (!preg_match('/^[a-f0-9]{32,64}$/', $key)) throw new InvalidArgumentException('Idempotency key no válida.');
        return strlen($key) === 64 ? $key : hash('sha256', $key);
    }

    private function eventByKey(string $key): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT event_id,correlation_id,action,result,id_socio FROM access_policy_event '
            . 'WHERE id_empresa=:empresa AND idempotency_key=:key LIMIT 1'
        );
        $stmt->execute([':empresa'=>$this->empresaId, ':key'=>$key]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function now(): DateTimeImmutable
    {
        $now = ($this->clock)();
        if (!$now instanceof DateTimeImmutable) throw new RuntimeException('El reloj de acceso no devolvió un instante válido.');
        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function decision(
        string $state,
        string $reason,
        ?array $policy,
        DateTimeImmutable $now,
        string $detail = ''
    ): array {
        $provider = $this->providerState($policy);
        return [
            'estado'=>$state,
            'reason_code'=>$reason,
            'motivo'=>$detail !== '' ? $detail : self::message($reason),
            'policy_state'=>$policy['state'] ?? null,
            'starts_at_utc'=>$policy['starts_at_utc'] ?? null,
            'expires_at_utc'=>$policy['expires_at_utc'] ?? null,
            'suspended_until_utc'=>$policy['suspended_until_utc'] ?? null,
            'version'=>(int)($policy['version'] ?? 0),
            'evaluated_at_utc'=>$now->format(DATE_ATOM),
            'solo_logico'=>true,
            'provider_mode'=>$provider['mode'],
            'provider_sync_state'=>$provider['state'],
            'provider_result_code'=>$provider['result'],
        ];
    }

    /** Mantiene separada la política SaaS del estado de su futura réplica física. */
    private function providerState(?array $policy): array
    {
        $mode = defined('ACCESS_CONTROL_MODE')
            ? AccessControlMode::resolve(
                ACCESS_CONTROL_MODE,
                defined('ACCESS_CONTROL_ACTIVE_CONFIRM') && ACCESS_CONTROL_ACTIVE_CONFIRM
            )
            : AccessControlMode::DISABLED;
        if ($mode === AccessControlMode::DISABLED) {
            return ['mode'=>'disabled', 'state'=>'DISABLED', 'result'=>null];
        }
        if ($policy === null || empty($policy['id_gimnasio']) || empty($policy['id_socio'])) {
            return ['mode'=>$mode, 'state'=>'NOT_REQUESTED', 'result'=>null];
        }
        $stmt = $this->db->prepare(
            'SELECT status,provider_result_code,last_error_code FROM access_sync_job '
            . 'WHERE id_empresa=:empresa AND id_gimnasio=:sede AND id_socio=:socio '
            . 'ORDER BY created_at DESC,id_job DESC LIMIT 1'
        );
        $stmt->execute([
            ':empresa'=>$this->empresaId,
            ':sede'=>(int)$policy['id_gimnasio'],
            ':socio'=>(int)$policy['id_socio'],
        ]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($job === null) return ['mode'=>$mode, 'state'=>'NOT_REQUESTED', 'result'=>null];
        $state = (string)$job['status'];
        $result = $job['provider_result_code'] ?: $job['last_error_code'];
        if ($mode === AccessControlMode::SHADOW && $result === 'SHADOW_SIMULATED') {
            $state = 'SHADOW_SIMULATED';
        }
        return ['mode'=>$mode, 'state'=>$state, 'result'=>$result ?: null];
    }

    private function resolve(?array $member, ?array $policy, array $base, DateTimeImmutable $now): array
    {
        if ($member === null) return $this->decision('BLOQUEADO', 'MEMBER_NOT_FOUND_OR_OUT_OF_SCOPE', null, $now);
        if (!$this->tenantOperational()) return $this->decision('BLOQUEADO', 'TENANT_NOT_OPERATIONAL', null, $now);
        if ((int)($member['activo'] ?? 0) !== 1) return $this->decision('BLOQUEADO', 'MEMBER_INACTIVE', $policy, $now);
        if ($policy !== null) {
            if ($policy['state'] === self::PERMANENT_BLOCK) return $this->decision('BLOQUEADO', $policy['reason_code'], $policy, $now);
            if ($policy['state'] === self::SUSPENDED) {
                $reason = !empty($policy['suspended_until_utc']) && $now >= $this->utc((string)$policy['suspended_until_utc'])
                    ? 'SUSPENSION_REVIEW_REQUIRED' : $policy['reason_code'];
                return $this->decision('BLOQUEADO', $reason, $policy, $now);
            }
            if ($policy['state'] === self::DENIED && $policy['reason_code'] !== 'TEMPORARY_EXPIRED') {
                return $this->decision('BLOQUEADO', $policy['reason_code'], $policy, $now);
            }
            if ($policy['state'] === self::TEMPORARY) {
                $starts = !empty($policy['starts_at_utc']) ? $this->utc((string)$policy['starts_at_utc']) : null;
                $expires = $this->utc((string)$policy['expires_at_utc']);
                if ($starts !== null && $now < $starts) return $this->decision('BLOQUEADO', 'TEMPORARY_NOT_STARTED', $policy, $now);
                if ($now < $expires) return $this->decision('PERMITIDO', $policy['reason_code'], $policy, $now);
                if (($base['estado'] ?? '') === 'PERMITIDO') return $this->decision('PERMITIDO', 'MEMBERSHIP_CONVERTED', $policy, $now);
                return $this->decision('BLOQUEADO', 'TEMPORARY_EXPIRED', $policy, $now);
            }
        }
        return $this->decision(
            (string)($base['estado'] ?? 'BLOQUEADO'),
            (string)($base['reason_code'] ?? 'ELIGIBILITY_UNAVAILABLE'),
            $policy,
            $now,
            (string)($base['motivo'] ?? '')
        );
    }

    private static function message(string $reason): string
    {
        return match ($reason) {
            'TEMPORARY_VISIT', 'TRIAL', 'MANUAL_EXCEPTION' => 'Acceso temporal vigente.',
            'TEMPORARY_NOT_STARTED' => 'El acceso temporal todavía no ha comenzado.',
            'TEMPORARY_EXPIRED' => 'El acceso temporal ha caducado.',
            'MEMBERSHIP_CONVERTED' => 'Acceso válido por membresía vigente tras el periodo temporal.',
            'SUSPENSION_REVIEW_REQUIRED' => 'La suspensión necesita una restauración explícita.',
            'TENANT_NOT_OPERATIONAL' => 'La empresa no está operativa.',
            default => 'Acceso decidido por la política vigente.',
        };
    }
}
