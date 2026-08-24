<?php

require_once dirname(__DIR__) . '/helpers/AuditPolicy.php';
require_once dirname(__DIR__) . '/helpers/RequestContext.php';
require_once dirname(__DIR__) . '/helpers/RetentionPolicy.php';
require_once dirname(__DIR__) . '/helpers/RetentionPresentation.php';
require_once dirname(__DIR__) . '/helpers/TenantLifecyclePolicy.php';
require_once dirname(__DIR__) . '/models/LogModel.php';

/** Cálculo, bandeja y workflow del motor Retention V1. */
final class RetentionService
{
    private LogModel $audit;
    private ?string $companyNameCache = null;

    public function __construct(private PDO $db, private int $companyId, private ?int $siteId = null)
    {
        if ($companyId <= 0) throw new InvalidArgumentException('Retention exige una empresa válida.');
        if ($siteId !== null) {
            $site = $this->db->prepare('SELECT 1 FROM gimnasio WHERE id_gimnasio=:site AND id_empresa=:company AND activo=1');
            $site->execute([':site'=>$siteId, ':company'=>$companyId]);
            if (!$site->fetchColumn()) throw new DomainException('La sede no pertenece al contexto autorizado.');
        }
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
            if ($existing && $existing['status'] === 'COMPLETED' && $this->snapshotComplete($existing)) {
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
            $deleteSnapshots = $this->db->prepare('DELETE FROM retention_member_snapshot WHERE id_retention_run=:run');
            $deleteSnapshots->execute([':run'=>$runId]);
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
                $this->storeSnapshot($runId, $date, $candidate, $classification);
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
            $row['display_state'] = (string)$row['level'];
            $row['state_label'] = RetentionPresentation::label((string)$row['level']);
            $row['activity_label'] = RetentionPresentation::activity((string)$row['activity_family']);
            $row['workflow_label'] = RetentionPresentation::workflow((string)$row['status']);
            $row['explanation'] = RetentionPresentation::explanation($row, (int)$config['recent_days']);
            $row['last_attendance_label'] = RetentionPresentation::relativeDate(
                $row['last_attendance_utc'] ?: null, (string)$config['timezone']
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
        $runId = $this->latestRunId();
        $fields=['total','reviewed','dismissed','contacted','returned','evaluated','insufficient','normal','attention','high_attention'];
        $result=array_fill_keys($fields,0);
        if ($runId === null) return $result;
        $sql="SELECT COUNT(*) evaluated,
                    SUM(s.state='INSUFFICIENT_DATA') insufficient,SUM(s.state='NORMAL') normal,
                    SUM(s.state='ATTENTION') attention,SUM(s.state='HIGH_ATTENTION') high_attention,
                    SUM(s.state IN ('ATTENTION','HIGH_ATTENTION') AND d.status IN ('OPEN','REVIEWED','POSTPONED','CONTACTED')) total,
                    SUM(d.status='REVIEWED') reviewed,SUM(d.status='DISMISSED') dismissed,
                    SUM(d.status='CONTACTED') contacted,SUM(d.status='RETURNED') returned
                FROM retention_member_snapshot s
                LEFT JOIN (
                    SELECT id_empresa,id_socio,MAX(id_retention_detection) id_retention_detection
                      FROM retention_detection WHERE id_empresa=:detection_company GROUP BY id_empresa,id_socio
                ) latest ON latest.id_empresa=s.id_empresa AND latest.id_socio=s.id_socio
                LEFT JOIN retention_detection d ON d.id_retention_detection=latest.id_retention_detection
               WHERE s.id_empresa=:company AND s.id_retention_run=:run";
        $params=[':detection_company'=>$this->companyId,':company'=>$this->companyId,':run'=>$runId];
        if($this->siteId!==null){$sql.=' AND s.id_gimnasio=:site';$params[':site']=$this->siteId;}
        $stmt=$this->db->prepare($sql);$stmt->execute($params);$row=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
        foreach($fields as $field)$result[$field]=(int)($row[$field]??0);
        return $result;
    }

    /** @return list<array{id_gimnasio:int,nombre:string}> */
    public function sites(): array
    {
        $stmt = $this->db->prepare(
            'SELECT id_gimnasio,nombre FROM gimnasio WHERE id_empresa=:company AND activo=1 ORDER BY nombre,id_gimnasio'
        );
        $stmt->execute([':company'=>$this->companyId]);
        return array_map(static fn(array $row): array => [
            'id_gimnasio'=>(int)$row['id_gimnasio'], 'nombre'=>(string)$row['nombre'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{items:list<array<string,mixed>>,pagination:array<string,int>} */
    public function cases(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        [$page,$perPage,$offset] = $this->pagination($page,$perPage);
        $runId = $this->latestRunId();
        if ($runId === null) return ['items'=>[], 'pagination'=>$this->paginationResult(0,$page,$perPage)];
        $state = $this->filterValue((string)($filters['state'] ?? 'attention'), [
            'attention','high','partial','returned','normal','insufficient','all',
        ], 'attention');
        $activity = $this->filterValue(strtoupper((string)($filters['activity'] ?? '')), ['GYM','BOXEO','TATAMI','GENERAL'], '');
        $workflow = $this->filterValue(strtolower((string)($filters['workflow'] ?? '')), [
            'pending','reviewed','postponed','contacted','dismissed','returned',
        ], '');

        $params = [':company'=>$this->companyId, ':run'=>$runId, ':detection_company'=>$this->companyId];
        $where = ['s.id_empresa=:company', 's.id_retention_run=:run'];
        if ($this->siteId !== null) {
            $where[] = 's.id_gimnasio=:site';
            $params[':site'] = $this->siteId;
        }
        $where[] = match ($state) {
            'attention' => "s.state IN ('ATTENTION','HIGH_ATTENTION') AND d.status IN ('OPEN','REVIEWED','POSTPONED','CONTACTED')",
            'high' => "s.state='HIGH_ATTENTION' AND d.status IN ('OPEN','REVIEWED','POSTPONED','CONTACTED')",
            'partial' => "s.state='ATTENTION' AND d.status IN ('OPEN','REVIEWED','POSTPONED','CONTACTED')",
            'returned' => "d.status='RETURNED'",
            'normal' => "s.state='NORMAL'",
            'insufficient' => "s.state='INSUFFICIENT_DATA'",
            default => '1=1',
        };
        if ($activity !== '') {
            $where[] = 's.activity_family=:activity';
            $params[':activity'] = $activity;
        }
        if ($workflow !== '') {
            $workflowStatus = match ($workflow) {
                'pending'=>'OPEN','reviewed'=>'REVIEWED','postponed'=>'POSTPONED',
                'contacted'=>'CONTACTED','dismissed'=>'DISMISSED','returned'=>'RETURNED',
            };
            $where[] = 'd.status=:workflow';
            $params[':workflow'] = $workflowStatus;
        }
        $detectionJoin = "LEFT JOIN (
                SELECT id_empresa,id_socio,MAX(id_retention_detection) id_retention_detection
                  FROM retention_detection WHERE id_empresa=:detection_company GROUP BY id_empresa,id_socio
            ) latest_detection ON latest_detection.id_empresa=s.id_empresa AND latest_detection.id_socio=s.id_socio
            LEFT JOIN retention_detection d ON d.id_retention_detection=latest_detection.id_retention_detection";
        $from = "FROM retention_member_snapshot s
            {$detectionJoin}
            JOIN usuario u ON u.id_usuario=s.id_socio AND u.id_empresa=s.id_empresa
            JOIN gimnasio g ON g.id_gimnasio=s.id_gimnasio AND g.id_empresa=s.id_empresa";
        $whereSql = implode(' AND ', $where);
        $count = $this->db->prepare("SELECT COUNT(*) {$from} WHERE {$whereSql}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $sql = "SELECT s.*,u.nombre,u.apellidos,g.nombre sede_nombre,
                       d.id_retention_detection,d.status workflow_status,d.version,d.detected_at_utc,
                       d.contacted_at_utc,d.returned_at_utc,d.days_to_return,d.next_review_at,
                       CASE WHEN d.status='RETURNED' THEN 'RETURNED' ELSE s.state END display_state
                  {$from} WHERE {$whereSql}
                 ORDER BY FIELD(CASE WHEN d.status='RETURNED' THEN 'RETURNED' ELSE s.state END,
                                'HIGH_ATTENTION','ATTENTION','RETURNED','NORMAL','INSUFFICIENT_DATA'),
                          s.drop_pct DESC,COALESCE(d.detected_at_utc,s.created_at_utc) ASC,s.id_socio
                 LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key=>$value) $stmt->bindValue($key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        $stmt->bindValue(':limit',$perPage,PDO::PARAM_INT);
        $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
        $stmt->execute();
        return [
            'items'=>$this->decorateCases($stmt->fetchAll(PDO::FETCH_ASSOC)),
            'pagination'=>$this->paginationResult($total,$page,$perPage),
        ];
    }

    /** @return array{items:list<array<string,mixed>>,pagination:array<string,int>,query:string} */
    public function search(string $query, int $page = 1, int $perPage = 20): array
    {
        [$page,$perPage,$offset] = $this->pagination($page,$perPage);
        $query = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $query) ?? '');
        if (mb_strlen($query) > 100) $query = mb_substr($query,0,100);
        if ($query === '') return ['items'=>[], 'pagination'=>$this->paginationResult(0,$page,$perPage), 'query'=>''];
        $needle = mb_strtolower($query,'UTF-8');
        $runId = $this->latestRunId() ?? 0;
        $params = [
            ':company'=>$this->companyId, ':run'=>$runId, ':needle'=>$needle,
            ':detection_company'=>$this->companyId,
        ];
        $scope = "u.id_empresa=:company AND u.rol='socio' AND u.activo=1 AND u.anonimizado_en IS NULL
                  AND LOCATE(:needle,LOWER(CONCAT_WS(' ',u.nombre,u.apellidos,COALESCE(u.telefono,''))))>0";
        if ($this->siteId !== null) {
            $scope .= ' AND u.id_gimnasio=:site';
            $params[':site'] = $this->siteId;
        }
        $snapshotJoin = "LEFT JOIN retention_member_snapshot s
                ON s.id_empresa=u.id_empresa AND s.id_socio=u.id_usuario AND s.id_retention_run=:run
            LEFT JOIN (
                SELECT id_empresa,id_socio,MAX(id_retention_detection) id_retention_detection
                  FROM retention_detection WHERE id_empresa=:detection_company GROUP BY id_empresa,id_socio
            ) latest_detection ON latest_detection.id_empresa=u.id_empresa AND latest_detection.id_socio=u.id_usuario
            LEFT JOIN retention_detection d ON d.id_retention_detection=latest_detection.id_retention_detection
            JOIN gimnasio g ON g.id_gimnasio=u.id_gimnasio AND g.id_empresa=u.id_empresa";
        $count = $this->db->prepare("SELECT COUNT(*) FROM usuario u {$snapshotJoin} WHERE {$scope}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $sql = "SELECT u.id_usuario id_socio,u.nombre,u.apellidos,u.id_gimnasio,g.nombre sede_nombre,
                       s.id_retention_run,s.state,s.activity_family,s.baseline_visits,s.recent_visits,
                       s.baseline_weekly_rate,s.recent_weekly_rate,s.drop_pct,s.last_attendance_utc,
                       d.id_retention_detection,d.status workflow_status,d.version,d.detected_at_utc,
                       d.contacted_at_utc,d.returned_at_utc,d.days_to_return,d.next_review_at,
                       CASE WHEN d.status='RETURNED' THEN 'RETURNED' ELSE COALESCE(s.state,'NOT_EVALUATED') END display_state
                  FROM usuario u {$snapshotJoin} WHERE {$scope}
                 ORDER BY u.apellidos,u.nombre,u.id_usuario LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key=>$value) $stmt->bindValue($key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        $stmt->bindValue(':limit',$perPage,PDO::PARAM_INT);
        $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
        $stmt->execute();
        return [
            'items'=>$this->decorateCases($stmt->fetchAll(PDO::FETCH_ASSOC)),
            'pagination'=>$this->paginationResult($total,$page,$perPage),
            'query'=>$query,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function recentVisits(int $limit = 10): array
    {
        return $this->attendanceHistory([],1,max(1,min(20,$limit)))['items'];
    }

    /** @return array{items:list<array<string,mixed>>,pagination:array<string,int>,filters:array<string,mixed>} */
    public function attendanceHistory(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        [$page,$perPage,$offset] = $this->pagination($page,$perPage);
        $activity = $this->filterValue(strtoupper((string)($filters['activity'] ?? '')), ['GYM','BOXEO','TATAMI','GENERAL'], '');
        $fromDate = $this->validDate((string)($filters['from'] ?? ''));
        $toDate = $this->validDate((string)($filters['to'] ?? ''));
        if ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate) {
            throw new InvalidArgumentException('El rango de fechas no es válido.');
        }
        $query = trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',(string)($filters['member'] ?? '')) ?? '');
        if (mb_strlen($query)>100) $query=mb_substr($query,0,100);
        $params = [':company'=>$this->companyId];
        $where = ['v.id_empresa=:company'];
        if ($this->siteId !== null) {
            $where[]='v.id_gimnasio=:site';
            $params[':site']=$this->siteId;
        }
        if ($fromDate!=='') { $where[]='v.local_date>=:from_date'; $params[':from_date']=$fromDate; }
        if ($toDate!=='') { $where[]='v.local_date<=:to_date'; $params[':to_date']=$toDate; }
        if ($activity!=='') { $where[]='v.activity_family=:activity'; $params[':activity']=$activity; }
        if ($query!=='') { $where[]="LOCATE(:member,LOWER(CONCAT_WS(' ',u.nombre,u.apellidos)))>0"; $params[':member']=mb_strtolower($query,'UTF-8'); }
        $from = "FROM attendance_daily_visit v
            JOIN usuario u ON u.id_usuario=v.id_socio AND u.id_empresa=v.id_empresa
            JOIN gimnasio g ON g.id_gimnasio=v.id_gimnasio AND g.id_empresa=u.id_empresa";
        $whereSql=implode(' AND ',$where);
        $count=$this->db->prepare("SELECT COUNT(*) {$from} WHERE {$whereSql}");
        $count->execute($params);
        $total=(int)$count->fetchColumn();
        $stmt=$this->db->prepare("SELECT v.*,u.nombre,u.apellidos,g.nombre sede_nombre {$from}
            WHERE {$whereSql} ORDER BY v.occurred_at_utc DESC,v.id_socio DESC LIMIT :limit OFFSET :offset");
        foreach($params as $key=>$value) $stmt->bindValue($key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        $stmt->bindValue(':limit',$perPage,PDO::PARAM_INT);
        $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
        $stmt->execute();
        $config=$this->config();
        $items=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($items as &$item){
            $item['activity_label']=RetentionPresentation::activity((string)$item['activity_family']);
            $item['local_datetime']=RetentionPresentation::localDateTime((string)$item['occurred_at_utc'],(string)$config['timezone']);
            $item['relative_datetime']=RetentionPresentation::relativeDate((string)$item['occurred_at_utc'],(string)$config['timezone']);
        }
        unset($item);
        return [
            'items'=>$items,'pagination'=>$this->paginationResult($total,$page,$perPage),
            'filters'=>['activity'=>$activity,'from'=>$fromDate,'to'=>$toDate,'member'=>$query],
        ];
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

    private function snapshotComplete(array $run): bool
    {
        $expected=(int)($run['evaluated_count']??0);
        $stmt=$this->db->prepare('SELECT COUNT(*) FROM retention_member_snapshot WHERE id_retention_run=:run AND id_empresa=:company');
        $stmt->execute([':run'=>(int)$run['id_retention_run'],':company'=>$this->companyId]);
        return (int)$stmt->fetchColumn()===$expected;
    }

    private function storeSnapshot(int $runId,DateTimeImmutable $date,array $candidate,array $classification): void
    {
        $family=RetentionPolicy::activityFamily($candidate['mapped_families']??null,$candidate['membership_names']??null);
        $stmt=$this->db->prepare(
            'INSERT INTO retention_member_snapshot
             (id_retention_run,id_empresa,id_gimnasio,id_socio,evaluation_date,state,activity_family,
              baseline_visits,recent_visits,baseline_weekly_rate,recent_weekly_rate,drop_pct,reason_code,
              last_attendance_utc,created_at_utc)
             VALUES (:run,:company,:site,:member,:date,:state,:family,:baseline_visits,:recent_visits,
                     :baseline_rate,:recent_rate,:drop,:reason,:last_attendance,:created)'
        );
        $stmt->execute([
            ':run'=>$runId,':company'=>$this->companyId,':site'=>(int)$candidate['id_gimnasio'],
            ':member'=>(int)$candidate['id_usuario'],':date'=>$date->format('Y-m-d'),
            ':state'=>(string)$classification['state'],':family'=>$family,
            ':baseline_visits'=>(int)$candidate['baseline_visits'],':recent_visits'=>(int)$candidate['recent_visits'],
            ':baseline_rate'=>number_format((float)$classification['baseline_rate'],2,'.',''),
            ':recent_rate'=>number_format((float)$classification['recent_rate'],2,'.',''),
            ':drop'=>number_format((float)$classification['drop_pct'],2,'.',''),
            ':reason'=>(string)$classification['reason_code'],':last_attendance'=>$candidate['last_attendance_utc']?:null,
            ':created'=>gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function latestRunId(): ?int
    {
        $stmt=$this->db->prepare(
            "SELECT id_retention_run FROM retention_run WHERE id_empresa=:company AND status='COMPLETED'
              ORDER BY evaluation_date DESC,id_retention_run DESC LIMIT 1"
        );
        $stmt->execute([':company'=>$this->companyId]);
        $value=$stmt->fetchColumn();
        return $value===false?null:(int)$value;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function decorateCases(array $rows): array
    {
        $config=$this->config();
        $timezone=(string)$config['timezone'];
        if($this->companyNameCache===null){
            $stmt=$this->db->prepare('SELECT COALESCE(NULLIF(nombre_comercial,\'\'),nombre) FROM empresa WHERE id_empresa=:company');
            $stmt->execute([':company'=>$this->companyId]);
            $this->companyNameCache=(string)($stmt->fetchColumn()?:'Gimnera');
        }
        foreach($rows as &$row){
            $state=(string)($row['display_state']??$row['state']??'NOT_EVALUATED');
            $family=(string)($row['activity_family']??'GENERAL');
            $row['display_state']=$state;
            $row['state_label']=RetentionPresentation::label($state);
            $row['activity_label']=RetentionPresentation::activity($family);
            $row['workflow_label']=RetentionPresentation::workflow($row['workflow_status']??null);
            $row['explanation']=RetentionPresentation::explanation($row,(int)$config['recent_days']);
            $row['last_attendance_label']=RetentionPresentation::relativeDate(
                !empty($row['last_attendance_utc'])?(string)$row['last_attendance_utc']:null,$timezone
            );
            $row['detected_label']=RetentionPresentation::localDateTime(
                !empty($row['detected_at_utc'])?(string)$row['detected_at_utc']:null,$timezone,'d/m/Y'
            );
            $row['returned_label']=RetentionPresentation::localDateTime(
                !empty($row['returned_at_utc'])?(string)$row['returned_at_utc']:null,$timezone,'d/m/Y'
            );
            $row['suggested_message']=in_array($state,['ATTENTION','HIGH_ATTENTION'],true)
                ? RetentionPolicy::suggestedMessage($config,$family,(string)$row['nombre'],$this->companyNameCache)
                : null;
        }
        unset($row);
        return $rows;
    }

    /** @return array{0:int,1:int,2:int} */
    private function pagination(int $page,int $perPage): array
    {
        $page=max(1,min(10000,$page));
        $perPage=max(1,min(50,$perPage));
        return [$page,$perPage,($page-1)*$perPage];
    }

    /** @return array{total:int,page:int,per_page:int,pages:int} */
    private function paginationResult(int $total,int $page,int $perPage): array
    {
        return ['total'=>$total,'page'=>$page,'per_page'=>$perPage,'pages'=>max(1,(int)ceil($total/$perPage))];
    }

    /** @param list<string> $allowed */
    private function filterValue(string $value,array $allowed,string $default): string
    {
        return in_array($value,$allowed,true)?$value:$default;
    }

    private function validDate(string $value): string
    {
        $value=trim($value);
        if($value==='') return '';
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if(!$date||$date->format('Y-m-d')!==$value) throw new InvalidArgumentException('Fecha de filtro no válida.');
        return $value;
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
