<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AppLogger.php';
require_once __DIR__ . '/../helpers/TenantLifecyclePolicy.php';
require_once __DIR__ . '/../helpers/SafeException.php';
require_once __DIR__ . '/MigrationException.php';
require_once __DIR__ . '/MigrationStorage.php';
require_once __DIR__ . '/CsvImportReader.php';
require_once __DIR__ . '/ImportFieldMapper.php';
require_once __DIR__ . '/ImportNormalizer.php';
require_once __DIR__ . '/ImportBackupGuard.php';

/**
 * Motor de importación ligado a un tenant calculado por el servidor.
 * Nunca lee empresa/sede desde el archivo.
 */
final class MigrationService
{
    private PDO $db;
    private MigrationStorage $storage;
    private CsvImportReader $reader;
    private ?string $disabledMemberPasswordHash = null;

    public function __construct(
        private int $companyId,
        private ?int $siteId,
        private int $userId,
        ?PDO $db = null,
        ?MigrationStorage $storage = null,
        private $backupVerifier = null
    ) {
        $this->db = $db ?: Database::getInstance()->getConnection();
        $this->storage = $storage ?: new MigrationStorage();
        $this->reader = new CsvImportReader();
        $this->validateContext();
    }

    private function validateContext(): void
    {
        if ($this->companyId <= 0 || $this->userId <= 0) {
            throw new MigrationException('Contexto de importación incompleto.', 'invalid_context');
        }
        $stmt = $this->db->prepare('SELECT estado,onboarding_state FROM empresa WHERE id_empresa=:e');
        $stmt->execute([':e' => $this->companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company || !TenantLifecyclePolicy::allows($company, TenantLifecyclePolicy::WRITE)) {
            throw new MigrationException('Empresa no autorizada.', 'invalid_context');
        }
        if ($this->siteId !== null) {
            $stmt = $this->db->prepare('SELECT 1 FROM gimnasio WHERE id_gimnasio=:s AND id_empresa=:e AND activo=1');
            $stmt->execute([':s' => $this->siteId, ':e' => $this->companyId]);
            if (!$stmt->fetchColumn()) throw new MigrationException('Sede no autorizada.', 'invalid_site');
        }
        $stmt = $this->db->prepare("SELECT rol,id_empresa FROM usuario WHERE id_usuario=:u AND activo=1");
        $stmt->execute([':u' => $this->userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $isPlatform = $user
            && $user['rol'] === 'superadmin'
            && ($user['id_empresa'] ?? null) === null;
        $isTenantDirection = $user
            && $user['rol'] === 'direccion'
            && (int) $user['id_empresa'] === $this->companyId;
        if (!$isPlatform && !$isTenantDirection) {
            throw new MigrationException('Usuario fuera del tenant.', 'invalid_context');
        }
    }

    private function requireSite(): int
    {
        if ($this->siteId === null) {
            throw new MigrationException('Selecciona una sede antes de preparar la importación.', 'site_required');
        }
        return $this->siteId;
    }

    public function createFromUpload(string $entity, string $source, string $originalName, array $file, bool $force = false): array
    {
        $tenantLifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->companyId);
        $stored = $this->storage->storeUploaded($file);
        return $this->createStored($entity, $source, $originalName, $stored, $force);
    }

    /** Entrada de CLI y tests; una petición web no puede suministrar rutas. */
    public function createFromPath(string $entity, string $source, string $originalName, string $path, bool $force = false): array
    {
        $tenantLifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->companyId);
        $stored = $this->storage->storePath($path);
        return $this->createStored($entity, $source, $originalName, $stored, $force);
    }

    private function createStored(string $entity, string $source, string $originalName, array $stored, bool $force): array
    {
        try {
            $site = $this->requireSite();
            if (!in_array($entity, ImportFieldMapper::supportedEntities(), true)) {
                throw new MigrationException('Tipo de importación no soportado.', 'unsupported_entity');
            }
            $source = strtolower(trim($source));
            if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $source)) {
                throw new MigrationException('La fuente de importación no es válida.', 'invalid_source');
            }
            $logical = basename(str_replace('\\', '/', $originalName));
            $logical = preg_replace('/[\x00-\x1F\x7F]/u', '', $logical) ?? '';
            if ($logical === '' || mb_strlen($logical) > 255) {
                throw new MigrationException('El nombre lógico del archivo no es válido.', 'invalid_filename');
            }
            $inspection = $this->reader->inspect($stored['path'], $logical);
            $previous = $this->findSameFile($entity, $source, $stored['hash']);
            if ($previous && !$force) {
                $this->storage->delete($stored['storage_key']);
                $previous['already_processed'] = true;
                return $previous;
            }
            $attempt = $previous ? ((int) $previous['attempt_no'] + 1) : 1;
            $uuid = self::uuidV4();
            $mapping = ImportFieldMapper::infer($inspection['headers'], $entity);
            $stmt = $this->db->prepare(
                'INSERT INTO migration_batch
                 (uuid,id_empresa,id_gimnasio,id_usuario,source_system,entity_type,
                  original_name,storage_key,file_hash,file_size,attempt_no,delimiter,
                  headers_json,mapping_json,options_json,expires_at)
                 VALUES
                 (:uuid,:empresa,:sede,:usuario,:fuente,:entidad,:nombre,:storage,
                  :hash,:tamano,:intento,:delimitador,:cabeceras,:mapeo,:opciones,
                  :expira)'
            );
            $stmt->execute([
                ':uuid' => $uuid, ':empresa' => $this->companyId, ':sede' => $site,
                ':usuario' => $this->userId, ':fuente' => $source, ':entidad' => $entity,
                ':nombre' => $logical, ':storage' => $stored['storage_key'], ':hash' => $stored['hash'],
                ':tamano' => $stored['size'], ':intento' => $attempt, ':delimitador' => $inspection['delimiter'],
                ':cabeceras' => self::json($inspection['headers']), ':mapeo' => self::json($mapping),
                ':opciones' => self::json(['date_format' => 'Y-m-d']),
                ':expira' => date('Y-m-d H:i:s', time() + IMPORT_RETENTION_DAYS * 86400),
            ]);
            return $this->getBatch($uuid);
        } catch (Throwable $e) {
            if (isset($stored['storage_key'])) $this->storage->delete($stored['storage_key']);
            throw $e;
        }
    }

    private function findSameFile(string $entity, string $source, string $hash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM migration_batch
             WHERE id_empresa=:e AND source_system=:s AND entity_type=:t AND file_hash=:h
             ORDER BY attempt_no DESC LIMIT 1'
        );
        $stmt->execute([':e'=>$this->companyId, ':s'=>$source, ':t'=>$entity, ':h'=>$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decodeBatch($row) : null;
    }

    public function dryRun(string $uuid, array $mapping, array $options = []): array
    {
        $tenantLifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->companyId);
        $batch = $this->getBatch($uuid);
        if (!in_array($batch['status'], ['uploaded','dry_run_ready','failed'], true)) {
            throw new MigrationException('El batch ya no admite otra simulación.', 'invalid_batch_state');
        }
        $headers = $batch['headers'];
        $mapping = ImportFieldMapper::validate($headers, $batch['entity_type'], $mapping);
        $options = ['date_format' => (($options['date_format'] ?? 'Y-m-d') === 'd/m/Y' ? 'd/m/Y' : 'Y-m-d')];
        $path = $this->storage->verify((string) $batch['storage_key'], (string) $batch['file_hash']);
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM migration_batch_issue WHERE id_batch=:b')->execute([':b'=>$batch['id_batch']]);
            $this->db->prepare('DELETE FROM migration_batch_row WHERE id_batch=:b')->execute([':b'=>$batch['id_batch']]);
            $seen = [];
            $seenIdentifiers = ['dni'=>[],'email'=>[],'telefono'=>[]];
            $counts = ['rows'=>0,'valid'=>0,'warnings'=>0,'errors'=>0];
            $blockedHeaders = ImportFieldMapper::prohibitedHeaders($headers);
            if ($blockedHeaders) {
                $this->issue((int) $batch['id_batch'], 1, 'WARNING', null, 'tenant_columns_ignored',
                    'Las columnas de empresa/sede del archivo se ignoran para autorización.', null,
                    'Se utilizará exclusivamente el TenantContext del proceso.');
                $counts['warnings']++;
            }
            foreach ($this->reader->rows($path, $headers, $batch['delimiter']) as $sourceRow) {
                $counts['rows']++;
                $number = (int) $sourceRow['row_number'];
                $projected = ImportFieldMapper::project($sourceRow['values'], $mapping);
                $normalized = ImportNormalizer::normalize($batch['entity_type'], $projected, $options);
                $errors = $normalized['errors'];
                $warnings = $normalized['warnings'];
                if ($sourceRow['extra_columns']) {
                    $errors[] = ['field'=>null,'code'=>'extra_columns','message'=>'La fila contiene más columnas que el encabezado.'];
                }
                $external = (string) ($normalized['data']['external_id'] ?? '');
                if ($external !== '' && isset($seen[$external])) {
                    $errors[] = ['field'=>'external_id','code'=>'duplicate_external_id','message'=>'El identificador externo está repetido en el archivo.'];
                }
                if ($external !== '') $seen[$external] = true;
                $duplicatePhone = false;
                foreach (['dni','email','telefono'] as $identifier) {
                    $value = (string)($normalized['data'][$identifier] ?? '');
                    if ($value === '') continue;
                    $key = mb_strtolower($value);
                    if (isset($seenIdentifiers[$identifier][$key])) {
                        if ($identifier === 'telefono') $duplicatePhone = true;
                        else $errors[] = ['field'=>$identifier,'code'=>'duplicate_identifier','message'=>'El identificador está repetido en el archivo.'];
                    }
                    $seenIdentifiers[$identifier][$key] = true;
                }
                if ($errors) {
                    foreach ($errors as $error) {
                        $this->issue((int)$batch['id_batch'],$number,'ERROR',$error['field'],$error['code'],$error['message'],
                            $this->sourceValue($projected,$error['field']),'Corregir el archivo y ejecutar otro dry-run.');
                        $counts['errors']++;
                    }
                    $classification = 'INVALID'; $status = 'rejected'; $internalId = null;
                } else {
                    $decision = $this->classify($batch, $normalized['data']);
                    if ($duplicatePhone && $decision['status'] === 'ready') {
                        $decision['classification'] = 'POSSIBLE_DUPLICATE';
                        $decision['status'] = 'review';
                        $decision['issues'][] = self::decisionIssue('WARNING','telefono','duplicate_phone_in_file',
                            'El teléfono aparece en más de una fila del archivo.',
                            'Revisar manualmente; la fila no se importará automáticamente.');
                    }
                    $normalized['data'] = $decision['data'];
                    $classification = $decision['classification'];
                    $status = $decision['status'];
                    $internalId = $decision['internal_id'];
                    foreach ($decision['issues'] as $warning) {
                        $this->issue((int)$batch['id_batch'],$number,$warning['severity'],$warning['field'],$warning['code'],
                            $warning['message'],$this->sourceValue($projected,$warning['field']),$warning['action']);
                        $counts[strtolower($warning['severity']) . 's']++;
                    }
                    if (in_array($status, ['ready','link'], true)) $counts['valid']++;
                }
                foreach ($warnings as $warning) {
                    $this->issue((int)$batch['id_batch'],$number,'WARNING',$warning['field'] ?? null,$warning['code'],$warning['message'],null,null);
                    $counts['warnings']++;
                }
                $stmt = $this->db->prepare(
                    'INSERT INTO migration_batch_row
                     (id_batch,`row_number`,row_hash,external_id,classification,status,normalized_json,internal_id)
                     VALUES (:b,:n,:h,:x,:c,:s,:j,:i)'
                );
                $stmt->execute([
                    ':b'=>$batch['id_batch'], ':n'=>$number,
                    ':h'=>hash('sha256', self::json($sourceRow['values'])), ':x'=>$external ?: null,
                    ':c'=>$classification, ':s'=>$status, ':j'=>self::json($normalized['data']), ':i'=>$internalId,
                ]);
            }
            $stmt = $this->db->prepare(
                "UPDATE migration_batch SET status='dry_run_ready',mode='dry_run',mapping_json=:m,options_json=:o,
                 row_count=:r,valid_count=:v,warning_count=:w,error_count=:e,imported_count=0,linked_count=0,
                 last_committed_row=0,failure_code=NULL,started_at=NOW(),finished_at=NOW()
                 WHERE id_batch=:b"
            );
            $stmt->execute([':m'=>self::json($mapping),':o'=>self::json($options),':r'=>$counts['rows'],
                ':v'=>$counts['valid'],':w'=>$counts['warnings'],':e'=>$counts['errors'],':b'=>$batch['id_batch']]);
            $this->db->commit();
            return $this->report($uuid);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            AppLogger::error('migration_dry_run_failed', array_merge(
                ['batch'=>$uuid,'company_id'=>$this->companyId], SafeException::context($e, 'MigrationService.dryRun')
            ));
            throw $e instanceof MigrationException ? $e : new MigrationException('No se pudo completar el dry-run.', 'dry_run_failed');
        }
    }

    private function classify(array $batch, array $data): array
    {
        return match ($batch['entity_type']) {
            'socios' => $this->classifyMember($batch, $data),
            'productos' => $this->classifyProduct($batch, $data),
            'membresias' => $this->classifyMembership($batch, $data),
            default => throw new MigrationException('Entidad no soportada.', 'unsupported_entity'),
        };
    }

    private function mappedInternal(array $batch, string $externalId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT internal_id FROM migration_entity_map
             WHERE id_empresa=:e AND source_system=:s AND entity_type=:t AND external_id=:x LIMIT 1'
        );
        $stmt->execute([':e'=>$this->companyId,':s'=>$batch['source_system'],':t'=>$batch['entity_type'],':x'=>$externalId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function classifyMember(array $batch, array $data): array
    {
        $mapped = $this->mappedInternal($batch, (string)$data['external_id']);
        if ($mapped !== null) {
            $existing = $this->memberById($mapped);
            if (!$existing) return $this->conflict($data,'external_id','stale_external_map','El mapa externo no apunta a un socio del tenant.');
            return $this->compareMember($data, $existing);
        }
        $stmt = $this->db->prepare(
            "SELECT id_usuario,nombre,apellidos,dni,email,telefono,id_gimnasio,activo
             FROM usuario WHERE id_empresa=:e AND rol='socio' AND (dni=:dni OR email=:email)"
        );
        $stmt->execute([':e'=>$this->companyId,':dni'=>$data['dni'],':email'=>$data['email']]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_values(array_unique(array_map(static fn($r)=>(int)$r['id_usuario'],$matches)));
        if (count($ids) > 1) return $this->conflict($data,null,'identifier_conflict','DNI y email corresponden a socios distintos.');
        if (count($ids) === 1) return $this->compareMember($data, $matches[0]);
        if ($data['telefono']) {
            $stmt = $this->db->prepare(
                "SELECT id_usuario FROM usuario WHERE id_empresa=:e AND rol='socio' AND id_gimnasio=:g
                 AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefono,' ',''),'-',''),'(',''),')',''),'+',''),'.','')=:p LIMIT 2"
            );
            $stmt->execute([':e'=>$this->companyId,':g'=>$this->siteId,':p'=>ltrim((string)$data['telefono'],'+')]);
            if ($stmt->fetchAll(PDO::FETCH_COLUMN)) {
                return ['classification'=>'POSSIBLE_DUPLICATE','status'=>'review','internal_id'=>null,'data'=>$data,'issues'=>[self::decisionIssue(
                    'WARNING','telefono','possible_duplicate','Existe un socio del tenant con el mismo teléfono.','Revisar manualmente; no se fusionará ni importará automáticamente.'
                )]];
            }
        }
        return ['classification'=>'NEW','status'=>'ready','internal_id'=>null,'data'=>$data,'issues'=>[]];
    }

    private function memberById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id_usuario,nombre,apellidos,dni,email,telefono,id_gimnasio,activo
             FROM usuario WHERE id_usuario=:id AND id_empresa=:e AND rol='socio' LIMIT 1"
        );
        $stmt->execute([':id'=>$id,':e'=>$this->companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function compareMember(array $data, array $existing): array
    {
        if ((int)$existing['id_gimnasio'] !== $this->siteId) {
            return $this->conflict($data,'sede_externa','different_site','La coincidencia pertenece a otra sede de la empresa.');
        }
        $differences = [];
        foreach (['nombre','apellidos','dni','email'] as $field) {
            $a = mb_strtolower(trim((string)$data[$field]));
            $b = mb_strtolower(trim((string)$existing[$field]));
            if ($a !== $b) $differences[] = $field;
        }
        $existingPhone = InputValidator::phone($existing['telefono'] ?? '') ?: null;
        if (($data['telefono'] ?? null) !== $existingPhone) $differences[] = 'telefono';
        if ($differences) {
            return $this->conflict($data,implode(',',$differences),'existing_values_differ',
                'El socio coincide por identificador, pero contiene valores diferentes.');
        }
        return ['classification'=>'SAFE_MATCH','status'=>'link','internal_id'=>(int)$existing['id_usuario'],'data'=>$data,'issues'=>[self::decisionIssue(
            'WARNING','external_id','safe_match','Se vinculará con un socio idéntico existente.','No se sobrescribirá ningún dato del socio.'
        )]];
    }

    private function classifyProduct(array $batch, array $data): array
    {
        $mapped = $this->mappedInternal($batch, (string)$data['external_id']);
        if ($mapped !== null) {
            $stmt = $this->db->prepare(
                'SELECT p.id_producto,p.nombre,p.precio,p.stock,p.estado,p.id_gimnasio
                 FROM producto p JOIN gimnasio g ON g.id_gimnasio=p.id_gimnasio
                 WHERE p.id_producto=:id AND p.id_gimnasio=:s AND g.id_empresa=:e LIMIT 1'
            );
            $stmt->execute([':id'=>$mapped,':s'=>$this->siteId,':e'=>$this->companyId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) return $this->conflict($data,'external_id','stale_external_map','El mapa externo no apunta a un producto del tenant.');
            $same = mb_strtolower(trim($existing['nombre'])) === mb_strtolower(trim($data['nombre']))
                && (string)$existing['precio'] === (string)$data['precio']
                && (int)$existing['stock'] === (int)$data['stock']
                && (string)$existing['estado'] === (string)$data['estado'];
            if (!$same) return $this->conflict($data,null,'existing_values_differ','El producto ya mapeado contiene valores diferentes.');
            return ['classification'=>'SAFE_MATCH','status'=>'link','internal_id'=>$mapped,'data'=>$data,'issues'=>[self::decisionIssue(
                'WARNING','external_id','safe_match','Se reutilizará el producto ya mapeado.','No se sobrescribirá el producto.'
            )]];
        }
        $issues = [];
        if ($data['categoria']) {
            $stmt = $this->db->prepare(
                'SELECT id_categoria FROM categoria_producto
                  WHERE id_empresa=:e AND nombre_categoria=:n LIMIT 2'
            );
            $stmt->execute([':e'=>$this->companyId, ':n'=>$data['categoria']]);
            $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($categories) === 1) $data['id_categoria'] = (int)$categories[0];
            else {
                $data['id_categoria'] = null;
                $issues[] = self::decisionIssue('WARNING','categoria','category_not_linked',
                    'La categoría no tiene una coincidencia única.','El producto se importará sin categoría.');
            }
        } else $data['id_categoria'] = null;
        $stmt = $this->db->prepare('SELECT id_producto FROM producto WHERE id_gimnasio=:s AND LOWER(nombre)=LOWER(:n) LIMIT 2');
        $stmt->execute([':s'=>$this->siteId,':n'=>$data['nombre']]);
        if ($stmt->fetchAll(PDO::FETCH_COLUMN)) {
            $issues[] = self::decisionIssue('WARNING','nombre','possible_duplicate','Existe un producto con el mismo nombre en la sede.',
                'Revisar manualmente; no se importará automáticamente.');
            return ['classification'=>'POSSIBLE_DUPLICATE','status'=>'review','internal_id'=>null,'data'=>$data,'issues'=>$issues];
        }
        return ['classification'=>'NEW','status'=>'ready','internal_id'=>null,'data'=>$data,'issues'=>$issues];
    }

    private function classifyMembership(array $batch, array $data): array
    {
        $memberBatch = $batch;
        $memberBatch['entity_type'] = 'socios';
        $memberId = $this->mappedInternal($memberBatch, (string)$data['socio_external_id']);
        $member = $memberId ? $this->memberById($memberId) : null;
        if (!$member || (int)$member['id_gimnasio'] !== $this->siteId) {
            return $this->conflict($data,'socio_external_id','missing_member_reference',
                'No existe un mapa seguro del socio externo en esta sede.');
        }
        $stmt = $this->db->prepare(
            'SELECT id_tipo_membresia FROM tipo_membresia
             WHERE id_empresa=:e AND (id_gimnasio IS NULL OR id_gimnasio=:g) AND nombre=:n LIMIT 2'
        );
        $stmt->execute([':e'=>$this->companyId,':g'=>$this->siteId,':n'=>$data['tarifa']]);
        $types = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($types) !== 1) {
            return $this->conflict($data,'tarifa','missing_tariff_reference',
                'La tarifa no tiene una correspondencia única dentro de la empresa.');
        }
        $data['id_socio'] = $memberId;
        $data['id_tipo_membresia'] = (int)$types[0];
        return ['classification'=>'NEW','status'=>'review','internal_id'=>null,'data'=>$data,'issues'=>[
            self::decisionIssue('WARNING',null,'dry_run_only',
                'Las referencias son válidas, pero la importación de membresías está deshabilitada.',
                'Revisar precio histórico, estado y política de cobro antes de habilitarla.')
        ]];
    }

    private function conflict(array $data, ?string $field, string $code, string $message): array
    {
        return ['classification'=>'CONFLICT','status'=>'rejected','internal_id'=>null,'data'=>$data,'issues'=>[
            self::decisionIssue('ERROR',$field,$code,$message,'Resolver manualmente; no se modificará el registro existente.')
        ]];
    }

    public function confirm(string $uuid, int $chunkSize = 250, ?callable $beforeRow = null): array
    {
        $tenantLifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->companyId);
        $chunkSize = max(1, min(500, $chunkSize));
        $batch = $this->getBatch($uuid);
        if ($batch['entity_type'] === 'membresias') {
            throw new MigrationException('Las membresías están disponibles solo en dry-run.', 'dry_run_only');
        }
        if (in_array($batch['status'], ['completed','completed_with_warnings'], true)) return $this->report($uuid);
        if (!in_array($batch['status'], ['dry_run_ready','partial','failed'], true) || (int)$batch['error_count'] > 0) {
            throw new MigrationException('El batch no puede confirmarse mientras tenga errores o un estado no válido.', 'batch_not_confirmable');
        }
        $this->storage->verify((string)$batch['storage_key'], (string)$batch['file_hash']);
        $backup = is_callable($this->backupVerifier) ? ($this->backupVerifier)($batch) : ImportBackupGuard::verify();
        if (!is_array($backup) || empty($backup['reference']) || empty($backup['verified_at'])) {
            throw new MigrationException('La verificación previa de backup no es válida.', 'backup_required');
        }
        $this->db->prepare(
            "UPDATE migration_batch SET status='importing',mode='import',backup_reference=:r,
             backup_verified_at=:v,started_at=COALESCE(started_at,NOW()),failure_code=NULL WHERE id_batch=:b"
        )->execute([':r'=>mb_substr((string)$backup['reference'],0,255),':v'=>$backup['verified_at'],':b'=>$batch['id_batch']]);
        try {
            while (true) {
                $stmt = $this->db->prepare(
                    "SELECT * FROM migration_batch_row WHERE id_batch=:b AND status IN ('ready','link')
                     ORDER BY `row_number` LIMIT {$chunkSize}"
                );
                $stmt->execute([':b'=>$batch['id_batch']]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) break;
                $this->db->beginTransaction();
                $created = $linked = 0; $last = 0;
                try {
                    foreach ($rows as $row) {
                        if ($beforeRow) $beforeRow($row);
                        $data = json_decode($row['normalized_json'], true, 512, JSON_THROW_ON_ERROR);
                        if ($row['status'] === 'link') {
                            $internal = (int)$row['internal_id'];
                            $this->addMap($batch, (string)$row['external_id'], $internal);
                            $this->markRow((int)$row['id_row'], 'linked', $internal);
                            $linked++;
                        } else {
                            $internal = $batch['entity_type'] === 'socios'
                                ? $this->insertMember($batch, $data, (int)$row['row_number'])
                                : $this->insertProduct($batch, $data);
                            $this->addMap($batch, (string)$row['external_id'], $internal);
                            $this->markRow((int)$row['id_row'], 'imported', $internal);
                            $created++;
                        }
                        $last = (int)$row['row_number'];
                    }
                    $this->db->prepare(
                        'UPDATE migration_batch SET imported_count=imported_count+:c,linked_count=linked_count+:l,
                         last_committed_row=:r WHERE id_batch=:b'
                    )->execute([':c'=>$created,':l'=>$linked,':r'=>$last,':b'=>$batch['id_batch']]);
                    $this->auditChunk($batch, $last, $created, $linked);
                    $this->db->commit();
                } catch (Throwable $e) {
                    if ($this->db->inTransaction()) $this->db->rollBack();
                    throw $e;
                }
            }
            $remaining = $this->db->prepare("SELECT COUNT(*) FROM migration_batch_row WHERE id_batch=:b AND status='review'");
            $remaining->execute([':b'=>$batch['id_batch']]);
            $final = ((int)$remaining->fetchColumn() > 0 || (int)$batch['warning_count'] > 0)
                ? 'completed_with_warnings' : 'completed';
            $this->db->prepare(
                "UPDATE migration_batch SET status=:s,finished_at=NOW(),failure_code=NULL,storage_key=NULL WHERE id_batch=:b"
            )->execute([':s'=>$final,':b'=>$batch['id_batch']]);
            $this->storage->delete($batch['storage_key']);
            return $this->report($uuid);
        } catch (Throwable $e) {
            $current = $this->getBatch($uuid);
            $state = ((int)$current['imported_count'] + (int)$current['linked_count']) > 0 ? 'partial' : 'failed';
            $code = $e instanceof MigrationException ? $e->safeCode() : 'chunk_failed';
            $this->db->prepare('UPDATE migration_batch SET status=:s,failure_code=:f WHERE id_batch=:b')
                ->execute([':s'=>$state,':f'=>$code,':b'=>$batch['id_batch']]);
            AppLogger::error('migration_import_failed', array_merge([
                'batch'=>$uuid,'company_id'=>$this->companyId,'site_id'=>$this->siteId,
                'last_committed_row'=>$current['last_committed_row'],
            ], SafeException::context($e, 'MigrationService.confirm')));
            throw $e instanceof MigrationException ? $e : new MigrationException('Falló un lote; los lotes anteriores siguen confirmados.', 'chunk_failed');
        }
    }

    private function insertMember(array $batch, array $data, int $rowNumber): int
    {
        $username = sprintf('imp_%d_%s_%d', $this->companyId, substr(str_replace('-','',$batch['uuid']),0,8), $rowNumber);
        // El portal de socio no está habilitado. Se usa un secreto aleatorio
        // imposible de conocer y se descarta; cualquier acceso futuro exigirá
        // establecer una contraseña mediante su flujo específico.
        $this->disabledMemberPasswordHash ??= password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost'=>12]);
        $password = $this->disabledMemberPasswordHash;
        $createdAt = $data['fecha_alta'] ? $data['fecha_alta'] . ' 00:00:00' : date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "INSERT INTO usuario
             (id_empresa,nombre,apellidos,dni,telefono,email,nombre_usuario,contrasena,activo,rol,id_gimnasio,created_at)
             VALUES (:e,:n,:a,:d,:t,:m,:u,:p,:activo,'socio',:g,:fecha)"
        );
        try {
            $stmt->execute([':e'=>$this->companyId,':n'=>$data['nombre'],':a'=>$data['apellidos'],':d'=>$data['dni'],
                ':t'=>$data['telefono'],':m'=>$data['email'],':u'=>$username,':p'=>$password,
                ':activo'=>$data['estado']==='activo'?1:0,':g'=>$this->siteId,':fecha'=>$createdAt]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new MigrationException('Un identificador ya no está disponible; repite el dry-run.', 'concurrent_duplicate');
            }
            throw $e;
        }
        return (int)$this->db->lastInsertId();
    }

    private function insertProduct(array $batch, array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO producto
             (nombre,descripcion,precio,iva,stock,stock_minimo,estado,id_categoria,id_gimnasio)
             VALUES (:n,:d,:p,21.00,:s,5,:estado,:c,:g)"
        );
        $stmt->execute([':n'=>$data['nombre'],':d'=>$data['descripcion'],':p'=>$data['precio'],':s'=>$data['stock'],
            ':estado'=>$data['estado'],':c'=>$data['id_categoria'],':g'=>$this->siteId]);
        return (int)$this->db->lastInsertId();
    }

    private function addMap(array $batch, string $externalId, int $internalId): void
    {
        $existing = $this->mappedInternal($batch, $externalId);
        if ($existing !== null) {
            if ($existing !== $internalId) {
                throw new MigrationException('Conflicto en el mapa de identificadores externos.', 'external_map_conflict');
            }
            return;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO migration_entity_map
             (id_empresa,id_gimnasio,source_system,entity_type,external_id,internal_id,id_batch)
             VALUES (:e,:g,:s,:t,:x,:i,:b)
            '
        );
        try {
            $stmt->execute([':e'=>$this->companyId,':g'=>$this->siteId,':s'=>$batch['source_system'],
                ':t'=>$batch['entity_type'],':x'=>$externalId,':i'=>$internalId,':b'=>$batch['id_batch']]);
        } catch (PDOException $e) {
            throw new MigrationException('Conflicto en el mapa de identificadores externos.', 'external_map_conflict');
        }
    }

    private function markRow(int $rowId, string $status, int $internalId): void
    {
        $this->db->prepare('UPDATE migration_batch_row SET status=:s,internal_id=:i WHERE id_row=:r')
            ->execute([':s'=>$status,':i'=>$internalId,':r'=>$rowId]);
    }

    private function auditChunk(array $batch, int $lastRow, int $created, int $linked): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO log_actividad
             (id_usuario,accion,entidad,id_entidad,detalle,id_gimnasio,id_empresa,fecha)
             VALUES (:u,'Importación masiva','migration_batch',:b,:d,:g,:e,NOW())"
        );
        $stmt->execute([':u'=>$this->userId,':b'=>$batch['id_batch'],
            ':d'=>sprintf('Batch %s: lote hasta fila %d, creados %d, vinculados %d.',$batch['uuid'],$lastRow,$created,$linked),
            ':g'=>$this->siteId,':e'=>$this->companyId]);
    }

    public function getBatch(string $uuid): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/', $uuid)) throw new MigrationException('Batch no válido.', 'invalid_batch');
        $sql = 'SELECT * FROM migration_batch WHERE uuid=:u AND id_empresa=:e';
        $params = [':u'=>$uuid,':e'=>$this->companyId];
        if ($this->siteId !== null) { $sql .= ' AND id_gimnasio=:g'; $params[':g']=$this->siteId; }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new MigrationException('El batch no existe en el ámbito autorizado.', 'batch_not_found');
        return $this->decodeBatch($row);
    }

    public function listBatches(int $limit = 50): array
    {
        $limit = max(1,min(100,$limit));
        $sql = 'SELECT * FROM migration_batch WHERE id_empresa=:e'; $params=[':e'=>$this->companyId];
        if ($this->siteId !== null) { $sql .= ' AND id_gimnasio=:g'; $params[':g']=$this->siteId; }
        $stmt = $this->db->prepare($sql . " ORDER BY created_at DESC LIMIT {$limit}");
        $stmt->execute($params);
        return array_map(fn($r)=>$this->decodeBatch($r),$stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function report(string $uuid): array
    {
        $batch = $this->getBatch($uuid);
        $stmt = $this->db->prepare(
            'SELECT `row_number`,severity,field_name,problem_code,message,value_excerpt,proposed_action
             FROM migration_batch_issue WHERE id_batch=:b ORDER BY `row_number`,id_issue LIMIT 500'
        );
        $stmt->execute([':b'=>$batch['id_batch']]);
        return ['batch'=>$batch,'issues'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function discard(string $uuid): void
    {
        $tenantLifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->companyId);
        $batch = $this->getBatch($uuid);
        if (in_array($batch['status'], ['importing','partial','completed','completed_with_warnings'], true)) {
            throw new MigrationException('Ese batch no puede descartarse automáticamente.', 'discard_rejected');
        }
        $this->storage->delete($batch['storage_key']);
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM migration_batch_issue WHERE id_batch=:b')->execute([':b'=>$batch['id_batch']]);
            $this->db->prepare('DELETE FROM migration_batch_row WHERE id_batch=:b')->execute([':b'=>$batch['id_batch']]);
            $this->db->prepare("UPDATE migration_batch SET status='expired',storage_key=NULL WHERE id_batch=:b")
                ->execute([':b'=>$batch['id_batch']]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function purgeExpired(): int
    {
        $sql = "SELECT id_batch,storage_key,status FROM migration_batch
                WHERE expires_at<NOW() AND storage_key IS NOT NULL AND id_empresa=:e";
        $params = [':e'=>$this->companyId];
        if ($this->siteId !== null) { $sql .= ' AND id_gimnasio=:g'; $params[':g']=$this->siteId; }
        $stmt = $this->db->prepare($sql . ' LIMIT 500');
        $stmt->execute($params);
        $purged = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $batch) {
            if (!$this->storage->delete($batch['storage_key'])) continue;
            $this->db->prepare('DELETE FROM migration_batch_issue WHERE id_batch=:b')->execute([':b'=>$batch['id_batch']]);
            $this->db->prepare('DELETE FROM migration_batch_row WHERE id_batch=:b')->execute([':b'=>$batch['id_batch']]);
            $status = in_array($batch['status'], ['completed','completed_with_warnings'], true) ? $batch['status'] : 'expired';
            $this->db->prepare('UPDATE migration_batch SET storage_key=NULL,status=:s WHERE id_batch=:b')
                ->execute([':s'=>$status,':b'=>$batch['id_batch']]);
            $purged++;
        }
        return $purged;
    }

    private function issue(int $batchId, int $row, string $severity, ?string $field, string $code, string $message, ?string $value, ?string $action): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO migration_batch_issue
             (id_batch,`row_number`,severity,field_name,problem_code,message,value_excerpt,proposed_action)
             VALUES (:b,:r,:s,:f,:c,:m,:v,:a)'
        );
        $stmt->execute([':b'=>$batchId,':r'=>max(1,$row),':s'=>$severity,':f'=>$field,
            ':c'=>mb_substr($code,0,80),':m'=>mb_substr($message,0,500),
            ':v'=>$value!==null?mb_substr($value,0,255):null,':a'=>$action!==null?mb_substr($action,0,255):null]);
    }

    private function sourceValue(array $row, ?string $field): ?string
    {
        if ($field === null || str_contains($field, ',')) return null;
        $value = (string)($row[$field] ?? '');
        $value = preg_replace('/[\x00-\x1F\x7F]/u','',$value) ?? '';
        return $value === '' ? null : $value;
    }

    private static function decisionIssue(string $severity, ?string $field, string $code, string $message, string $action): array
    {
        return compact('severity','field','code','message','action');
    }

    private function decodeBatch(array $row): array
    {
        $row['headers'] = json_decode($row['headers_json'] ?: '[]', true) ?: [];
        $row['mapping'] = json_decode($row['mapping_json'] ?: '{}', true) ?: [];
        $row['options'] = json_decode($row['options_json'] ?: '{}', true) ?: [];
        unset($row['headers_json'],$row['mapping_json'],$row['options_json']);
        return $row;
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
