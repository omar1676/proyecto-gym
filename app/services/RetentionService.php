<?php

require_once dirname(__DIR__) . '/helpers/AuditPolicy.php';
require_once dirname(__DIR__) . '/helpers/RequestContext.php';
require_once dirname(__DIR__) . '/helpers/RetentionPolicy.php';
require_once dirname(__DIR__) . '/helpers/TenantLifecyclePolicy.php';
require_once dirname(__DIR__) . '/models/LogModel.php';

/** Cálculo, bandeja y workflow del motor Retention V1. */
final class RetentionService
{
    private LogModel $audit;

    public function __construct(private PDO $db, private int $companyId, private ?int $siteId = null)
    {
        if ($companyId <= 0) throw new InvalidArgumentException('Retention exige una empresa válida.');
        $this->audit = new LogModel($companyId, $db);
    }

    /** @return array<string,mixed> */
    public function config(bool $createIfMissing = false): array
    {
        if ($createIfMissing) $this->ensureConfig();
        $stmt = $this->db->prepare('SELECT * FROM retention_config WHERE id_empresa=:company');
        $stmt->execute([':company'=>$this->companyId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$config) {
            $config = RetentionPolicy::defaults();
            $config['id_empresa'] = $this->companyId;
        }
        try {
            new DateTimeZone((string) $config['timezone']);
        } catch (Throwable) {
            throw new DomainException('El timezone de Retention no es válido.');
        }
        foreach (['baseline_days','recent_days','min_history_days','min_baseline_visits','min_baseline_active_weeks','cooldown_days'] as $field) {
            $config[$field] = (int) $config[$field];
        }
        foreach (['min_baseline_weekly_rate','attention_drop_pct','high_attention_drop_pct'] as $field) {
            $config[$field] = (float) $config[$field];
        }
        return $config;
    }

    /** @return array<string,mixed> */
    public function run(?string $evaluationDate = null): array
    {
        $lifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->companyId);
        $jobLock = 'gimnera:retention:' . $this->companyId;
        $lockHeld = false;
        $runId = null;
        try {
            $config = $this->config(true);
            $timezone = new DateTimeZone((string) $config['timezone']);
            $date = $evaluationDate === null
                ? new DateTimeImmutable('today', $timezone)
                : DateTimeImmutable::createFromFormat('!Y-m-d', $evaluationDate, $timezone);
            if (!$date || $date->format('Y-m-d') !== ($evaluationDate ?? $date->format('Y-m-d'))) {
                throw new InvalidArgumentException('Fecha de evaluación no válida.');
            }
            $lock = $this->db->prepare('SELECT GET_LOCK(:name, 1)');
            $lock->execute([':name'=>$jobLock]);
            if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Ya existe un cálculo Retention en curso para esta empresa.');
            $lockHeld = true;

            $existing = $this->findRun($date->format('Y-m-d'));
            if ($existing && $existing['status'] === 'COMPLETED') {
                return $this->runResult($existing, true);
            }
            if ($existing && $existing['status'] === 'RUNNING'
                && strtotime((string)$existing['started_at_utc']) > time() - 900) {
                throw new RuntimeException('La ejecución Retention sigue marcada como activa.');
            }

            $runUuid = $existing['run_id'] ?? RequestContext::newId();
            $started = gmdate('Y-m-d H:i:s');
            if ($existing) {
                $reset = $this->db->prepare(
                    "UPDATE retention_run SET status='RUNNING', error_code=NULL, started_at_utc=:started,
                            finished_at_utc=NULL, evaluated_count=0, insufficient_count=0, normal_count=0,
                            attention_count=0, high_attention_count=0, returned_count=0
                      WHERE id_retention_run=:id"
                );
                $reset->execute([':started'=>$started, ':id'=>$existing['id_retention_run']]);
                $runId = (int)$existing['id_retention_run'];
            } else {
                $insert = $this->db->prepare(
                    'INSERT INTO retention_run (run_id,id_empresa,evaluation_date,algorithm_version,status,started_at_utc)
                     VALUES (:run,:company,:date,:version,\'RUNNING\',:started)'
                );
                $insert->execute([
                    ':run'=>$runUuid, ':company'=>$this->companyId, ':date'=>$date->format('Y-m-d'),
                    ':version'=>RetentionPolicy::ALGORITHM_VERSION, ':started'=>$started,
                ]);
                $runId = (int)$this->db->lastInsertId();
            }

            $this->db->beginTransaction();
            $returned = $this->markReturns($date, $config);
            $windows = RetentionPolicy::windows($date, $config);
            $candidates = $this->candidateStats($windows);
            $counts = ['evaluated'=>0,'insufficient'=>0,'normal'=>0,'attention'=>0,'high_attention'=>0,'returned'=>$returned];
            foreach ($candidates as $candidate) {
                $counts['evaluated']++;
                $classification = RetentionPolicy::classify($candidate, $config, $windows);
                $bucket = match ($classification['state']) {
                    RetentionPolicy::INSUFFICIENT_DATA => 'insufficient',
                    RetentionPolicy::ATTENTION => 'attention',
                    RetentionPolicy::HIGH_ATTENTION => 'high_attention',
                    default => 'normal',
                };
                $counts[$bucket]++;
                if (in_array($classification['state'], [RetentionPolicy::ATTENTION, RetentionPolicy::HIGH_ATTENTION], true)) {
                    $this->createDetection($runId, $date, $candidate, $classification, $config);
                }
            }
            $finish = $this->db->prepare(
                "UPDATE retention_run SET status='COMPLETED', evaluated_count=:evaluated,
                        insufficient_count=:insufficient, normal_count=:normal, attention_count=:attention,
                        high_attention_count=:high_attention, returned_count=:returned,
                        finished_at_utc=:finished WHERE id_retention_run=:id AND status='RUNNING'"
            );
            $finish->execute([
                ':evaluated'=>$counts['evaluated'], ':insufficient'=>$counts['insufficient'], ':normal'=>$counts['normal'],
                ':attention'=>$counts['attention'], ':high_attention'=>$counts['high_attention'], ':returned'=>$counts['returned'],
                ':finished'=>gmdate('Y-m-d H:i:s'), ':id'=>$runId,
            ]);
            if ($finish->rowCount() !== 1) throw new RuntimeException('La ejecución Retention perdió su estado.');
            $this->db->commit();
            return $this->runResult($this->findRun($date->format('Y-m-d')) ?: [], false);
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($runId !== null) {
                $failed = $this->db->prepare(
                    "UPDATE retention_run SET status='FAILED', error_code='RETENTION_RUN_FAILED', finished_at_utc=:finished
                      WHERE id_retention_run=:id AND status='RUNNING'"
                );
                $failed->execute([':finished'=>gmdate('Y-m-d H:i:s'), ':id'=>$runId]);
            }
            throw $error;
        } finally {
            if ($lockHeld) {
                try {
                    $release = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
                    $release->execute([':name'=>$jobLock]);
                } catch (Throwable) {
                }
            }
            $lifecycle->release();
        }
    }

    /** @return list<array<string,mixed>> */
    public function inbox(int $limit = 100): array
    {
        $limit = max(1, min(250, $limit));
        $config = $this->config();
        $tenantToday = (new DateTimeImmutable('today', new DateTimeZone((string)$config['timezone'])))->format('Y-m-d');
        $sql = "SELECT d.*,u.nombre,u.apellidos,g.nombre AS sede_nombre,e.nombre_comercial,e.nombre AS empresa_nombre
                  FROM retention_detection d
                  JOIN usuario u ON u.id_usuario=d.id_socio AND u.id_empresa=d.id_empresa
                  JOIN gimnasio g ON g.id_gimnasio=d.id_gimnasio AND g.id_empresa=d.id_empresa
                  JOIN empresa e ON e.id_empresa=d.id_empresa
                 WHERE d.id_empresa=:company
                   AND d.status IN ('OPEN','REVIEWED','POSTPONED','CONTACTED')
                   AND (d.status <> 'POSTPONED' OR d.next_review_at IS NULL OR d.next_review_at <= :tenant_today)";
        $params = [':company'=>$this->companyId, ':tenant_today'=>$tenantToday];
        if ($this->siteId !== null) {
            $sql .= ' AND d.id_gimnasio=:site';
            $params[':site'] = $this->siteId;
        }
        $sql .= " ORDER BY FIELD(d.level,'HIGH_ATTENTION','ATTENTION'), d.drop_pct DESC, d.detected_at_utc ASC LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key=>$value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['explanation'] = sprintf(
                'Frecuencia habitual: %.2f visitas/semana. Últimos %d días: %d visita%s (%.2f/semana). Caída aproximada: %.0f%%.',
                (float)$row['baseline_weekly_rate'], (int)$config['recent_days'], (int)$row['recent_visits'],
                (int)$row['recent_visits'] === 1 ? '' : 's', (float)$row['recent_weekly_rate'], (float)$row['drop_pct']
            );
            $row['suggested_message'] = RetentionPolicy::suggestedMessage(
                $config, (string)$row['activity_family'], (string)$row['nombre'],
                (string)($row['nombre_comercial'] ?: $row['empresa_nombre'])
            );
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,int|null> */
    public function metrics(): array
    {
        $sql = "SELECT SUM(status='OPEN' OR status='REVIEWED' OR status='POSTPONED' OR status='CONTACTED') total,
                       SUM(status='REVIEWED') reviewed,
                       SUM(status='DISMISSED') dismissed,
                       SUM(status='CONTACTED') contacted,
                       SUM(status='RETURNED') returned
                  FROM retention_detection WHERE id_empresa=:company";
        $params = [':company'=>$this->companyId];
        if ($this->siteId !== null) {
            $sql .= ' AND id_gimnasio=:site';
            $params[':site'] = $this->siteId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $result = [
            'total'=>(int)($row['total'] ?? 0), 'reviewed'=>(int)($row['reviewed'] ?? 0),
            'dismissed'=>(int)($row['dismissed'] ?? 0), 'contacted'=>(int)($row['contacted'] ?? 0),
            'returned'=>(int)($row['returned'] ?? 0),
        ];
        $result += ['evaluated'=>null,'insufficient'=>null,'normal'=>null,'attention'=>null,'high_attention'=>null];
        if ($this->siteId === null) {
            $last = $this->db->prepare(
                "SELECT evaluated_count,insufficient_count,normal_count,attention_count,high_attention_count
                   FROM retention_run WHERE id_empresa=:company AND status='COMPLETED'
                   ORDER BY evaluation_date DESC,id_retention_run DESC LIMIT 1"
            );
            $last->execute([':company'=>$this->companyId]);
            $run = $last->fetch(PDO::FETCH_ASSOC);
            if ($run) {
                $result['evaluated'] = (int)$run['evaluated_count'];
                $result['insufficient'] = (int)$run['insufficient_count'];
                $result['normal'] = (int)$run['normal_count'];
                $result['attention'] = (int)$run['attention_count'];
                $result['high_attention'] = (int)$run['high_attention_count'];
            }
        }
        return $result;
    }

    public function act(int $detectionId, int $actorId, string $action, string $idempotencyKey, int $version, ?string $reason = null, int $postponeDays = 7): bool
    {
        $actions = ['REVIEW','DISMISS','POSTPONE','CONTACT_MANUAL'];
        $action = strtoupper(trim($action));
        if (!in_array($action, $actions, true) || $detectionId <= 0 || $actorId <= 0 || $version <= 0) {
            throw new InvalidArgumentException('Acción Retention no válida.');
        }
        if (!preg_match('/^[a-f0-9-]{36}$/i', $idempotencyKey)) throw new InvalidArgumentException('Clave idempotente no válida.');
        $reason = $reason !== null ? trim($reason) : null;
        if ($reason === '') $reason = null;
        if ($reason !== null && mb_strlen($reason) > 255) throw new InvalidArgumentException('El motivo es demasiado largo.');
        $postponeDays = max(1, min(90, $postponeDays));
        $hashedKey = hash('sha256', $this->companyId . '|retention-action|' . strtolower($idempotencyKey));
        $lifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->companyId);
        try {
            $this->db->beginTransaction();
            $actorSql = "SELECT id_usuario FROM usuario
                          WHERE id_usuario=:actor AND id_empresa=:company AND activo=1
                            AND rol='direccion'";
            $actorParams = [':actor'=>$actorId, ':company'=>$this->companyId];
            if ($this->siteId !== null) {
                $actorSql = "SELECT id_usuario FROM usuario
                              WHERE id_usuario=:actor AND id_empresa=:company AND activo=1
                                AND (rol='direccion' OR (rol='admin' AND id_gimnasio=:actor_site))";
                $actorParams[':actor_site'] = $this->siteId;
            }
            $actor = $this->db->prepare($actorSql . ' FOR UPDATE');
            $actor->execute($actorParams);
            if (!$actor->fetchColumn()) throw new DomainException('El actor no pertenece al contexto autorizado de Retention.');
            $duplicate = $this->db->prepare('SELECT action,id_retention_detection FROM retention_action WHERE id_empresa=:company AND idempotency_key=:key');
            $duplicate->execute([':company'=>$this->companyId, ':key'=>$hashedKey]);
            $existing = $duplicate->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['action'] !== $action || (int)$existing['id_retention_detection'] !== $detectionId) {
                    throw new DomainException('La clave idempotente ya se utilizó para otra acción.');
                }
                $this->db->commit();
                return true;
            }
            $sql = 'SELECT * FROM retention_detection WHERE id_retention_detection=:id AND id_empresa=:company';
            $params = [':id'=>$detectionId, ':company'=>$this->companyId];
            if ($this->siteId !== null) {
                $sql .= ' AND id_gimnasio=:site';
                $params[':site'] = $this->siteId;
            }
            $sql .= ' FOR UPDATE';
            $select = $this->db->prepare($sql);
            $select->execute($params);
            $detection = $select->fetch(PDO::FETCH_ASSOC);
            if (!$detection) throw new DomainException('La detección no pertenece al contexto autorizado.');
            if ((int)$detection['version'] !== $version) throw new DomainException('La detección cambió; recarga antes de continuar.');
            if (in_array($detection['status'], ['DISMISSED','RETURNED'], true)) throw new DomainException('La detección ya está cerrada.');

            $status = match ($action) {
                'REVIEW' => 'REVIEWED', 'DISMISS' => 'DISMISSED', 'POSTPONE' => 'POSTPONED',
                'CONTACT_MANUAL' => 'CONTACTED',
            };
            $actionConfig = $this->config();
            $tenantToday = new DateTimeImmutable('today', new DateTimeZone((string)$actionConfig['timezone']));
            $nextReview = $action === 'POSTPONE'
                ? $tenantToday->modify('+' . $postponeDays . ' days')->format('Y-m-d')
                : null;
            $contacted = $action === 'CONTACT_MANUAL' ? gmdate('Y-m-d H:i:s') : $detection['contacted_at_utc'];
            $update = $this->db->prepare(
                'UPDATE retention_detection SET status=:status,next_review_at=:next_review,contacted_at_utc=:contacted,version=version+1
                  WHERE id_retention_detection=:id AND id_empresa=:company AND version=:version'
            );
            $update->execute([
                ':status'=>$status, ':next_review'=>$nextReview, ':contacted'=>$contacted,
                ':id'=>$detectionId, ':company'=>$this->companyId, ':version'=>$version,
            ]);
            if ($update->rowCount() !== 1) throw new DomainException('La detección fue modificada por otra sesión.');
            $insert = $this->db->prepare(
                'INSERT INTO retention_action
                 (action_id,id_retention_detection,id_empresa,id_gimnasio,id_socio,id_actor,action,reason,idempotency_key,created_at_utc)
                 VALUES (:action_id,:detection,:company,:site,:member,:actor,:action,:reason,:key,:created)'
            );
            $insert->execute([
                ':action_id'=>RequestContext::newId(), ':detection'=>$detectionId, ':company'=>$this->companyId,
                ':site'=>$detection['id_gimnasio'], ':member'=>$detection['id_socio'], ':actor'=>$actorId,
                ':action'=>$action, ':reason'=>$reason, ':key'=>$hashedKey, ':created'=>gmdate('Y-m-d H:i:s'),
            ]);
            $auditAction = match ($action) {
                'REVIEW'=>'RETENTION_REVIEWED', 'DISMISS'=>'RETENTION_DISMISSED',
                'POSTPONE'=>'RETENTION_POSTPONED', 'CONTACT_MANUAL'=>'RETENTION_CONTACTED_MANUAL',
            };
            $this->audit->registrarCambio(
                $actorId, $auditAction, 'Acción manual sobre detección Retention', (int)$detection['id_socio'],
                'retention_detection', $detectionId, (string)$detection['status'], $status,
                (int)$detection['id_gimnasio'], 'exito', $auditAction, [], 'usuario', 'WEB', AuditPolicy::REQUIRED
            );
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        } finally {
            $lifecycle->release();
        }
    }

    private function ensureConfig(): void
    {
        $defaults = RetentionPolicy::defaults();
        $stmt = $this->db->prepare(
            'INSERT INTO retention_config
             (id_empresa,timezone,baseline_days,recent_days,min_history_days,min_baseline_visits,min_baseline_active_weeks,
              min_baseline_weekly_rate,attention_drop_pct,high_attention_drop_pct,cooldown_days,
              template_general,template_gym,template_boxeo,template_tatami)
             SELECT :company,:timezone,:baseline,:recent,:history,:visits,:active_weeks,:min_rate,:attention,:high,:cooldown,
                    :general,:gym,:boxeo,:tatami
               FROM empresa WHERE id_empresa=:company_exists
             ON DUPLICATE KEY UPDATE id_empresa=VALUES(id_empresa)'
        );
        $stmt->execute([
            ':company'=>$this->companyId, ':timezone'=>$defaults['timezone'], ':baseline'=>$defaults['baseline_days'],
            ':recent'=>$defaults['recent_days'], ':history'=>$defaults['min_history_days'], ':visits'=>$defaults['min_baseline_visits'],
            ':active_weeks'=>$defaults['min_baseline_active_weeks'], ':min_rate'=>$defaults['min_baseline_weekly_rate'],
            ':attention'=>$defaults['attention_drop_pct'], ':high'=>$defaults['high_attention_drop_pct'], ':cooldown'=>$defaults['cooldown_days'],
            ':general'=>$defaults['template_general'], ':gym'=>$defaults['template_gym'], ':boxeo'=>$defaults['template_boxeo'],
            ':tatami'=>$defaults['template_tatami'], ':company_exists'=>$this->companyId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function candidateStats(array $windows): array
    {
        // Separar elegibilidad y agregación evita que MariaDB materialice el
        // producto membresías × asistencias antes del GROUP BY. Con volumen
        // realista esa estrategia podía convertir 10.000 eventos en minutos.
        $eligible = $this->db->prepare(
            "SELECT u.id_usuario,u.id_gimnasio,u.nombre,u.apellidos,
                    GROUP_CONCAT(DISTINCT COALESCE(tm.nombre,sm.nombre_tipo) SEPARATOR '||') membership_names,
                    GROUP_CONCAT(DISTINCT ram.activity_family ORDER BY ram.activity_family) mapped_families
               FROM usuario u
               JOIN socio_membresia sm ON sm.id_socio=u.id_usuario
               LEFT JOIN tipo_membresia tm
                 ON tm.id_tipo_membresia=sm.id_tipo_membresia AND tm.id_empresa=u.id_empresa
               LEFT JOIN retention_activity_mapping ram
                 ON ram.id_empresa=u.id_empresa AND ram.id_tipo_membresia=tm.id_tipo_membresia
              WHERE u.id_empresa=:company AND u.rol='socio' AND u.activo=1 AND u.anonimizado_en IS NULL
                AND sm.fecha_inicio <= :membership_date AND sm.fecha_fin >= :membership_date_again
              GROUP BY u.id_usuario,u.id_gimnasio,u.nombre,u.apellidos
              ORDER BY u.id_usuario"
        );
        $eligible->execute([
            ':company'=>$this->companyId,
            ':membership_date'=>$windows['recent_end'],
            ':membership_date_again'=>$windows['recent_end'],
        ]);
        $candidates = $eligible->fetchAll(PDO::FETCH_ASSOC);
        if ($candidates === []) return [];

        $attendance = $this->db->prepare(
            "SELECT id_socio,
                    MIN(CASE WHEN local_date <= :baseline_end_first THEN local_date END) first_historical_date,
                    COUNT(DISTINCT CASE WHEN local_date BETWEEN :baseline_start AND :baseline_end THEN local_date END) baseline_visits,
                    COUNT(DISTINCT CASE WHEN local_date BETWEEN :recent_start AND :recent_end THEN local_date END) recent_visits,
                    COUNT(DISTINCT CASE WHEN local_date BETWEEN :baseline_start_week AND :baseline_end_week
                         THEN FLOOR(DATEDIFF(local_date,:baseline_start_anchor)/7) END) baseline_active_weeks,
                    MAX(occurred_at_utc) last_attendance_utc
               FROM attendance_event
              WHERE id_empresa=:company AND local_date <= :recent_end_events
              GROUP BY id_socio"
        );
        $attendance->execute([
            ':baseline_end_first'=>$windows['baseline_end'], ':baseline_start'=>$windows['baseline_start'],
            ':baseline_end'=>$windows['baseline_end'], ':recent_start'=>$windows['recent_start'],
            ':recent_end'=>$windows['recent_end'], ':baseline_start_week'=>$windows['baseline_start'],
            ':baseline_end_week'=>$windows['baseline_end'], ':baseline_start_anchor'=>$windows['baseline_start'],
            ':company'=>$this->companyId, ':recent_end_events'=>$windows['recent_end'],
        ]);
        $byMember = [];
        foreach ($attendance->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byMember[(int)$row['id_socio']] = $row;
        }
        foreach ($candidates as &$candidate) {
            $stats = $byMember[(int)$candidate['id_usuario']] ?? [];
            $candidate += [
                'first_historical_date'=>$stats['first_historical_date'] ?? null,
                'baseline_visits'=>(int)($stats['baseline_visits'] ?? 0),
                'recent_visits'=>(int)($stats['recent_visits'] ?? 0),
                'baseline_active_weeks'=>(int)($stats['baseline_active_weeks'] ?? 0),
                'last_attendance_utc'=>$stats['last_attendance_utc'] ?? null,
            ];
        }
        unset($candidate);
        return $candidates;
    }

    private function createDetection(int $runId, DateTimeImmutable $date, array $candidate, array $classification, array $config): void
    {
        $cooldown = $this->db->prepare(
            'SELECT 1 FROM retention_detection
              WHERE id_empresa=:company AND id_socio=:member AND cooldown_until >= :date LIMIT 1'
        );
        $cooldown->execute([':company'=>$this->companyId, ':member'=>$candidate['id_usuario'], ':date'=>$date->format('Y-m-d')]);
        if ($cooldown->fetchColumn()) return;
        $family = RetentionPolicy::activityFamily($candidate['mapped_families'] ?? null, $candidate['membership_names'] ?? null);
        $detectionId = RequestContext::newId();
        $insert = $this->db->prepare(
            'INSERT IGNORE INTO retention_detection
             (detection_id,id_empresa,id_gimnasio,id_socio,id_retention_run,evaluation_date,level,activity_family,
              baseline_visits,recent_visits,baseline_weekly_rate,recent_weekly_rate,drop_pct,last_attendance_utc,
              detected_at_utc,cooldown_until)
             VALUES (:uuid,:company,:site,:member,:run,:date,:level,:family,:baseline_visits,:recent_visits,
                     :baseline_rate,:recent_rate,:drop,:last_attendance,:detected,:cooldown)'
        );
        $insert->execute([
            ':uuid'=>$detectionId, ':company'=>$this->companyId, ':site'=>$candidate['id_gimnasio'], ':member'=>$candidate['id_usuario'],
            ':run'=>$runId, ':date'=>$date->format('Y-m-d'), ':level'=>$classification['state'], ':family'=>$family,
            ':baseline_visits'=>$candidate['baseline_visits'], ':recent_visits'=>$candidate['recent_visits'],
            ':baseline_rate'=>number_format((float)$classification['baseline_rate'],2,'.',''),
            ':recent_rate'=>number_format((float)$classification['recent_rate'],2,'.',''),
            ':drop'=>number_format((float)$classification['drop_pct'],2,'.',''), ':last_attendance'=>$candidate['last_attendance_utc'],
            ':detected'=>gmdate('Y-m-d H:i:s'), ':cooldown'=>$date->modify('+' . (int)$config['cooldown_days'] . ' days')->format('Y-m-d'),
        ]);
        if ($insert->rowCount() !== 1) return;
        $id = (int)$this->db->lastInsertId();
        $this->audit->registrarCambio(
            null, 'RETENTION_DETECTED', 'Caída de asistencia detectada por reglas V1', (int)$candidate['id_usuario'],
            'retention_detection', $id, null, (string)$classification['state'], (int)$candidate['id_gimnasio'],
            'exito', (string)$classification['reason_code'], [
                'baseline_visits'=>(int)$candidate['baseline_visits'], 'recent_visits'=>(int)$candidate['recent_visits'],
                'drop_pct'=>(float)$classification['drop_pct'], 'algorithm'=>RetentionPolicy::ALGORITHM_VERSION,
            ], 'system', 'CRON', AuditPolicy::REQUIRED
        );
    }

    private function markReturns(DateTimeImmutable $date, array $config): int
    {
        $stmt = $this->db->prepare(
            "SELECT d.*,
                    (SELECT MIN(a.occurred_at_utc) FROM attendance_event a
                      WHERE a.id_empresa=d.id_empresa AND a.id_socio=d.id_socio
                        AND a.local_date > d.evaluation_date) AS return_at
               FROM retention_detection d
              WHERE d.id_empresa=:company AND d.status IN ('OPEN','REVIEWED','POSTPONED','CONTACTED')
              FOR UPDATE"
        );
        $stmt->execute([':company'=>$this->companyId]);
        $returned = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $detection) {
            if (empty($detection['return_at'])) continue;
            $tenantTimezone = new DateTimeZone((string)$config['timezone']);
            $base = new DateTimeImmutable((string)$detection['evaluation_date'], $tenantTimezone);
            $returnAt = new DateTimeImmutable((string)$detection['return_at'], new DateTimeZone('UTC'));
            $returnDay = new DateTimeImmutable($returnAt->setTimezone($tenantTimezone)->format('Y-m-d'), $tenantTimezone);
            $days = min(65535, (int)$base->diff($returnDay)->days);
            $update = $this->db->prepare(
                "UPDATE retention_detection SET status='RETURNED',returned_at_utc=:returned,days_to_return=:days,version=version+1
                  WHERE id_retention_detection=:id AND status IN ('OPEN','REVIEWED','POSTPONED','CONTACTED')"
            );
            $update->execute([':returned'=>$returnAt->format('Y-m-d H:i:s'), ':days'=>$days, ':id'=>$detection['id_retention_detection']]);
            if ($update->rowCount() !== 1) continue;
            $key = hash('sha256', $this->companyId . '|return|' . $detection['id_retention_detection'] . '|' . $returnAt->format('c'));
            $action = $this->db->prepare(
                "INSERT IGNORE INTO retention_action
                 (action_id,id_retention_detection,id_empresa,id_gimnasio,id_socio,id_actor,action,reason,idempotency_key,created_at_utc)
                 VALUES (:uuid,:detection,:company,:site,:member,NULL,'RETURN_AUTO',NULL,:key,:created)"
            );
            $action->execute([
                ':uuid'=>RequestContext::newId(), ':detection'=>$detection['id_retention_detection'], ':company'=>$this->companyId,
                ':site'=>$detection['id_gimnasio'], ':member'=>$detection['id_socio'], ':key'=>$key, ':created'=>gmdate('Y-m-d H:i:s'),
            ]);
            $this->audit->registrarCambio(
                null, 'RETENTION_RETURNED', 'Regreso detectado mediante asistencia posterior', (int)$detection['id_socio'],
                'retention_detection', (int)$detection['id_retention_detection'], (string)$detection['status'], 'RETURNED',
                (int)$detection['id_gimnasio'], 'exito', 'RETENTION_ATTENDANCE_RETURNED', ['days_to_return'=>$days],
                'system', 'CRON', AuditPolicy::REQUIRED
            );
            $returned++;
        }
        return $returned;
    }

    private function findRun(string $date): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM retention_run WHERE id_empresa=:company AND evaluation_date=:date AND algorithm_version=:version LIMIT 1'
        );
        $stmt->execute([':company'=>$this->companyId, ':date'=>$date, ':version'=>RetentionPolicy::ALGORITHM_VERSION]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string,mixed> */
    private function runResult(array $row, bool $reused): array
    {
        return [
            'run_id'=>(string)($row['run_id'] ?? ''), 'reused'=>$reused,
            'evaluated'=>(int)($row['evaluated_count'] ?? 0), 'insufficient'=>(int)($row['insufficient_count'] ?? 0),
            'normal'=>(int)($row['normal_count'] ?? 0), 'attention'=>(int)($row['attention_count'] ?? 0),
            'high_attention'=>(int)($row['high_attention_count'] ?? 0), 'returned'=>(int)($row['returned_count'] ?? 0),
        ];
    }
}
