<?php

require_once __DIR__ . '/../helpers/Authorization.php';
require_once __DIR__ . '/../helpers/AuditPolicy.php';
require_once __DIR__ . '/../helpers/RequestContext.php';
require_once __DIR__ . '/../helpers/TenantLifecyclePolicy.php';
require_once __DIR__ . '/../helpers/TrainingMediaStorage.php';
require_once __DIR__ . '/../helpers/TrainingPolicy.php';
require_once __DIR__ . '/../models/LogModel.php';

/**
 * Servicio de dominio Training.
 *
 * Todo acceso recibe empresa/rol/sede desde TenantContext. Los IDs enviados
 * por formularios solo identifican recursos; nunca el tenant autorizado.
 */
final class TrainingService
{
    private LogModel $audit;

    public function __construct(
        private PDO $db,
        private int $companyId,
        private ?int $siteId,
        private string $role,
        private int $actorId
    ) {
        if ($companyId <= 0 || $actorId <= 0) throw new InvalidArgumentException('Contexto Training no válido.');
        if (!in_array($role, ['superadmin','direccion','admin','recepcion','socio'], true)) {
            throw new InvalidArgumentException('Rol Training no válido.');
        }
        if ($siteId !== null) $this->assertSite($siteId);
        if ($role === 'admin' && $siteId === null) throw new DomainException('El administrador necesita una sede activa.');
        $this->audit = new LogModel($companyId, $db);
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int} */
    public function listExercises(string $search = '', string $discipline = '', int $page = 1, int $perPage = 25): array
    {
        $this->assertPermission('training.view');
        [$page, $perPage, $offset] = $this->pagination($page, $perPage);
        $where = ' WHERE (id_empresa=:company OR id_empresa IS NULL) AND active=1';
        $params = [':company' => $this->companyId];
        $search = trim($search);
        if ($search !== '') {
            if (mb_strlen($search) > 120) throw new InvalidArgumentException('Búsqueda demasiado larga.');
            $where .= ' AND (name LIKE :q OR short_description LIKE :q2)';
            $params[':q'] = '%' . $search . '%';
            $params[':q2'] = '%' . $search . '%';
        }
        if ($discipline !== '') {
            $discipline = TrainingPolicy::enum($discipline, TrainingPolicy::DISCIPLINES, 'Disciplina');
            $where .= ' AND discipline=:discipline';
            $params[':discipline'] = $discipline;
        }
        $count = $this->db->prepare('SELECT COUNT(*) FROM training_exercise' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $stmt = $this->db->prepare(
            'SELECT id_training_exercise,id_empresa,name,slug,discipline,short_description,muscle_group,equipment,
                    difficulty,execution_type,version,(id_empresa IS NULL) AS is_global
               FROM training_exercise' . $where . '
              ORDER BY is_global DESC, name ASC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) $stmt->bindValue($key, $value);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $this->pageResult($stmt->fetchAll(PDO::FETCH_ASSOC), $total, $page, $perPage);
    }

    public function exercise(int $exerciseId): ?array
    {
        $this->assertPermission('training.view');
        $stmt = $this->db->prepare(
            'SELECT * FROM training_exercise
              WHERE id_training_exercise=:id AND (id_empresa=:company OR id_empresa IS NULL) LIMIT 1'
        );
        $stmt->execute([':id' => $exerciseId, ':company' => $this->companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $media = $this->db->prepare(
            'SELECT * FROM training_exercise_media
              WHERE id_training_exercise=:id AND catalog_scope=:scope ORDER BY sort_order,id_training_exercise_media'
        );
        $media->execute([':id' => $exerciseId, ':scope' => (int) $row['catalog_scope']]);
        $row['media'] = $media->fetchAll(PDO::FETCH_ASSOC);
        return $row;
    }

    public function createExercise(array $input): int
    {
        $this->assertPermission('training.manage');
        $data = $this->exerciseData($input);
        return $this->write(function () use ($data): int {
            $stmt = $this->db->prepare(
                'INSERT INTO training_exercise
                 (id_empresa,name,slug,discipline,short_description,preparation,execution_instructions,
                  breathing,common_errors,technical_notes,muscle_group,equipment,difficulty,execution_type,created_by)
                 VALUES (:company,:name,:slug,:discipline,:description,:preparation,:instructions,
                         :breathing,:errors,:notes,:muscle,:equipment,:difficulty,:execution_type,:actor)'
            );
            $stmt->execute([':company' => $this->companyId, ':actor' => $this->actorId] + $data);
            $id = (int) $this->db->lastInsertId();
            $this->auditRequired('TRAINING_EXERCISE_CREATED', 'training_exercise', $id, null, $data[':name']);
            return $id;
        });
    }

    public function updateExercise(int $exerciseId, int $expectedVersion, array $input): void
    {
        $this->assertPermission('training.manage');
        if ($expectedVersion < 1) throw new InvalidArgumentException('Versión no válida.');
        $data = $this->exerciseData($input);
        $this->write(function () use ($exerciseId, $expectedVersion, $data): void {
            $stmt = $this->db->prepare(
                'UPDATE training_exercise SET name=:name,slug=:slug,discipline=:discipline,
                    short_description=:description,preparation=:preparation,execution_instructions=:instructions,
                    breathing=:breathing,common_errors=:errors,technical_notes=:notes,muscle_group=:muscle,
                    equipment=:equipment,difficulty=:difficulty,execution_type=:execution_type,version=version+1
                  WHERE id_training_exercise=:id AND id_empresa=:company AND version=:version'
            );
            $stmt->execute($data + [':id' => $exerciseId, ':company' => $this->companyId, ':version' => $expectedVersion]);
            if ($stmt->rowCount() !== 1) throw new DomainException('El ejercicio cambió en otra sesión o no pertenece a la empresa.');
            $this->auditRequired('TRAINING_EXERCISE_UPDATED', 'training_exercise', $exerciseId, (string) $expectedVersion, (string) ($expectedVersion + 1));
        });
    }

    public function cloneExercise(int $exerciseId, string $name): int
    {
        $this->assertPermission('training.manage');
        $source = $this->exercise($exerciseId);
        if (!$source) throw new DomainException('Ejercicio no disponible.');
        $source['name'] = TrainingPolicy::text($name, 140, 'Nombre');
        $source['slug'] = TrainingPolicy::slug($name);
        return $this->createExercise($source);
    }

    public function addImageMedia(int $exerciseId, array $file, array $metadata): int
    {
        $this->assertPermission('training.manage');
        $exercise = $this->ownedExercise($exerciseId);
        $stored = TrainingMediaStorage::storeUploadedImage($file);
        try {
            return $this->write(function () use ($exercise, $stored, $metadata): int {
                $order = $this->nextMediaOrder((int) $exercise['id_training_exercise']);
                $stmt = $this->db->prepare(
                    "INSERT INTO training_exercise_media
                     (id_training_exercise,catalog_scope,media_type,storage_key,mime_type,size_bytes,sort_order,
                      alt_text,source,license,attribution)
                     VALUES (:exercise,:scope,'IMAGE',:key,:mime,:size,:sort,:alt,:source,:license,:attribution)"
                );
                $stmt->execute([
                    ':exercise' => (int) $exercise['id_training_exercise'], ':scope' => $this->companyId,
                    ':key' => $stored['storage_key'], ':mime' => $stored['mime_type'], ':size' => $stored['size_bytes'],
                    ':sort' => $order, ':alt' => TrainingPolicy::text($metadata['alt_text'] ?? '', 255, 'Texto alternativo'),
                    ':source' => TrainingPolicy::text($metadata['source'] ?? '', 255, 'Fuente', false),
                    ':license' => TrainingPolicy::text($metadata['license'] ?? '', 120, 'Licencia', false),
                    ':attribution' => TrainingPolicy::text($metadata['attribution'] ?? '', 500, 'Atribución', false),
                ]);
                $id = (int) $this->db->lastInsertId();
                $this->auditRequired('TRAINING_EXERCISE_MEDIA_CREATED', 'training_exercise_media', $id, null, 'IMAGE');
                return $id;
            });
        } catch (Throwable $error) {
            TrainingMediaStorage::deleteIfUnreferenced($this->db, $stored['storage_key']);
            throw $error;
        }
    }

    public function addVideoReference(int $exerciseId, string $url, array $metadata): int
    {
        $this->assertPermission('training.manage');
        $exercise = $this->ownedExercise($exerciseId);
        $url = TrainingMediaStorage::validateVideoReference($url);
        return $this->write(function () use ($exercise, $url, $metadata): int {
            $stmt = $this->db->prepare(
                "INSERT INTO training_exercise_media
                 (id_training_exercise,catalog_scope,media_type,external_url,sort_order,alt_text,source,license,attribution)
                 VALUES (:exercise,:scope,'VIDEO_REFERENCE',:url,:sort,:alt,:source,:license,:attribution)"
            );
            $stmt->execute([
                ':exercise' => (int) $exercise['id_training_exercise'], ':scope' => $this->companyId,
                ':url' => $url, ':sort' => $this->nextMediaOrder((int) $exercise['id_training_exercise']),
                ':alt' => TrainingPolicy::text($metadata['alt_text'] ?? '', 255, 'Texto alternativo'),
                ':source' => TrainingPolicy::text($metadata['source'] ?? '', 255, 'Fuente', false),
                ':license' => TrainingPolicy::text($metadata['license'] ?? '', 120, 'Licencia', false),
                ':attribution' => TrainingPolicy::text($metadata['attribution'] ?? '', 500, 'Atribución', false),
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->auditRequired('TRAINING_EXERCISE_MEDIA_CREATED', 'training_exercise_media', $id, null, 'VIDEO_REFERENCE');
            return $id;
        });
    }

    public function media(int $mediaId): ?array
    {
        $this->assertPermission('training.view');
        $stmt = $this->db->prepare(
            'SELECT m.* FROM training_exercise_media m
             JOIN training_exercise e ON e.id_training_exercise=m.id_training_exercise AND e.catalog_scope=m.catalog_scope
             WHERE m.id_training_exercise_media=:id AND (e.id_empresa=:company OR e.id_empresa IS NULL) LIMIT 1'
        );
        $stmt->execute([':id' => $mediaId, ':company' => $this->companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function planMedia(int $mediaId): ?array
    {
        $this->assertPermission($this->role === 'socio' ? 'training.own.view' : 'training.view');
        $where='pm.id_training_plan_exercise_media=:id AND pm.id_empresa=:company';
        $params=[':id'=>$mediaId,':company'=>$this->companyId];
        if($this->role==='admin'){$where.=' AND pm.id_gimnasio=:site';$params[':site']=$this->siteId;}
        elseif($this->role==='socio'){$where.=' AND pm.id_socio=:member';$params[':member']=$this->actorId;}
        $stmt=$this->db->prepare(
            'SELECT pm.* FROM training_plan_exercise_media pm '
            . 'JOIN training_plan_exercise pe ON pe.id_training_plan_exercise=pm.id_training_plan_exercise '
            . 'AND pe.id_empresa=pm.id_empresa AND pe.id_gimnasio=pm.id_gimnasio AND pe.id_socio=pm.id_socio '
            . 'WHERE '.$where.' LIMIT 1'
        );
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC)?:null;
    }

    /** Crea una plantilla completa o vacía como una sola unidad atómica. */
    public function createTemplate(array $input, array $days = []): int
    {
        $this->assertPermission('training.manage');
        $data = $this->templateData($input);
        return $this->write(function () use ($data, $days): int {
            $stmt = $this->db->prepare(
                'INSERT INTO training_template
                 (id_empresa,name,slug,description,objective,level,days_per_week,status,created_by)
                 VALUES (:company,:name,:slug,:description,:objective,:level,:days,:status,:actor)'
            );
            $stmt->execute([
                ':company' => $this->companyId, ':actor' => $this->actorId,
                ':name' => $data['name'], ':slug' => $data['slug'], ':description' => $data['description'],
                ':objective' => $data['objective'], ':level' => $data['level'], ':days' => $data['days_per_week'],
                ':status' => $data['status'],
            ]);
            $templateId = (int) $this->db->lastInsertId();
            $this->insertDisciplines('training_template_discipline', 'id_training_template', $templateId, $data['disciplines']);
            foreach ($days as $day) $this->insertTemplateDay($templateId, $day);
            $this->auditRequired('TRAINING_TEMPLATE_CREATED', 'training_template', $templateId, null, $data['name']);
            return $templateId;
        });
    }

    /** @return list<array<string,mixed>> */
    public function listTemplates(): array
    {
        $this->assertPermission('training.view');
        $stmt = $this->db->prepare(
            'SELECT t.*,GROUP_CONCAT(d.discipline ORDER BY d.discipline) disciplines
               FROM training_template t
               LEFT JOIN training_template_discipline d ON d.id_training_template=t.id_training_template AND d.id_empresa=t.id_empresa
              WHERE t.id_empresa=:company
              GROUP BY t.id_training_template ORDER BY t.updated_at_utc DESC,t.id_training_template DESC'
        );
        $stmt->execute([':company' => $this->companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function template(int $templateId): ?array
    {
        $this->assertPermission('training.view');
        $stmt = $this->db->prepare('SELECT * FROM training_template WHERE id_training_template=:id AND id_empresa=:company');
        $stmt->execute([':id' => $templateId, ':company' => $this->companyId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$template) return null;
        $template['disciplines'] = $this->disciplinesFor('training_template_discipline', 'id_training_template', $templateId);
        $template['days'] = $this->templateDays($templateId);
        return $template;
    }

    /** @return list<array<string,mixed>> */
    public function listMembers(): array
    {
        $this->assertPermission('training.view');
        $sql = "SELECT u.id_usuario,u.nombre,u.apellidos,u.id_gimnasio,g.nombre site_name
                  FROM usuario u JOIN gimnasio g ON g.id_gimnasio=u.id_gimnasio AND g.id_empresa=u.id_empresa
                 WHERE u.id_empresa=:company AND u.rol='socio' AND u.activo=1 AND u.anonimizado_en IS NULL";
        $params = [':company' => $this->companyId];
        if ($this->role === 'admin') { $sql .= ' AND u.id_gimnasio=:site'; $params[':site'] = $this->siteId; }
        $sql .= ' ORDER BY u.apellidos,u.nombre LIMIT 1000';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addTemplateDay(int $templateId, array $day): int
    {
        $this->assertPermission('training.manage');
        return $this->write(function () use ($templateId, $day): int {
            $template = $this->templateForUpdate($templateId);
            $id = $this->insertTemplateDay($templateId, $day);
            $this->bumpTemplateVersion($templateId, (int) $template['version']);
            $this->auditRequired('TRAINING_TEMPLATE_UPDATED', 'training_template', $templateId, (string)$template['version'], (string)((int)$template['version'] + 1));
            return $id;
        });
    }

    public function addTemplateBlock(int $templateId, int $dayId, array $block): int
    {
        $this->assertPermission('training.manage');
        return $this->write(function () use ($templateId, $dayId, $block): int {
            $template = $this->templateForUpdate($templateId);
            $day = $this->db->prepare('SELECT 1 FROM training_template_day WHERE id_training_template_day=:day AND id_training_template=:template AND id_empresa=:company FOR UPDATE');
            $day->execute([':day'=>$dayId,':template'=>$templateId,':company'=>$this->companyId]);
            if (!$day->fetchColumn()) throw new DomainException('Día de plantilla no disponible.');
            $id = $this->insertTemplateBlock($dayId, $block);
            $this->bumpTemplateVersion($templateId, (int) $template['version']);
            $this->auditRequired('TRAINING_TEMPLATE_UPDATED', 'training_template', $templateId, (string)$template['version'], (string)((int)$template['version'] + 1));
            return $id;
        });
    }

    public function addTemplateExercise(int $templateId, int $blockId, array $item): int
    {
        $this->assertPermission('training.manage');
        return $this->write(function () use ($templateId, $blockId, $item): int {
            $template = $this->templateForUpdate($templateId);
            $block = $this->db->prepare(
                'SELECT b.block_type FROM training_template_block b
                 JOIN training_template_day d ON d.id_training_template_day=b.id_training_template_day
                 WHERE b.id_training_template_block=:block AND d.id_training_template=:template
                   AND b.id_empresa=:company FOR UPDATE'
            );
            $block->execute([':block'=>$blockId,':template'=>$templateId,':company'=>$this->companyId]);
            $type = $block->fetchColumn();
            if ($type === false) throw new DomainException('Bloque de plantilla no disponible.');
            $id = $this->insertTemplateExercise($blockId, (string)$type, $item);
            $this->bumpTemplateVersion($templateId, (int) $template['version']);
            $this->auditRequired('TRAINING_TEMPLATE_UPDATED', 'training_template', $templateId, (string)$template['version'], (string)((int)$template['version'] + 1));
            return $id;
        });
    }

    public function createBlankPlan(array $input): int
    {
        $this->assertPermission('training.manage');
        $member = $this->member((int) ($input['member_id'] ?? 0));
        $data = $this->planData($input, $member);
        return $this->write(function () use ($data, $member): int {
            $id = $this->insertPlan($data, $member, null);
            $this->insertDisciplines('training_plan_discipline', 'id_training_plan', $id, $data['disciplines'], $member);
            $this->auditRequired('TRAINING_PLAN_CREATED', 'training_plan', $id, null, $data['name'], (int) $member['id_usuario'], (int) $member['id_gimnasio']);
            return $id;
        });
    }

    public function addPlanDay(int $planId, int $expectedPlanVersion, array $input): int
    {
        $this->assertPermission('training.manage');
        return $this->write(function () use ($planId,$expectedPlanVersion,$input): int {
            $plan=$this->planForUpdate($planId);
            $this->assertPlanVersion($plan,$expectedPlanVersion);
            $stmt=$this->db->prepare(
                'INSERT INTO training_plan_day
                 (id_training_plan,id_empresa,id_gimnasio,id_socio,name,day_order,objective,notes)
                 VALUES (:plan,:company,:site,:member,:name,:sort,:objective,:notes)'
            );
            $stmt->execute([
                ':plan'=>$planId,':company'=>$this->companyId,':site'=>(int)$plan['id_gimnasio'],':member'=>(int)$plan['id_socio'],
                ':name'=>TrainingPolicy::text($input['name']??'',120,'Nombre del día'),
                ':sort'=>TrainingPolicy::positiveInt($input['day_order']??null,'Orden del día',31),
                ':objective'=>TrainingPolicy::optionalEnum($input['objective']??null,TrainingPolicy::OBJECTIVES,'Objetivo del día'),
                ':notes'=>TrainingPolicy::text($input['notes']??'',3000,'Notas del día',false),
            ]);
            $id=(int)$this->db->lastInsertId();
            $this->bumpPlanVersion($planId,$expectedPlanVersion);
            $this->auditRequired('TRAINING_PLAN_UPDATED','training_plan',$planId,(string)$expectedPlanVersion,(string)($expectedPlanVersion+1),(int)$plan['id_socio'],(int)$plan['id_gimnasio']);
            return $id;
        });
    }

    public function addPlanBlock(int $planId,int $dayId,int $expectedPlanVersion,array $input): int
    {
        $this->assertPermission('training.manage');
        return $this->write(function () use ($planId,$dayId,$expectedPlanVersion,$input): int {
            $plan=$this->planForUpdate($planId);$this->assertPlanVersion($plan,$expectedPlanVersion);
            $day=$this->db->prepare('SELECT 1 FROM training_plan_day WHERE id_training_plan_day=:day AND id_training_plan=:plan AND id_empresa=:company AND id_gimnasio=:site AND id_socio=:member FOR UPDATE');
            $day->execute([':day'=>$dayId,':plan'=>$planId,':company'=>$this->companyId,':site'=>$plan['id_gimnasio'],':member'=>$plan['id_socio']]);
            if(!$day->fetchColumn())throw new DomainException('Día de plan no disponible.');
            $type=TrainingPolicy::enum($input['block_type']??'GENERAL',TrainingPolicy::BLOCK_TYPES,'Tipo de bloque');
            $rounds=$type==='CIRCUIT'?TrainingPolicy::positiveInt($input['circuit_rounds']??null,'Vueltas',1000):null;
            $stmt=$this->db->prepare('INSERT INTO training_plan_block (id_training_plan_day,id_empresa,id_gimnasio,id_socio,name,block_type,block_order,circuit_rounds,round_rest_seconds,notes) VALUES (:day,:company,:site,:member,:name,:type,:sort,:rounds,:rest,:notes)');
            $stmt->execute([
                ':day'=>$dayId,':company'=>$this->companyId,':site'=>$plan['id_gimnasio'],':member'=>$plan['id_socio'],
                ':name'=>TrainingPolicy::text($input['name']??'',120,'Nombre del bloque'),':type'=>$type,
                ':sort'=>TrainingPolicy::positiveInt($input['block_order']??null,'Orden del bloque',64),':rounds'=>$rounds,
                ':rest'=>$type==='CIRCUIT'?TrainingPolicy::nonNegativeInt($input['round_rest_seconds']??0,'Descanso entre vueltas',86400,true):null,
                ':notes'=>TrainingPolicy::text($input['notes']??'',3000,'Notas del bloque',false),
            ]);
            $id=(int)$this->db->lastInsertId();$this->bumpPlanVersion($planId,$expectedPlanVersion);
            $this->auditRequired('TRAINING_PLAN_UPDATED','training_plan',$planId,(string)$expectedPlanVersion,(string)($expectedPlanVersion+1),(int)$plan['id_socio'],(int)$plan['id_gimnasio']);
            return $id;
        });
    }

    public function addPlanExercise(int $planId,int $blockId,int $expectedPlanVersion,array $input): int
    {
        $this->assertPermission('training.manage');
        return $this->write(function () use ($planId,$blockId,$expectedPlanVersion,$input): int {
            $plan=$this->planForUpdate($planId);$this->assertPlanVersion($plan,$expectedPlanVersion);
            $block=$this->db->prepare('SELECT b.block_type FROM training_plan_block b JOIN training_plan_day d ON d.id_training_plan_day=b.id_training_plan_day WHERE b.id_training_plan_block=:block AND d.id_training_plan=:plan AND b.id_empresa=:company AND b.id_gimnasio=:site AND b.id_socio=:member FOR UPDATE');
            $block->execute([':block'=>$blockId,':plan'=>$planId,':company'=>$this->companyId,':site'=>$plan['id_gimnasio'],':member'=>$plan['id_socio']]);
            $blockType=$block->fetchColumn();if($blockType===false)throw new DomainException('Bloque de plan no disponible.');
            $exercise=$this->exerciseRow((int)($input['exercise_id']??0));
            $type=TrainingPolicy::enum($input['execution_type']??$exercise['execution_type'],TrainingPolicy::EXECUTION_TYPES,'Tipo de ejecución');
            if($blockType==='CIRCUIT')$type='CIRCUIT';elseif($type!==$exercise['execution_type'])throw new DomainException('El tipo no está permitido para el ejercicio.');
            $params=TrainingPolicy::executionParameters($type,$input);
            $columns=['id_training_plan_block','id_empresa','id_gimnasio','id_socio','source_exercise_id','exercise_name','discipline','instructions','execution_type','item_order'];
            $bindings=[':block'=>$blockId,':company'=>$this->companyId,':site'=>$plan['id_gimnasio'],':member'=>$plan['id_socio'],':source'=>$exercise['id_training_exercise'],':name'=>$exercise['name'],':discipline'=>$exercise['discipline'],':instructions'=>$exercise['execution_instructions'],':type'=>$type,':sort'=>TrainingPolicy::positiveInt($input['item_order']??null,'Orden del ejercicio',1000),':notes'=>TrainingPolicy::text($input['notes']??'',2000,'Notas',false)];
            $placeholders=[':block',':company',':site',':member',':source',':name',':discipline',':instructions',':type',':sort'];
            foreach($params as $column=>$value){$columns[]=$column;$placeholders[]=':'.$column;$bindings[':'.$column]=$value;}$columns[]='notes';$placeholders[]=':notes';
            $stmt=$this->db->prepare('INSERT INTO training_plan_exercise ('.implode(',',$columns).') VALUES ('.implode(',',$placeholders).')');$stmt->execute($bindings);
            $id=(int)$this->db->lastInsertId();
            $this->copyExerciseMediaToPlan($id,(int)$exercise['id_training_exercise'],(int)$plan['id_gimnasio'],(int)$plan['id_socio']);
            $this->bumpPlanVersion($planId,$expectedPlanVersion);
            $this->auditRequired('TRAINING_PLAN_UPDATED','training_plan',$planId,(string)$expectedPlanVersion,(string)($expectedPlanVersion+1),(int)$plan['id_socio'],(int)$plan['id_gimnasio']);
            return $id;
        });
    }

    public function createPlanFromTemplate(int $templateId, int $memberId, array $overrides = []): int
    {
        $this->assertPermission('training.manage');
        $member = $this->member($memberId);
        return $this->write(function () use ($templateId, $member, $overrides): int {
            $lock = $this->db->prepare('SELECT * FROM training_template WHERE id_training_template=:id AND id_empresa=:company FOR UPDATE');
            $lock->execute([':id' => $templateId, ':company' => $this->companyId]);
            $template = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$template || $template['status'] !== 'ACTIVE') throw new DomainException('Plantilla no disponible.');
            $this->assertTemplateExecutable($templateId, (int) $template['days_per_week']);
            $data = $this->planData([
                'member_id' => (int) $member['id_usuario'],
                'name' => trim((string)($overrides['name'] ?? '')) !== '' ? $overrides['name'] : $template['name'],
                'objective' => $overrides['objective'] ?? $template['objective'],
                'start_date' => $overrides['start_date'] ?? date('Y-m-d'),
                'end_date' => $overrides['end_date'] ?? null,
                'notes' => trim((string)($overrides['notes'] ?? '')) !== '' ? $overrides['notes'] : $template['description'],
                'disciplines' => $this->disciplinesFor('training_template_discipline', 'id_training_template', $templateId),
            ], $member);
            $planId = $this->insertPlan($data, $member, $templateId);
            $this->insertDisciplines('training_plan_discipline', 'id_training_plan', $planId, $data['disciplines'], $member);
            $this->cloneTemplateStructure($templateId, $planId, $member);
            $this->auditRequired('TRAINING_PLAN_CREATED', 'training_plan', $planId, null, $data['name'], (int) $member['id_usuario'], (int) $member['id_gimnasio']);
            return $planId;
        });
    }

    /** @return list<array<string,mixed>> */
    public function listPlans(?int $siteFilter = null): array
    {
        $this->assertPermission('training.view');
        $where = 'p.id_empresa=:company';
        $params = [':company' => $this->companyId];
        $site = $this->authorizedSiteFilter($siteFilter);
        if ($site !== null) {
            $where .= ' AND p.id_gimnasio=:site';
            $params[':site'] = $site;
        }
        $stmt = $this->db->prepare(
            "SELECT p.*,u.nombre member_name,u.apellidos member_surname,g.nombre site_name,
                    EXISTS(SELECT 1 FROM training_assignment a WHERE a.id_training_plan=p.id_training_plan AND a.status='ACTIVE') assigned
               FROM training_plan p JOIN usuario u ON u.id_usuario=p.id_socio AND u.id_empresa=p.id_empresa
               JOIN gimnasio g ON g.id_gimnasio=p.id_gimnasio AND g.id_empresa=p.id_empresa
              WHERE {$where} ORDER BY p.updated_at_utc DESC,p.id_training_plan DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function plan(int $planId): ?array
    {
        if ($this->role === 'socio') $this->assertPermission('training.own.view');
        else $this->assertPermission('training.view');
        $where = 'p.id_training_plan=:id AND p.id_empresa=:company';
        $params = [':id' => $planId, ':company' => $this->companyId];
        if ($this->role === 'admin') {
            $where .= ' AND p.id_gimnasio=:site';
            $params[':site'] = $this->siteId;
        } elseif ($this->role === 'socio') {
            $where .= ' AND p.id_socio=:member';
            $params[':member'] = $this->actorId;
        }
        $stmt = $this->db->prepare("SELECT p.*,u.nombre member_name,u.apellidos member_surname FROM training_plan p JOIN usuario u ON u.id_usuario=p.id_socio AND u.id_empresa=p.id_empresa WHERE {$where}");
        $stmt->execute($params);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) return null;
        $plan['disciplines'] = $this->disciplinesFor('training_plan_discipline', 'id_training_plan', $planId);
        $plan['days'] = $this->planDays($planId, (int) $plan['id_gimnasio'], (int) $plan['id_socio']);
        $history=$this->db->prepare(
            'SELECT s.id_training_session,s.session_date,s.status,s.completed_at_utc,s.notes,COUNT(se.id_training_session_exercise) result_count '
            . 'FROM training_session s LEFT JOIN training_session_exercise se '
            . 'ON se.id_training_session=s.id_training_session AND se.id_empresa=s.id_empresa '
            . 'WHERE s.id_training_plan=:plan AND s.id_empresa=:company AND s.id_gimnasio=:site AND s.id_socio=:member '
            . 'GROUP BY s.id_training_session ORDER BY s.session_date DESC,s.id_training_session DESC LIMIT 200'
        );
        $history->execute([':plan'=>$planId,':company'=>$this->companyId,':site'=>(int)$plan['id_gimnasio'],':member'=>(int)$plan['id_socio']]);
        $plan['sessions']=$history->fetchAll(PDO::FETCH_ASSOC);
        return $plan;
    }

    public function assignPlan(int $planId, string $idempotencyKey): int
    {
        $this->assertPermission('training.manage');
        if (!preg_match('/^[A-Za-z0-9._:-]{16,200}$/', $idempotencyKey)) throw new InvalidArgumentException('Clave de idempotencia no válida.');
        $hash = hash('sha256', $idempotencyKey);
        return $this->write(function () use ($planId, $hash): int {
            $plan = $this->planForUpdate($planId);
            $this->assertPlanExecutable($planId);
            $memberLock = $this->db->prepare('SELECT id_usuario FROM usuario WHERE id_usuario=:member AND id_empresa=:company FOR UPDATE');
            $memberLock->execute([':member' => (int) $plan['id_socio'], ':company' => $this->companyId]);
            $existingKey = $this->db->prepare('SELECT id_training_assignment,id_training_plan FROM training_assignment WHERE id_empresa=:company AND idempotency_key=:key');
            $existingKey->execute([':company' => $this->companyId, ':key' => $hash]);
            $existing = $existingKey->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ((int) $existing['id_training_plan'] !== $planId) throw new DomainException('La clave de idempotencia pertenece a otra asignación.');
                return (int) $existing['id_training_assignment'];
            }
            $current=$this->db->prepare(
                "SELECT id_training_assignment,id_training_plan FROM training_assignment "
                . "WHERE id_empresa=:company AND id_socio=:member AND status='ACTIVE' FOR UPDATE"
            );
            $current->execute([':company'=>$this->companyId,':member'=>(int)$plan['id_socio']]);
            $activeAssignment=$current->fetch(PDO::FETCH_ASSOC);
            if($activeAssignment && (int)$activeAssignment['id_training_plan']===$planId){
                return (int)$activeAssignment['id_training_assignment'];
            }
            if($activeAssignment){
                $archive=$this->db->prepare(
                    "UPDATE training_plan SET status='ARCHIVED',version=version+1 "
                    . "WHERE id_training_plan=:plan AND id_empresa=:company AND status='ACTIVE'"
                );
                $archive->execute([':plan'=>(int)$activeAssignment['id_training_plan'],':company'=>$this->companyId]);
                if($archive->rowCount()!==1)throw new DomainException('El plan principal anterior cambió durante la asignación.');
            }
            $end = $this->db->prepare("UPDATE training_assignment SET status='ENDED',ended_at_utc=UTC_TIMESTAMP() WHERE id_empresa=:company AND id_socio=:member AND status='ACTIVE'");
            $end->execute([':company' => $this->companyId, ':member' => (int) $plan['id_socio']]);
            $insert = $this->db->prepare(
                "INSERT INTO training_assignment
                 (id_training_plan,id_empresa,id_gimnasio,id_socio,assigned_by,status,idempotency_key)
                 VALUES (:plan,:company,:site,:member,:actor,'ACTIVE',:key)"
            );
            $insert->execute([
                ':plan' => $planId, ':company' => $this->companyId, ':site' => (int) $plan['id_gimnasio'],
                ':member' => (int) $plan['id_socio'], ':actor' => $this->actorId, ':key' => $hash,
            ]);
            $assignmentId = (int) $this->db->lastInsertId();
            $this->db->prepare("UPDATE training_plan SET status='ACTIVE',version=version+1 WHERE id_training_plan=:id AND id_empresa=:company")
                ->execute([':id' => $planId, ':company' => $this->companyId]);
            $this->auditRequired('TRAINING_PLAN_ASSIGNED', 'training_assignment', $assignmentId, null, (string) $planId, (int) $plan['id_socio'], (int) $plan['id_gimnasio']);
            return $assignmentId;
        });
    }

    public function updatePlanExercise(int $itemId, int $expectedVersion, array $input): void
    {
        $this->assertPermission('training.manage');
        $type = TrainingPolicy::enum($input['execution_type'] ?? '', TrainingPolicy::EXECUTION_TYPES, 'Tipo de ejecución');
        $params = TrainingPolicy::executionParameters($type, $input);
        $notes = TrainingPolicy::text($input['notes'] ?? '', 2000, 'Notas', false);
        $this->write(function () use ($itemId, $expectedVersion, $type, $params, $notes): void {
            $scope = "id_training_plan_exercise=:id AND id_empresa=:company AND version=:version";
            $values = [':id' => $itemId, ':company' => $this->companyId, ':version' => $expectedVersion, ':type' => $type, ':notes' => $notes];
            if ($this->role === 'admin') {
                $scope .= ' AND id_gimnasio=:site';
                $values[':site'] = $this->siteId;
            }
            $currentScope='id_training_plan_exercise=:id AND id_empresa=:company';$currentValues=[':id'=>$itemId,':company'=>$this->companyId];
            if($this->role==='admin'){$currentScope.=' AND id_gimnasio=:site';$currentValues[':site']=$this->siteId;}
            $current=$this->db->prepare('SELECT execution_type FROM training_plan_exercise WHERE '.$currentScope.' FOR UPDATE');$current->execute($currentValues);
            if((string)$current->fetchColumn()!==$type)throw new DomainException('El tipo de ejecución del snapshot no puede cambiarse.');
            $sets = [];
            foreach ($params as $column => $value) {
                $sets[] = $column . '=:' . $column;
                $values[':' . $column] = $value;
            }
            $stmt = $this->db->prepare('UPDATE training_plan_exercise SET execution_type=:type,' . implode(',', $sets) . ',notes=:notes,version=version+1 WHERE ' . $scope);
            $stmt->execute($values);
            if ($stmt->rowCount() !== 1) throw new DomainException('El plan cambió en otra sesión o está fuera de ámbito.');
            $this->auditRequired('TRAINING_PLAN_UPDATED', 'training_plan_exercise', $itemId, (string) $expectedVersion, (string) ($expectedVersion + 1));
        });
    }

    /** @param list<int> $orderedIds */
    public function reorderPlanExercises(int $blockId, array $orderedIds, int $planId, int $expectedPlanVersion): void
    {
        $this->assertPermission('training.manage');
        $orderedIds = array_values(array_unique(array_map('intval', $orderedIds)));
        if ($orderedIds === [] || count($orderedIds) > 1000 || min($orderedIds) <= 0) throw new InvalidArgumentException('Orden no válido.');
        $this->write(function () use ($blockId, $orderedIds, $planId, $expectedPlanVersion): void {
            $plan = $this->planForUpdate($planId);
            if ((int) $plan['version'] !== $expectedPlanVersion) throw new DomainException('El plan cambió en otra sesión.');
            $scope = $this->db->prepare(
                'SELECT e.id_training_plan_exercise FROM training_plan_exercise e
                 JOIN training_plan_block b ON b.id_training_plan_block=e.id_training_plan_block
                 JOIN training_plan_day d ON d.id_training_plan_day=b.id_training_plan_day
                 WHERE e.id_training_plan_block=:block AND d.id_training_plan=:plan AND e.id_empresa=:company
                 ORDER BY e.item_order FOR UPDATE'
            );
            $scope->execute([':block' => $blockId, ':plan' => $planId, ':company' => $this->companyId]);
            $existing = array_map('intval', $scope->fetchAll(PDO::FETCH_COLUMN));
            $a = $existing; $b = $orderedIds; sort($a); sort($b);
            if ($a !== $b) throw new DomainException('El orden contiene elementos ajenos o incompletos.');
            $temp = $this->db->prepare('UPDATE training_plan_exercise SET item_order=item_order+1000 WHERE id_training_plan_block=:block AND id_empresa=:company');
            $temp->execute([':block' => $blockId, ':company' => $this->companyId]);
            $update = $this->db->prepare('UPDATE training_plan_exercise SET item_order=:sort WHERE id_training_plan_exercise=:id AND id_empresa=:company');
            foreach ($orderedIds as $index => $id) $update->execute([':sort' => $index + 1, ':id' => $id, ':company' => $this->companyId]);
            $version = $this->db->prepare('UPDATE training_plan SET version=version+1 WHERE id_training_plan=:plan AND id_empresa=:company AND version=:version');
            $version->execute([':plan' => $planId, ':company' => $this->companyId, ':version' => $expectedPlanVersion]);
            if ($version->rowCount() !== 1) throw new DomainException('El plan cambió en otra sesión.');
            $this->auditRequired('TRAINING_PLAN_UPDATED', 'training_plan', $planId, (string) $expectedPlanVersion, (string) ($expectedPlanVersion + 1));
        });
    }

    public function createSession(int $planId, int $dayId, string $date, string $idempotencyKey): int
    {
        $this->assertPermission('training.manage');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Fecha de sesión no válida.');
        if (!preg_match('/^[A-Za-z0-9._:-]{16,200}$/', $idempotencyKey)) throw new InvalidArgumentException('Clave de idempotencia no válida.');
        $hash = hash('sha256', $idempotencyKey);
        return $this->write(function () use ($planId, $dayId, $date, $hash): int {
            $plan = $this->planForUpdate($planId);
            $day = $this->db->prepare('SELECT id_training_plan_day FROM training_plan_day WHERE id_training_plan_day=:day AND id_training_plan=:plan AND id_empresa=:company');
            $day->execute([':day' => $dayId, ':plan' => $planId, ':company' => $this->companyId]);
            if (!$day->fetchColumn()) throw new DomainException('Día de plan no disponible.');
            $existing = $this->db->prepare('SELECT id_training_session,id_training_plan,id_training_plan_day,session_date FROM training_session WHERE id_empresa=:company AND idempotency_key=:key');
            $existing->execute([':company' => $this->companyId, ':key' => $hash]);
            $prior = $existing->fetch(PDO::FETCH_ASSOC);
            if ($prior) {
                if ((int)$prior['id_training_plan'] !== $planId || (int)$prior['id_training_plan_day'] !== $dayId
                    || (string)$prior['session_date'] !== $date) {
                    throw new DomainException('La clave de idempotencia pertenece a otra sesión.');
                }
                return (int) $prior['id_training_session'];
            }
            $active = $this->db->prepare(
                "SELECT 1 FROM training_assignment WHERE id_training_plan=:plan AND id_empresa=:company "
                . "AND id_socio=:member AND status='ACTIVE' FOR UPDATE"
            );
            $active->execute([':plan'=>$planId, ':company'=>$this->companyId, ':member'=>(int)$plan['id_socio']]);
            if (!$active->fetchColumn()) throw new DomainException('El plan no está asignado como principal.');
            $stmt = $this->db->prepare(
                'INSERT INTO training_session
                 (id_training_plan,id_training_plan_day,id_empresa,id_gimnasio,id_socio,session_date,idempotency_key)
                 VALUES (:plan,:day,:company,:site,:member,:date,:key)'
            );
            $stmt->execute([':plan'=>$planId,':day'=>$dayId,':company'=>$this->companyId,':site'=>$plan['id_gimnasio'],':member'=>$plan['id_socio'],':date'=>$date,':key'=>$hash]);
            return (int) $this->db->lastInsertId();
        });
    }

    public function finishSession(int $sessionId, int $expectedVersion, string $status, array $results = [], ?string $notes = null): void
    {
        $this->assertPermission('training.manage');
        $status = TrainingPolicy::enum($status, ['COMPLETED','SKIPPED'], 'Estado de sesión');
        $notes = TrainingPolicy::text($notes ?? '', 2000, 'Notas', false);
        $this->write(function () use ($sessionId, $expectedVersion, $status, $results, $notes): void {
            $scope = 'id_training_session=:id AND id_empresa=:company AND status=\'PENDING\' AND version=:version';
            $values = [':id'=>$sessionId,':company'=>$this->companyId,':version'=>$expectedVersion,':status'=>$status,':completed_status'=>$status,':notes'=>$notes];
            if ($this->role === 'admin') { $scope .= ' AND id_gimnasio=:site'; $values[':site']=$this->siteId; }
            $stmt = $this->db->prepare("UPDATE training_session SET status=:status,notes=:notes,completed_at_utc=IF(:completed_status='COMPLETED',UTC_TIMESTAMP(),NULL),version=version+1 WHERE {$scope}");
            $stmt->execute($values);
            if ($stmt->rowCount() !== 1) throw new DomainException('La sesión ya fue procesada o está fuera de ámbito.');
            foreach ($results as $result) $this->insertSessionResult($sessionId, $result);
            $this->auditRequired('TRAINING_SESSION_' . $status, 'training_session', $sessionId, 'PENDING', $status);
        });
    }

    public function memberSummary(int $memberId): ?array
    {
        $this->assertPermission($this->role === 'socio' ? 'training.own.view' : 'training.view');
        if ($this->role === 'socio' && $memberId !== $this->actorId) throw new DomainException('No autorizado.');
        $member = $this->member($memberId, false);
        $stmt = $this->db->prepare(
            "SELECT p.id_training_plan,p.name,p.objective,p.start_date,p.end_date,
                    GROUP_CONCAT(DISTINCT d.discipline ORDER BY d.discipline) disciplines,
                    COUNT(DISTINCT pd.id_training_plan_day) day_count,
                    SUM(s.status='COMPLETED') completed_sessions,
                    COUNT(DISTINCT s.id_training_session) total_sessions
               FROM training_assignment a
               JOIN training_plan p ON p.id_training_plan=a.id_training_plan AND p.id_empresa=a.id_empresa
               LEFT JOIN training_plan_discipline d ON d.id_training_plan=p.id_training_plan AND d.id_empresa=p.id_empresa
               LEFT JOIN training_plan_day pd ON pd.id_training_plan=p.id_training_plan AND pd.id_empresa=p.id_empresa
               LEFT JOIN training_session s ON s.id_training_plan=p.id_training_plan AND s.id_empresa=p.id_empresa
              WHERE a.id_empresa=:company AND a.id_socio=:member AND a.status='ACTIVE'
              GROUP BY p.id_training_plan LIMIT 1"
        );
        $stmt->execute([':company'=>$this->companyId,':member'=>(int)$member['id_usuario']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function exerciseData(array $input): array
    {
        $name = TrainingPolicy::text($input['name'] ?? '', 140, 'Nombre');
        return [
            ':name' => $name,
            ':slug' => TrainingPolicy::slug($input['slug'] ?? $name),
            ':discipline' => TrainingPolicy::enum($input['discipline'] ?? '', TrainingPolicy::DISCIPLINES, 'Disciplina'),
            ':description' => TrainingPolicy::text($input['short_description'] ?? '', 500, 'Descripción', false),
            ':preparation' => TrainingPolicy::text($input['preparation'] ?? '', 5000, 'Preparación', false),
            ':instructions' => TrainingPolicy::text($input['execution_instructions'] ?? '', 8000, 'Ejecución', false),
            ':breathing' => TrainingPolicy::text($input['breathing'] ?? '', 3000, 'Respiración', false),
            ':errors' => TrainingPolicy::text($input['common_errors'] ?? '', 5000, 'Errores frecuentes', false),
            ':notes' => TrainingPolicy::text($input['technical_notes'] ?? '', 5000, 'Notas técnicas', false),
            ':muscle' => TrainingPolicy::optionalEnum($input['muscle_group'] ?? null, TrainingPolicy::MUSCLE_GROUPS, 'Grupo muscular'),
            ':equipment' => TrainingPolicy::optionalEnum($input['equipment'] ?? null, TrainingPolicy::EQUIPMENT, 'Equipamiento'),
            ':difficulty' => TrainingPolicy::enum($input['difficulty'] ?? 'INICIAL', TrainingPolicy::DIFFICULTIES, 'Dificultad'),
            ':execution_type' => TrainingPolicy::enum($input['execution_type'] ?? '', TrainingPolicy::EXECUTION_TYPES, 'Tipo de ejecución'),
        ];
    }

    private function templateData(array $input): array
    {
        $name = TrainingPolicy::text($input['name'] ?? '', 160, 'Nombre');
        return [
            'name'=>$name, 'slug'=>TrainingPolicy::slug($input['slug'] ?? $name),
            'description'=>TrainingPolicy::text($input['description'] ?? '', 5000, 'Descripción', false),
            'objective'=>TrainingPolicy::enum($input['objective'] ?? 'GENERAL', TrainingPolicy::OBJECTIVES, 'Objetivo'),
            'level'=>TrainingPolicy::enum($input['level'] ?? 'TODOS', TrainingPolicy::LEVELS, 'Nivel'),
            'days_per_week'=>TrainingPolicy::positiveInt($input['days_per_week'] ?? null, 'Días por semana', 7),
            'status'=>TrainingPolicy::enum($input['status'] ?? 'DRAFT', ['DRAFT','ACTIVE'], 'Estado'),
            'disciplines'=>TrainingPolicy::disciplines($input['disciplines'] ?? ['GENERAL']),
        ];
    }

    private function planData(array $input, array $member): array
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($input['start_date'] ?? ''));
        if (!$start || $start->format('Y-m-d') !== (string) ($input['start_date'] ?? '')) throw new InvalidArgumentException('Fecha de inicio no válida.');
        $endRaw = trim((string) ($input['end_date'] ?? ''));
        $end = $endRaw === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $endRaw);
        if ($endRaw !== '' && (!$end || $end->format('Y-m-d') !== $endRaw || $end < $start)) throw new InvalidArgumentException('Fecha de fin no válida.');
        return [
            'name'=>TrainingPolicy::text($input['name'] ?? '', 160, 'Nombre'),
            'objective'=>TrainingPolicy::enum($input['objective'] ?? 'GENERAL', TrainingPolicy::OBJECTIVES, 'Objetivo'),
            'start_date'=>$start->format('Y-m-d'), 'end_date'=>$end?->format('Y-m-d'),
            'notes'=>TrainingPolicy::text($input['notes'] ?? '', 5000, 'Notas', false),
            'disciplines'=>TrainingPolicy::disciplines($input['disciplines'] ?? ['GENERAL']),
            'site_id'=>(int)$member['id_gimnasio'], 'member_id'=>(int)$member['id_usuario'],
        ];
    }

    private function insertTemplateDay(int $templateId, array $day): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO training_template_day (id_training_template,id_empresa,name,day_order,objective,notes)
             VALUES (:template,:company,:name,:sort,:objective,:notes)'
        );
        $stmt->execute([
            ':template'=>$templateId,':company'=>$this->companyId,
            ':name'=>TrainingPolicy::text($day['name'] ?? '',120,'Nombre del día'),
            ':sort'=>TrainingPolicy::positiveInt($day['day_order'] ?? null,'Orden del día',31),
            ':objective'=>TrainingPolicy::optionalEnum($day['objective'] ?? null,TrainingPolicy::OBJECTIVES,'Objetivo del día'),
            ':notes'=>TrainingPolicy::text($day['notes'] ?? '',3000,'Notas del día',false),
        ]);
        $dayId=(int)$this->db->lastInsertId();
        foreach (($day['blocks'] ?? []) as $block) $this->insertTemplateBlock($dayId,$block);
        return $dayId;
    }

    private function insertTemplateBlock(int $dayId,array $block): int
    {
        $type=TrainingPolicy::enum($block['block_type']??'GENERAL',TrainingPolicy::BLOCK_TYPES,'Tipo de bloque');
        $rounds=$type==='CIRCUIT'?TrainingPolicy::positiveInt($block['circuit_rounds']??null,'Vueltas',1000):null;
        $stmt=$this->db->prepare(
            'INSERT INTO training_template_block
             (id_training_template_day,id_empresa,name,block_type,block_order,circuit_rounds,round_rest_seconds,notes)
             VALUES (:day,:company,:name,:type,:sort,:rounds,:rest,:notes)'
        );
        $stmt->execute([
            ':day'=>$dayId,':company'=>$this->companyId,':name'=>TrainingPolicy::text($block['name']??'',120,'Nombre del bloque'),
            ':type'=>$type,':sort'=>TrainingPolicy::positiveInt($block['block_order']??null,'Orden del bloque',64),
            ':rounds'=>$rounds,':rest'=>$type==='CIRCUIT'?TrainingPolicy::nonNegativeInt($block['round_rest_seconds']??0,'Descanso entre vueltas',86400,true):null,
            ':notes'=>TrainingPolicy::text($block['notes']??'',3000,'Notas del bloque',false),
        ]);
        $blockId=(int)$this->db->lastInsertId();
        foreach (($block['exercises']??[]) as $item) $this->insertTemplateExercise($blockId,$type,$item);
        return $blockId;
    }

    private function insertTemplateExercise(int $blockId,string $blockType,array $item): int
    {
        $exercise=$this->exerciseRow((int)($item['exercise_id']??0));
        $type=TrainingPolicy::enum($item['execution_type']??$exercise['execution_type'],TrainingPolicy::EXECUTION_TYPES,'Tipo de ejecución');
        if ($blockType==='CIRCUIT') $type='CIRCUIT';
        elseif ($type!==$exercise['execution_type']) throw new DomainException('El tipo no está permitido para el ejercicio.');
        $params=TrainingPolicy::executionParameters($type,$item);
        $columns=['id_training_template_block','id_empresa','id_training_exercise','exercise_catalog_scope','execution_type','item_order'];
        $values=[':block',':company',':exercise',':scope',':type',':sort'];
        $bindings=[':block'=>$blockId,':company'=>$this->companyId,':exercise'=>(int)$exercise['id_training_exercise'],':scope'=>(int)$exercise['catalog_scope'],':type'=>$type,':sort'=>TrainingPolicy::positiveInt($item['item_order']??null,'Orden del ejercicio',1000),':notes'=>TrainingPolicy::text($item['notes']??'',2000,'Notas',false)];
        foreach($params as $column=>$value){$columns[]=$column;$values[]=':'.$column;$bindings[':'.$column]=$value;}
        $columns[]='notes';$values[]=':notes';
        $stmt=$this->db->prepare('INSERT INTO training_template_exercise ('.implode(',',$columns).') VALUES ('.implode(',',$values).')');
        $stmt->execute($bindings);
        return (int)$this->db->lastInsertId();
    }

    private function insertPlan(array $data,array $member,?int $templateId): int
    {
        $stmt=$this->db->prepare(
            'INSERT INTO training_plan
             (id_empresa,id_gimnasio,id_socio,created_by,source_template_id,name,objective,start_date,end_date,status,notes)
             VALUES (:company,:site,:member,:actor,:template,:name,:objective,:start,:end,\'DRAFT\',:notes)'
        );
        $stmt->execute([':company'=>$this->companyId,':site'=>(int)$member['id_gimnasio'],':member'=>(int)$member['id_usuario'],':actor'=>$this->actorId,':template'=>$templateId,':name'=>$data['name'],':objective'=>$data['objective'],':start'=>$data['start_date'],':end'=>$data['end_date'],':notes'=>$data['notes']]);
        return (int)$this->db->lastInsertId();
    }

    private function cloneTemplateStructure(int $templateId,int $planId,array $member): void
    {
        foreach($this->templateDays($templateId) as $day){
            $insertDay=$this->db->prepare('INSERT INTO training_plan_day (id_training_plan,id_empresa,id_gimnasio,id_socio,name,day_order,objective,notes) VALUES (:plan,:company,:site,:member,:name,:sort,:objective,:notes)');
            $insertDay->execute([':plan'=>$planId,':company'=>$this->companyId,':site'=>$member['id_gimnasio'],':member'=>$member['id_usuario'],':name'=>$day['name'],':sort'=>$day['day_order'],':objective'=>$day['objective'],':notes'=>$day['notes']]);
            $planDay=(int)$this->db->lastInsertId();
            foreach($day['blocks'] as $block){
                $insertBlock=$this->db->prepare('INSERT INTO training_plan_block (id_training_plan_day,id_empresa,id_gimnasio,id_socio,name,block_type,block_order,circuit_rounds,round_rest_seconds,notes) VALUES (:day,:company,:site,:member,:name,:type,:sort,:rounds,:rest,:notes)');
                $insertBlock->execute([':day'=>$planDay,':company'=>$this->companyId,':site'=>$member['id_gimnasio'],':member'=>$member['id_usuario'],':name'=>$block['name'],':type'=>$block['block_type'],':sort'=>$block['block_order'],':rounds'=>$block['circuit_rounds'],':rest'=>$block['round_rest_seconds'],':notes'=>$block['notes']]);
                $planBlock=(int)$this->db->lastInsertId();
                foreach($block['exercises'] as $item){
                    $columns=['id_training_plan_block','id_empresa','id_gimnasio','id_socio','source_exercise_id','exercise_name','discipline','instructions','execution_type','item_order','sets_count','reps_count','load_kg','duration_seconds','rounds_count','round_duration_seconds','rest_seconds','distance_value','distance_unit','work_seconds','transition_seconds','notes'];
                    $placeholders=array_map(static fn(string $c):string=>':'.$c,$columns);
                    $values=['id_training_plan_block'=>$planBlock,'id_empresa'=>$this->companyId,'id_gimnasio'=>$member['id_gimnasio'],'id_socio'=>$member['id_usuario'],'source_exercise_id'=>$item['id_training_exercise'],'exercise_name'=>$item['exercise_name'],'discipline'=>$item['discipline'],'instructions'=>$item['execution_instructions'],'execution_type'=>$item['execution_type'],'item_order'=>$item['item_order'],'sets_count'=>$item['sets_count'],'reps_count'=>$item['reps_count'],'load_kg'=>$item['load_kg'],'duration_seconds'=>$item['duration_seconds'],'rounds_count'=>$item['rounds_count'],'round_duration_seconds'=>$item['round_duration_seconds'],'rest_seconds'=>$item['rest_seconds'],'distance_value'=>$item['distance_value'],'distance_unit'=>$item['distance_unit'],'work_seconds'=>$item['work_seconds'],'transition_seconds'=>$item['transition_seconds'],'notes'=>$item['notes']];
                    $insert=$this->db->prepare('INSERT INTO training_plan_exercise ('.implode(',',$columns).') VALUES ('.implode(',',$placeholders).')');
                    $bindings=[];foreach($values as $key=>$value)$bindings[':'.$key]=$value;$insert->execute($bindings);
                    $planExercise=(int)$this->db->lastInsertId();
                    $this->copyExerciseMediaToPlan($planExercise,(int)$item['id_training_exercise'],(int)$member['id_gimnasio'],(int)$member['id_usuario']);
                }
            }
        }
    }

    private function templateDays(int $templateId): array
    {
        $days=$this->db->prepare('SELECT * FROM training_template_day WHERE id_training_template=:template AND id_empresa=:company ORDER BY day_order');
        $days->execute([':template'=>$templateId,':company'=>$this->companyId]);
        $rows=$days->fetchAll(PDO::FETCH_ASSOC);
        if($rows===[])return [];
        $dayIds=array_map('intval',array_column($rows,'id_training_template_day'));
        [$dayIn,$dayParams]=$this->inParams($dayIds,'day');
        $blocks=$this->db->prepare('SELECT * FROM training_template_block WHERE id_training_template_day IN ('.$dayIn.') AND id_empresa=:company ORDER BY id_training_template_day,block_order');
        $blocks->execute($dayParams+[':company'=>$this->companyId]);$blockRows=$blocks->fetchAll(PDO::FETCH_ASSOC);
        $itemsByBlock=[];
        if($blockRows!==[]){
            $blockIds=array_map('intval',array_column($blockRows,'id_training_template_block'));
            [$blockIn,$blockParams]=$this->inParams($blockIds,'block');
            $items=$this->db->prepare('SELECT i.*,e.name exercise_name,e.discipline,e.execution_instructions FROM training_template_exercise i JOIN training_exercise e ON e.id_training_exercise=i.id_training_exercise AND e.catalog_scope=i.exercise_catalog_scope WHERE i.id_training_template_block IN ('.$blockIn.') AND i.id_empresa=:company ORDER BY i.id_training_template_block,i.item_order');
            $items->execute($blockParams+[':company'=>$this->companyId]);
            foreach($items->fetchAll(PDO::FETCH_ASSOC) as $item)$itemsByBlock[(int)$item['id_training_template_block']][]=$item;
        }
        $blocksByDay=[];foreach($blockRows as $block){$block['exercises']=$itemsByBlock[(int)$block['id_training_template_block']]??[];$blocksByDay[(int)$block['id_training_template_day']][]=$block;}
        foreach($rows as &$day)$day['blocks']=$blocksByDay[(int)$day['id_training_template_day']]??[];unset($day);
        return $rows;
    }

    private function planDays(int $planId,int $siteId,int $memberId): array
    {
        $days=$this->db->prepare('SELECT * FROM training_plan_day WHERE id_training_plan=:plan AND id_empresa=:company AND id_gimnasio=:site AND id_socio=:member ORDER BY day_order');
        $days->execute([':plan'=>$planId,':company'=>$this->companyId,':site'=>$siteId,':member'=>$memberId]);
        $rows=$days->fetchAll(PDO::FETCH_ASSOC);
        if($rows===[])return [];
        $dayIds=array_map('intval',array_column($rows,'id_training_plan_day'));[$dayIn,$dayParams]=$this->inParams($dayIds,'pday');
        $scope=[':company'=>$this->companyId,':site'=>$siteId,':member'=>$memberId];
        $blocks=$this->db->prepare('SELECT * FROM training_plan_block WHERE id_training_plan_day IN ('.$dayIn.') AND id_empresa=:company AND id_gimnasio=:site AND id_socio=:member ORDER BY id_training_plan_day,block_order');
        $blocks->execute($dayParams+$scope);$blockRows=$blocks->fetchAll(PDO::FETCH_ASSOC);
        $itemsByBlock=[];$mediaByItem=[];
        if($blockRows!==[]){
            $blockIds=array_map('intval',array_column($blockRows,'id_training_plan_block'));[$blockIn,$blockParams]=$this->inParams($blockIds,'pblock');
            $items=$this->db->prepare('SELECT * FROM training_plan_exercise WHERE id_training_plan_block IN ('.$blockIn.') AND id_empresa=:company AND id_gimnasio=:site AND id_socio=:member ORDER BY id_training_plan_block,item_order');
            $items->execute($blockParams+$scope);$itemRows=$items->fetchAll(PDO::FETCH_ASSOC);
            if($itemRows!==[]){
                $itemIds=array_map('intval',array_column($itemRows,'id_training_plan_exercise'));[$itemIn,$itemParams]=$this->inParams($itemIds,'pitem');
                $media=$this->db->prepare('SELECT * FROM training_plan_exercise_media WHERE id_training_plan_exercise IN ('.$itemIn.') AND id_empresa=:company AND id_gimnasio=:site AND id_socio=:member ORDER BY id_training_plan_exercise,sort_order');
                $media->execute($itemParams+$scope);foreach($media->fetchAll(PDO::FETCH_ASSOC) as $row)$mediaByItem[(int)$row['id_training_plan_exercise']][]=$row;
            }
            foreach($itemRows as $item){$item['media']=$mediaByItem[(int)$item['id_training_plan_exercise']]??[];$itemsByBlock[(int)$item['id_training_plan_block']][]=$item;}
        }
        $blocksByDay=[];foreach($blockRows as $block){$block['exercises']=$itemsByBlock[(int)$block['id_training_plan_block']]??[];$blocksByDay[(int)$block['id_training_plan_day']][]=$block;}
        foreach($rows as &$day)$day['blocks']=$blocksByDay[(int)$day['id_training_plan_day']]??[];unset($day);return $rows;
    }

    /** @param list<int> $ids @return array{0:string,1:array<string,int>} */
    private function inParams(array $ids,string $prefix):array
    {
        $marks=[];$params=[];foreach($ids as $index=>$id){$key=':'.$prefix.$index;$marks[]=$key;$params[$key]=$id;}
        return[implode(',',$marks),$params];
    }

    private function insertSessionResult(int $sessionId,array $result): void
    {
        $itemId=TrainingPolicy::positiveInt($result['plan_exercise_id']??null,'Ejercicio',PHP_INT_MAX);
        $typeQuery=$this->db->prepare(
            'SELECT e.execution_type FROM training_session s '
            . 'JOIN training_plan_day d ON d.id_training_plan=s.id_training_plan AND d.id_training_plan_day=s.id_training_plan_day '
            . 'AND d.id_empresa=s.id_empresa AND d.id_gimnasio=s.id_gimnasio AND d.id_socio=s.id_socio '
            . 'JOIN training_plan_block b ON b.id_training_plan_day=d.id_training_plan_day '
            . 'AND b.id_empresa=d.id_empresa AND b.id_gimnasio=d.id_gimnasio AND b.id_socio=d.id_socio '
            . 'JOIN training_plan_exercise e ON e.id_training_plan_block=b.id_training_plan_block '
            . 'AND e.id_training_plan_exercise=:item AND e.id_empresa=s.id_empresa '
            . 'AND e.id_gimnasio=s.id_gimnasio AND e.id_socio=s.id_socio '
            . 'WHERE s.id_training_session=:session AND s.id_empresa=:company FOR UPDATE'
        );
        $typeQuery->execute([':item'=>$itemId, ':session'=>$sessionId, ':company'=>$this->companyId]);
        $executionType=$typeQuery->fetchColumn();
        if($executionType===false)throw new DomainException('Resultado de ejercicio fuera de la sesión.');
        $allowedMetrics=match((string)$executionType){
            'REPS'=>['actual_reps','actual_load_kg'],
            'TIME'=>['actual_duration_seconds'],
            'ROUNDS'=>['actual_rounds','actual_duration_seconds'],
            'DISTANCE'=>['actual_duration_seconds'],
            'CIRCUIT','TECHNIQUE'=>['actual_rounds','actual_duration_seconds'],
            default=>[],
        };
        foreach(['actual_reps','actual_load_kg','actual_duration_seconds','actual_rounds'] as $metric){
            if(array_key_exists($metric,$result)&&$result[$metric]!==null&&$result[$metric]!==''&&!in_array($metric,$allowedMetrics,true)){
                throw new InvalidArgumentException('Métrica real incompatible con el tipo de ejecución.');
            }
        }
        $completed=TrainingPolicy::booleanFlag($result['completed']??0,'Ejercicio completado');
        $stmt=$this->db->prepare(
            'INSERT INTO training_session_exercise
             (id_training_session,id_empresa,id_gimnasio,id_socio,id_training_plan_exercise,completed,actual_reps,actual_load_kg,actual_duration_seconds,actual_rounds,notes)
             SELECT s.id_training_session,s.id_empresa,s.id_gimnasio,s.id_socio,e.id_training_plan_exercise,:completed,:reps,:load,:duration,:rounds,:notes
             FROM training_session s
             JOIN training_plan_day d ON d.id_training_plan=s.id_training_plan AND d.id_training_plan_day=s.id_training_plan_day
                  AND d.id_empresa=s.id_empresa AND d.id_gimnasio=s.id_gimnasio AND d.id_socio=s.id_socio
             JOIN training_plan_block b ON b.id_training_plan_day=d.id_training_plan_day
                  AND b.id_empresa=d.id_empresa AND b.id_gimnasio=d.id_gimnasio AND b.id_socio=d.id_socio
             JOIN training_plan_exercise e ON e.id_training_plan_block=b.id_training_plan_block
                  AND e.id_training_plan_exercise=:item AND e.id_empresa=s.id_empresa
                  AND e.id_gimnasio=s.id_gimnasio AND e.id_socio=s.id_socio
             WHERE s.id_training_session=:session AND s.id_empresa=:company'
        );
        $stmt->execute([':completed'=>$completed,':reps'=>TrainingPolicy::positiveInt($result['actual_reps']??null,'Repeticiones',10000,false),':load'=>TrainingPolicy::decimal($result['actual_load_kg']??null,'Carga',5,3),':duration'=>TrainingPolicy::positiveInt($result['actual_duration_seconds']??null,'Duración',86400,false),':rounds'=>TrainingPolicy::positiveInt($result['actual_rounds']??null,'Rounds',1000,false),':notes'=>TrainingPolicy::text($result['notes']??'',2000,'Notas',false),':item'=>$itemId,':session'=>$sessionId,':company'=>$this->companyId]);
        if($stmt->rowCount()!==1) throw new DomainException('Resultado de ejercicio fuera de la sesión.');
    }

    private function assertTemplateExecutable(int $templateId,int $expectedDays): void
    {
        $stmt=$this->db->prepare(
            'SELECT COUNT(DISTINCT d.id_training_template_day) day_count, '
            . 'COUNT(DISTINCT b.id_training_template_block) block_count, '
            . 'COUNT(i.id_training_template_exercise) item_count, '
            . 'SUM(NOT EXISTS(SELECT 1 FROM training_template_block bx WHERE bx.id_training_template_day=d.id_training_template_day AND bx.id_empresa=d.id_empresa)) empty_days, '
            . 'SUM(NOT EXISTS(SELECT 1 FROM training_template_exercise ix WHERE ix.id_training_template_block=b.id_training_template_block AND ix.id_empresa=b.id_empresa)) empty_blocks '
            . 'FROM training_template_day d '
            . 'LEFT JOIN training_template_block b ON b.id_training_template_day=d.id_training_template_day AND b.id_empresa=d.id_empresa '
            . 'LEFT JOIN training_template_exercise i ON i.id_training_template_block=b.id_training_template_block AND i.id_empresa=b.id_empresa '
            . 'WHERE d.id_training_template=:template AND d.id_empresa=:company'
        );
        $stmt->execute([':template'=>$templateId, ':company'=>$this->companyId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
        if((int)($row['day_count']??0)!==$expectedDays || (int)($row['block_count']??0)<1
            || (int)($row['item_count']??0)<1 || (int)($row['empty_days']??0)>0 || (int)($row['empty_blocks']??0)>0){
            throw new DomainException('La plantilla no tiene una estructura completa y ejecutable.');
        }
    }

    private function assertPlanExecutable(int $planId): void
    {
        $stmt=$this->db->prepare(
            'SELECT COUNT(DISTINCT d.id_training_plan_day) day_count, '
            . 'COUNT(DISTINCT b.id_training_plan_block) block_count, '
            . 'COUNT(i.id_training_plan_exercise) item_count, '
            . 'SUM(NOT EXISTS(SELECT 1 FROM training_plan_block bx WHERE bx.id_training_plan_day=d.id_training_plan_day AND bx.id_empresa=d.id_empresa)) empty_days, '
            . 'SUM(NOT EXISTS(SELECT 1 FROM training_plan_exercise ix WHERE ix.id_training_plan_block=b.id_training_plan_block AND ix.id_empresa=b.id_empresa)) empty_blocks '
            . 'FROM training_plan_day d '
            . 'LEFT JOIN training_plan_block b ON b.id_training_plan_day=d.id_training_plan_day AND b.id_empresa=d.id_empresa '
            . 'LEFT JOIN training_plan_exercise i ON i.id_training_plan_block=b.id_training_plan_block AND i.id_empresa=b.id_empresa '
            . 'WHERE d.id_training_plan=:plan AND d.id_empresa=:company'
        );
        $stmt->execute([':plan'=>$planId, ':company'=>$this->companyId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
        if((int)($row['day_count']??0)<1 || (int)($row['block_count']??0)<1 || (int)($row['item_count']??0)<1
            || (int)($row['empty_days']??0)>0 || (int)($row['empty_blocks']??0)>0){
            throw new DomainException('El plan no tiene una estructura completa y ejecutable.');
        }
    }

    private function member(int $memberId,bool $requireActive=true): array
    {
        if($memberId<=0)throw new InvalidArgumentException('Socio no válido.');
        $sql="SELECT * FROM usuario WHERE id_usuario=:member AND id_empresa=:company AND rol='socio'";
        if($requireActive)$sql.=' AND activo=1 AND anonimizado_en IS NULL';
        if($this->role==='admin')$sql.=' AND id_gimnasio=:site';
        $stmt=$this->db->prepare($sql);$params=[':member'=>$memberId,':company'=>$this->companyId];if($this->role==='admin')$params[':site']=$this->siteId;$stmt->execute($params);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);if(!$row)throw new DomainException('Socio no disponible en el ámbito autorizado.');return $row;
    }

    private function planForUpdate(int $planId): array
    {
        $sql='SELECT * FROM training_plan WHERE id_training_plan=:id AND id_empresa=:company';$params=[':id'=>$planId,':company'=>$this->companyId];
        if($this->role==='admin'){$sql.=' AND id_gimnasio=:site';$params[':site']=$this->siteId;}$sql.=' FOR UPDATE';
        $stmt=$this->db->prepare($sql);$stmt->execute($params);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!$row)throw new DomainException('Plan no disponible.');return $row;
    }

    private function templateForUpdate(int $templateId): array
    {
        $stmt=$this->db->prepare("SELECT * FROM training_template WHERE id_training_template=:id AND id_empresa=:company AND status<>'ARCHIVED' FOR UPDATE");
        $stmt->execute([':id'=>$templateId,':company'=>$this->companyId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new DomainException('Plantilla no disponible para edición.');
        return $row;
    }

    private function bumpTemplateVersion(int $templateId,int $version): void
    {
        $stmt=$this->db->prepare('UPDATE training_template SET version=version+1 WHERE id_training_template=:id AND id_empresa=:company AND version=:version');
        $stmt->execute([':id'=>$templateId,':company'=>$this->companyId,':version'=>$version]);
        if($stmt->rowCount()!==1)throw new DomainException('La plantilla cambió en otra sesión.');
    }

    private function assertPlanVersion(array $plan,int $expectedVersion): void
    {
        if($expectedVersion<1||(int)$plan['version']!==$expectedVersion)throw new DomainException('El plan cambió en otra sesión.');
    }

    private function bumpPlanVersion(int $planId,int $expectedVersion): void
    {
        $stmt=$this->db->prepare('UPDATE training_plan SET version=version+1 WHERE id_training_plan=:plan AND id_empresa=:company AND version=:version');
        $stmt->execute([':plan'=>$planId,':company'=>$this->companyId,':version'=>$expectedVersion]);
        if($stmt->rowCount()!==1)throw new DomainException('El plan cambió en otra sesión.');
    }

    private function exerciseRow(int $exerciseId): array
    {
        $stmt=$this->db->prepare('SELECT * FROM training_exercise WHERE id_training_exercise=:id AND active=1 AND (id_empresa=:company OR id_empresa IS NULL)');
        $stmt->execute([':id'=>$exerciseId,':company'=>$this->companyId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!$row)throw new DomainException('Ejercicio no disponible.');return $row;
    }

    private function ownedExercise(int $exerciseId): array
    {
        $stmt=$this->db->prepare('SELECT * FROM training_exercise WHERE id_training_exercise=:id AND id_empresa=:company');$stmt->execute([':id'=>$exerciseId,':company'=>$this->companyId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!$row)throw new DomainException('El catálogo global es de solo lectura o el ejercicio no pertenece a la empresa.');return $row;
    }

    private function insertDisciplines(string $table,string $idColumn,int $id,array $disciplines,?array $member=null): void
    {
        $columns=[$idColumn,'id_empresa'];$values=[':id',':company'];$base=[':id'=>$id,':company'=>$this->companyId];
        if($table==='training_plan_discipline'){$columns[]='id_gimnasio';$columns[]='id_socio';$values[]=':site';$values[]=':member';$base[':site']=$member['id_gimnasio'];$base[':member']=$member['id_usuario'];}
        $columns[]='discipline';$values[]=':discipline';$stmt=$this->db->prepare('INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES ('.implode(',',$values).')');
        foreach($disciplines as $discipline)$stmt->execute($base+[':discipline'=>$discipline]);
    }

    private function disciplinesFor(string $table,string $idColumn,int $id): array
    {
        $stmt=$this->db->prepare('SELECT discipline FROM '.$table.' WHERE '.$idColumn.'=:id AND id_empresa=:company ORDER BY discipline');$stmt->execute([':id'=>$id,':company'=>$this->companyId]);return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function nextMediaOrder(int $exerciseId): int
    {
        $stmt=$this->db->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM training_exercise_media WHERE id_training_exercise=:id');$stmt->execute([':id'=>$exerciseId]);$order=(int)$stmt->fetchColumn();if($order>65535)throw new DomainException('Demasiados medios asociados.');return $order;
    }

    private function copyExerciseMediaToPlan(int $planExerciseId,int $exerciseId,int $siteId,int $memberId): void
    {
        $media=$this->db->prepare('SELECT media_type,storage_key,external_url,mime_type,sort_order,alt_text,source,license,attribution FROM training_exercise_media WHERE id_training_exercise=:exercise ORDER BY sort_order');
        $media->execute([':exercise'=>$exerciseId]);
        $copy=$this->db->prepare('INSERT INTO training_plan_exercise_media (id_training_plan_exercise,id_empresa,id_gimnasio,id_socio,media_type,storage_key,external_url,mime_type,sort_order,alt_text,source,license,attribution) VALUES (:item,:company,:site,:member,:type,:key,:url,:mime,:sort,:alt,:source,:license,:attribution)');
        foreach($media->fetchAll(PDO::FETCH_ASSOC) as $item){
            $copy->execute([':item'=>$planExerciseId,':company'=>$this->companyId,':site'=>$siteId,':member'=>$memberId,':type'=>$item['media_type'],':key'=>$item['storage_key'],':url'=>$item['external_url'],':mime'=>$item['mime_type'],':sort'=>$item['sort_order'],':alt'=>$item['alt_text'],':source'=>$item['source'],':license'=>$item['license'],':attribution'=>$item['attribution']]);
        }
    }

    private function authorizedSiteFilter(?int $requested): ?int
    {
        if($this->role==='admin')return $this->siteId;
        if($requested===null||$requested<=0)return null;$this->assertSite($requested);return $requested;
    }

    private function assertSite(int $siteId): void
    {
        $stmt=$this->db->prepare('SELECT 1 FROM gimnasio WHERE id_gimnasio=:site AND id_empresa=:company');$stmt->execute([':site'=>$siteId,':company'=>$this->companyId]);if(!$stmt->fetchColumn())throw new DomainException('Sede fuera de la empresa.');
    }

    private function assertPermission(string $permission): void
    {
        if(!Authorization::can($this->role,$permission))throw new DomainException('No autorizado para Training.');
    }

    private function write(callable $operation): mixed
    {
        $lease=TenantLifecyclePolicy::acquireBusinessWrite($this->db,$this->companyId);
        try{$this->db->beginTransaction();$result=$operation();$this->db->commit();return $result;}
        catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
        finally{$lease->release();}
    }

    private function auditRequired(string $action,string $entity,int $entityId,?string $before,?string $after,?int $affected=null,?int $site=null): void
    {
        $this->audit->registrarCambio($this->actorId,$action,'Operación Training',$affected,$entity,$entityId,$before,$after,$site??$this->siteId,'exito',$action,[], 'usuario','WEB',AuditPolicy::REQUIRED);
    }

    private function pagination(int $page,int $perPage): array
    {
        $page=max(1,min(10000,$page));$perPage=max(1,min(50,$perPage));return[$page,$perPage,($page-1)*$perPage];
    }

    private function pageResult(array $items,int $total,int $page,int $perPage): array
    {
        return['items'=>$items,'total'=>$total,'page'=>$page,'per_page'=>$perPage,'pages'=>max(1,(int)ceil($total/$perPage))];
    }
}
