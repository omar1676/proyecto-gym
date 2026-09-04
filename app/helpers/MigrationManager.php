<?php
require_once __DIR__ . '/../config/database.php';

final class MigrationManager
{
    private PDO $db;
    private string $dir;
    private int $lockTimeoutSeconds;

    public function __construct(?PDO $db = null, ?string $dir = null, int $lockTimeoutSeconds = 10)
    {
        $this->db = $db ?: Database::getInstance()->getConnection();
        $this->dir = rtrim($dir ?: dirname(__DIR__) . '/config', '/\\');
        $this->lockTimeoutSeconds = max(0, min(60, $lockTimeoutSeconds));
    }

    public function files(): array
    {
        $files = [$this->dir . '/schema.sql', $this->dir . '/migracion.sql'];
        for ($i = 2; $i <= 999; $i++) {
            $file = $this->dir . '/migracion_v' . $i . '.sql';
            if (!is_file($file)) {
                break;
            }
            $files[] = $file;
        }
        return $files;
    }

    public function trackingExists(): bool
    {
        return $this->hasTable('schema_migrations');
    }

    public function isEmptyDatabase(): bool
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()'
        )->fetchColumn() === 0;
    }

    /** Registra un legacy probado solo hasta v22; v23 en adelante quedan pendientes. */
    public function baselineExisting(): void
    {
        $this->withAdvisoryLock(function (): void {
            if ($this->trackingExists()) {
                $rows = $this->trackingRows();
                if ($rows !== []) {
                    $this->assertConsistentStatus($this->status());
                    return;
                }
                $this->assertLegacyV21Schema();
                $this->assertMigrationEffects('migracion_v22.sql');
                $this->recordFiles($this->filesThrough('migracion_v22.sql'));
                return;
            }

            $this->assertLegacyV21Schema();
            $v22 = $this->dir . '/migracion_v22.sql';
            if (!is_file($v22)) {
                throw new RuntimeException('No existe migracion_v22.sql; baseline detenido.');
            }
            $this->executeFile($v22);
            $this->assertMigrationEffects('migracion_v22.sql');
            $this->recordFiles($this->filesThrough('migracion_v22.sql'));
        });
    }

    public function migrateFresh(): array
    {
        return $this->withAdvisoryLock(function (): array {
            if (!$this->isEmptyDatabase()) {
                throw new RuntimeException('La instalación --fresh exige una base completamente vacía.');
            }
            $applied = [];
            $executedBeforeTracking = [];
            foreach ($this->files() as $file) {
                $name = basename($file);
                $this->executeFile($file);
                $this->assertMigrationEffects($name);
                $applied[] = $name;
                $executedBeforeTracking[] = $file;
                if ($name === 'migracion_v22.sql') {
                    $this->recordFiles($executedBeforeTracking);
                } elseif ($this->trackingExists() && $this->migrationNumber($name) > 22) {
                    $this->recordFiles([$file]);
                }
            }
            if (!$this->trackingExists()) {
                throw new RuntimeException('La migración de tracking no se creó.');
            }
            return $applied;
        });
    }

    public function migratePending(): array
    {
        return $this->withAdvisoryLock(function (): array {
            if (!$this->trackingExists()) {
                throw new RuntimeException('Falta schema_migrations; ejecuta --baseline-current o --fresh.');
            }
            $status = $this->status();
            $this->assertConsistentStatus($status);
            $applied = [];
            foreach ($status['pending'] as $name) {
                $file = $this->dir . '/' . $name;
                try {
                    $this->executeFile($file);
                    $this->assertMigrationEffects($name);
                    $this->recordFiles([$file]);
                    $applied[] = $name;
                } catch (Throwable $e) {
                    throw new RuntimeException(
                        'La migración ' . $name . ' falló y no fue registrada.',
                        0,
                        $e
                    );
                }
            }
            return $applied;
        });
    }

    public function status(): array
    {
        $files = $this->files();
        $latest = basename(end($files));
        if (!$this->trackingExists()) {
            return [
                'initialized' => false, 'latest' => $latest, 'applied' => [],
                'pending' => array_map('basename', $files),
                'checksum_mismatch' => [], 'structural_mismatch' => [],
            ];
        }

        $rows = $this->trackingRows();
        $known = array_map('basename', $files);
        $pending = $checksumMismatch = $structuralMismatch = [];
        foreach (array_keys($rows) as $recorded) {
            if (!in_array($recorded, $known, true)) {
                $structuralMismatch[] = 'unknown_migration:' . $recorded;
            }
        }

        $firstMissingSeen = false;
        foreach ($files as $file) {
            $name = basename($file);
            $recorded = array_key_exists($name, $rows);
            if (!$recorded) {
                $pending[] = $name;
                $firstMissingSeen = true;
            } else {
                if ($firstMissingSeen) {
                    $structuralMismatch[] = 'migration_order_gap:' . $name;
                }
                $hash = hash_file('sha256', $file);
                if ($hash === false || !hash_equals((string) $rows[$name], $hash)) {
                    $checksumMismatch[] = $name;
                }
            }

            $checks = $this->structuralChecks($name);
            if ($checks === []) {
                continue;
            }
            $passed = array_filter($checks, static fn(bool $ok): bool => $ok);
            if ($recorded && count($passed) !== count($checks)) {
                $missing = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
                $structuralMismatch[] = 'applied_missing_effects:' . $name . ':' . implode(',', $missing);
            } elseif (!$recorded && count($passed) > 0) {
                $kind = count($passed) === count($checks)
                    ? 'effects_without_record'
                    : 'partial_effects_without_record';
                $structuralMismatch[] = $kind . ':' . $name;
            }
        }

        return [
            'initialized' => true, 'latest' => $latest, 'applied' => array_keys($rows),
            'pending' => $pending,
            'checksum_mismatch' => array_values(array_unique($checksumMismatch)),
            'structural_mismatch' => array_values(array_unique($structuralMismatch)),
        ];
    }

    private function assertConsistentStatus(array $status): void
    {
        if ($status['checksum_mismatch'] !== []) {
            throw new RuntimeException(
                'Una migración aplicada fue modificada: ' . implode(', ', $status['checksum_mismatch'])
            );
        }
        if (($status['structural_mismatch'] ?? []) !== []) {
            throw new RuntimeException(
                'El estado estructural de migraciones es inconsistente: '
                . implode('; ', $status['structural_mismatch'])
            );
        }
    }

    private function assertLegacyV21Schema(): void
    {
        $checks = $this->legacyV21Checks();
        $missing = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
        if ($missing !== []) {
            throw new RuntimeException(
                'No puede demostrarse que el esquema existente llegue íntegramente a v21: '
                . implode(', ', $missing)
            );
        }
    }

    private function assertMigrationEffects(string $name): void
    {
        $checks = $this->structuralChecks($name);
        $missing = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
        if ($missing !== []) {
            throw new RuntimeException(
                'La migración ' . $name . ' no produjo todas sus estructuras: ' . implode(', ', $missing)
            );
        }
    }

    private function structuralChecks(string $name): array
    {
        return match ($name) {
            'migracion_v21.sql' => $this->legacyV21Checks(),
            'migracion_v22.sql' => [
                'table:schema_migrations' => $this->hasTable('schema_migrations'),
                'column:schema_migrations.migration' => $this->hasColumn('schema_migrations', 'migration'),
                'column:schema_migrations.checksum' => $this->hasColumn('schema_migrations', 'checksum'),
                'column:schema_migrations.release_version' => $this->hasColumn('schema_migrations', 'release_version'),
                'column:schema_migrations.applied_at' => $this->hasColumn('schema_migrations', 'applied_at'),
            ],
            'migracion_v23.sql' => [
                'index:usuario.idx_usuario_empresa_rol_orden' => $this->hasIndex('usuario', 'idx_usuario_empresa_rol_orden'),
                'index:usuario.idx_usuario_sede_rol_orden' => $this->hasIndex('usuario', 'idx_usuario_sede_rol_orden'),
                'index:socio_membresia.idx_sm_socio_fin' => $this->hasIndex('socio_membresia', 'idx_sm_socio_fin'),
            ],
            'migracion_v24.sql' => $this->tableChecks([
                'migration_batch', 'migration_batch_issue', 'migration_batch_row', 'migration_entity_map',
            ]),
            'migracion_v25.sql' => $this->tableChecks([
                'obligacion_pago', 'cobro', 'caja_sesion', 'caja_movimiento',
            ]),
            'migracion_v26.sql' => array_merge(
                $this->tableChecks(['access_identity_map', 'access_sync_job', 'access_control_audit']),
                [
                    'index:gimnasio.uq_gimnasio_access_scope' => $this->hasIndex('gimnasio', 'uq_gimnasio_access_scope'),
                    'index:usuario.uq_usuario_access_scope' => $this->hasIndex('usuario', 'uq_usuario_access_scope'),
                ]
            ),
            'migracion_v27.sql' => [
                'column:mandato_sepa.idempotency_key' => $this->hasColumn('mandato_sepa', 'idempotency_key'),
                'column:mandato_sepa.socio_activo_unico' => $this->hasColumn('mandato_sepa', 'socio_activo_unico'),
                'index:mandato_sepa.uq_mandato_idempotencia' => $this->hasIndex('mandato_sepa', 'uq_mandato_idempotencia'),
                'index:mandato_sepa.uq_mandato_socio_activo' => $this->hasIndex('mandato_sepa', 'uq_mandato_socio_activo'),
                'column:remesa_recibo.membresia_en_cobro' => $this->hasColumn('remesa_recibo', 'membresia_en_cobro'),
                'index:remesa_recibo.uq_recibo_membresia_en_cobro' => $this->hasIndex('remesa_recibo', 'uq_recibo_membresia_en_cobro'),
                'column:log_actividad.resultado' => $this->hasColumn('log_actividad', 'resultado'),
            ],
            'migracion_v28.sql' => [
                'column:log_actividad.event_id' => $this->hasColumn('log_actividad', 'event_id'),
                'column:log_actividad.correlation_id' => $this->hasColumn('log_actividad', 'correlation_id'),
                'column:log_actividad.actor_type' => $this->hasColumn('log_actividad', 'actor_type'),
                'column:log_actividad.origin' => $this->hasColumn('log_actividad', 'origin'),
                'column:log_actividad.reason_code' => $this->hasColumn('log_actividad', 'reason_code'),
                'column:log_actividad.metadata_json' => $this->hasColumn('log_actividad', 'metadata_json'),
                'index:log_actividad.uq_log_event_id' => $this->hasIndex('log_actividad', 'uq_log_event_id'),
                'index:log_actividad.idx_log_correlation' => $this->hasIndex('log_actividad', 'idx_log_correlation'),
                'index:log_actividad.idx_log_empresa_origen_fecha' => $this->hasIndex('log_actividad', 'idx_log_empresa_origen_fecha'),
            ],
            'migracion_v29.sql' => [
                'column:empresa.slug' => $this->hasColumn('empresa', 'slug'),
                'column:empresa.onboarding_key' => $this->hasColumn('empresa', 'onboarding_key'),
                'column:empresa.onboarding_state' => $this->hasColumn('empresa', 'onboarding_state'),
                'column:empresa.onboarding_updated_at' => $this->hasColumn('empresa', 'onboarding_updated_at'),
                'column:usuario.identidad_empresa_scope' => $this->hasColumn('usuario', 'identidad_empresa_scope'),
                'column:categoria_producto.id_empresa' => $this->hasColumn('categoria_producto', 'id_empresa'),
                'index:empresa.uq_empresa_slug' => $this->hasIndex('empresa', 'uq_empresa_slug'),
                'index:empresa.uq_empresa_onboarding_key' => $this->hasIndex('empresa', 'uq_empresa_onboarding_key'),
                'index:empresa.idx_empresa_onboarding_state' => $this->hasIndex('empresa', 'idx_empresa_onboarding_state'),
                'index:usuario.uq_usuario_empresa_dni' => $this->hasIndex('usuario', 'uq_usuario_empresa_dni'),
                'index:usuario.uq_usuario_empresa_email' => $this->hasIndex('usuario', 'uq_usuario_empresa_email'),
                'index:usuario.uq_usuario_empresa_username' => $this->hasIndex('usuario', 'uq_usuario_empresa_username'),
                'index:gimnasio.uq_gimnasio_empresa_nombre' => $this->hasIndex('gimnasio', 'uq_gimnasio_empresa_nombre'),
                'index:categoria_producto.uq_categoria_empresa_nombre' => $this->hasIndex('categoria_producto', 'uq_categoria_empresa_nombre'),
                'fk:categoria_producto.fk_categoria_empresa' => $this->hasForeignKey('categoria_producto', 'fk_categoria_empresa'),
            ],
            'migracion_v30.sql' => [
                'column:usuario.profile_version' => $this->hasColumn('usuario', 'profile_version'),
            ],
            'migracion_v31.sql' => array_merge(
                $this->tableChecks([
                    'retention_config', 'retention_activity_mapping', 'attendance_event',
                    'retention_run', 'retention_detection', 'retention_action',
                ]),
                [
                    'index:tipo_membresia.uq_tipo_membresia_scope' => $this->hasIndex('tipo_membresia', 'uq_tipo_membresia_scope'),
                    'index:usuario.uq_usuario_company_scope' => $this->hasIndex('usuario', 'uq_usuario_company_scope'),
                    'index:attendance_event.uq_attendance_idempotency' => $this->hasIndex('attendance_event', 'uq_attendance_idempotency'),
                    'index:attendance_event.uq_attendance_external' => $this->hasIndex('attendance_event', 'uq_attendance_external'),
                    'index:retention_run.uq_retention_run_daily' => $this->hasIndex('retention_run', 'uq_retention_run_daily'),
                    'index:retention_detection.uq_retention_detection_daily' => $this->hasIndex('retention_detection', 'uq_retention_detection_daily'),
                    'index:retention_action.uq_retention_action_idempotency' => $this->hasIndex('retention_action', 'uq_retention_action_idempotency'),
                    'fk:attendance_event.fk_attendance_member_scope' => $this->hasForeignKey('attendance_event', 'fk_attendance_member_scope'),
                    'fk:retention_detection.fk_retention_detection_member_scope' => $this->hasForeignKey('retention_detection', 'fk_retention_detection_member_scope'),
                    'fk:retention_action.fk_retention_action_detection_scope' => $this->hasForeignKey('retention_action', 'fk_retention_action_detection_scope'),
                ]
            ),
            'migracion_v32.sql' => array_merge(
                $this->tableChecks(['retention_member_snapshot','attendance_daily_visit']),
                [
                    'index:retention_member_snapshot.uq_retention_snapshot_run_member' => $this->hasIndex('retention_member_snapshot', 'uq_retention_snapshot_run_member'),
                    'index:retention_member_snapshot.idx_retention_snapshot_dashboard' => $this->hasIndex('retention_member_snapshot', 'idx_retention_snapshot_dashboard'),
                    'index:attendance_event.idx_attendance_recent_daily' => $this->hasIndex('attendance_event', 'idx_attendance_recent_daily'),
                    'index:attendance_daily_visit.idx_attendance_daily_recent' => $this->hasIndex('attendance_daily_visit', 'idx_attendance_daily_recent'),
                    'trigger:trg_attendance_daily_after_insert' => $this->hasTrigger('trg_attendance_daily_after_insert'),
                    'fk:retention_member_snapshot.fk_retention_snapshot_member_scope' => $this->hasForeignKey('retention_member_snapshot', 'fk_retention_snapshot_member_scope'),
                    'fk:retention_member_snapshot.fk_retention_snapshot_site_scope' => $this->hasForeignKey('retention_member_snapshot', 'fk_retention_snapshot_site_scope'),
                ]
            ),
            'migracion_v33.sql' => array_merge(
                $this->tableChecks([
                    'training_exercise', 'training_exercise_media',
                    'training_template', 'training_template_discipline',
                    'training_template_day', 'training_template_block', 'training_template_exercise',
                    'training_plan', 'training_plan_discipline', 'training_plan_day',
                    'training_plan_block', 'training_plan_exercise', 'training_plan_exercise_media',
                    'training_assignment', 'training_session', 'training_session_exercise',
                ]),
                [
                    'index:training_exercise.uq_training_exercise_scope_slug' => $this->hasIndex('training_exercise', 'uq_training_exercise_scope_slug'),
                    'index:training_exercise.uq_training_exercise_scope_id' => $this->hasIndex('training_exercise', 'uq_training_exercise_scope_id'),
                    'index:training_exercise_media.uq_training_media_order' => $this->hasIndex('training_exercise_media', 'uq_training_media_order'),
                    'index:training_template.uq_training_template_company_slug' => $this->hasIndex('training_template', 'uq_training_template_company_slug'),
                    'index:training_template.uq_training_template_scope' => $this->hasIndex('training_template', 'uq_training_template_scope'),
                    'index:training_template_day.uq_training_template_day_order' => $this->hasIndex('training_template_day', 'uq_training_template_day_order'),
                    'index:training_template_block.uq_training_template_block_order' => $this->hasIndex('training_template_block', 'uq_training_template_block_order'),
                    'index:training_template_exercise.uq_training_template_exercise_order' => $this->hasIndex('training_template_exercise', 'uq_training_template_exercise_order'),
                    'index:training_plan.uq_training_plan_scope' => $this->hasIndex('training_plan', 'uq_training_plan_scope'),
                    'index:training_plan.idx_training_plan_member' => $this->hasIndex('training_plan', 'idx_training_plan_member'),
                    'index:training_plan_day.uq_training_plan_day_order' => $this->hasIndex('training_plan_day', 'uq_training_plan_day_order'),
                    'index:training_plan_block.uq_training_plan_block_order' => $this->hasIndex('training_plan_block', 'uq_training_plan_block_order'),
                    'index:training_plan_exercise.uq_training_plan_exercise_order' => $this->hasIndex('training_plan_exercise', 'uq_training_plan_exercise_order'),
                    'index:training_plan_exercise.uq_training_plan_exercise_scope' => $this->hasIndex('training_plan_exercise', 'uq_training_plan_exercise_scope'),
                    'index:training_plan_exercise_media.uq_training_plan_media_order' => $this->hasIndex('training_plan_exercise_media', 'uq_training_plan_media_order'),
                    'index:training_assignment.uq_training_assignment_idempotency' => $this->hasIndex('training_assignment', 'uq_training_assignment_idempotency'),
                    'index:training_assignment.uq_training_assignment_active_member' => $this->hasIndex('training_assignment', 'uq_training_assignment_active_member'),
                    'index:training_session.uq_training_session_idempotency' => $this->hasIndex('training_session', 'uq_training_session_idempotency'),
                    'index:training_session.uq_training_session_scope' => $this->hasIndex('training_session', 'uq_training_session_scope'),
                    'index:training_session_exercise.uq_training_session_exercise' => $this->hasIndex('training_session_exercise', 'uq_training_session_exercise'),
                    'fk:training_exercise_media.fk_training_media_exercise_scope' => $this->hasForeignKey('training_exercise_media', 'fk_training_media_exercise_scope'),
                    'fk:training_template_day.fk_training_template_day_scope' => $this->hasForeignKey('training_template_day', 'fk_training_template_day_scope'),
                    'fk:training_template_block.fk_training_template_block_day_scope' => $this->hasForeignKey('training_template_block', 'fk_training_template_block_day_scope'),
                    'fk:training_template_exercise.fk_training_template_exercise_block_scope' => $this->hasForeignKey('training_template_exercise', 'fk_training_template_exercise_block_scope'),
                    'fk:training_template_exercise.fk_training_template_exercise_catalog_scope' => $this->hasForeignKey('training_template_exercise', 'fk_training_template_exercise_catalog_scope'),
                    'fk:training_plan.fk_training_plan_member_scope' => $this->hasForeignKey('training_plan', 'fk_training_plan_member_scope'),
                    'fk:training_plan.fk_training_plan_site_scope' => $this->hasForeignKey('training_plan', 'fk_training_plan_site_scope'),
                    'fk:training_plan_day.fk_training_plan_day_scope' => $this->hasForeignKey('training_plan_day', 'fk_training_plan_day_scope'),
                    'fk:training_plan_block.fk_training_plan_block_day_scope' => $this->hasForeignKey('training_plan_block', 'fk_training_plan_block_day_scope'),
                    'fk:training_plan_exercise.fk_training_plan_exercise_block_scope' => $this->hasForeignKey('training_plan_exercise', 'fk_training_plan_exercise_block_scope'),
                    'fk:training_plan_exercise_media.fk_training_plan_media_exercise_scope' => $this->hasForeignKey('training_plan_exercise_media', 'fk_training_plan_media_exercise_scope'),
                    'fk:training_assignment.fk_training_assignment_plan_scope' => $this->hasForeignKey('training_assignment', 'fk_training_assignment_plan_scope'),
                    'fk:training_session.fk_training_session_plan_scope' => $this->hasForeignKey('training_session', 'fk_training_session_plan_scope'),
                    'fk:training_session.fk_training_session_day_scope' => $this->hasForeignKey('training_session', 'fk_training_session_day_scope'),
                    'fk:training_session_exercise.fk_training_session_exercise_session_scope' => $this->hasForeignKey('training_session_exercise', 'fk_training_session_exercise_session_scope'),
                    'fk:training_session_exercise.fk_training_session_exercise_plan_scope' => $this->hasForeignKey('training_session_exercise', 'fk_training_session_exercise_plan_scope'),
                    'check:training_exercise.chk_training_exercise_active' => $this->hasCheckConstraint('training_exercise', 'chk_training_exercise_active'),
                    'check:training_exercise_media.chk_training_media_reference' => $this->hasCheckConstraint('training_exercise_media', 'chk_training_media_reference'),
                    'check:training_template.chk_training_template_days' => $this->hasCheckConstraint('training_template', 'chk_training_template_days'),
                    'check:training_template_block.chk_training_template_circuit' => $this->hasCheckConstraint('training_template_block', 'chk_training_template_circuit'),
                    'check:training_template_exercise.chk_training_template_nonnegative' => $this->hasCheckConstraint('training_template_exercise', 'chk_training_template_nonnegative'),
                    'check:training_template_exercise.chk_training_template_execution_shape' => $this->hasCheckConstraint('training_template_exercise', 'chk_training_template_execution_shape'),
                    'check:training_plan.chk_training_plan_dates' => $this->hasCheckConstraint('training_plan', 'chk_training_plan_dates'),
                    'check:training_plan_block.chk_training_plan_circuit' => $this->hasCheckConstraint('training_plan_block', 'chk_training_plan_circuit'),
                    'check:training_plan_exercise.chk_training_plan_nonnegative' => $this->hasCheckConstraint('training_plan_exercise', 'chk_training_plan_nonnegative'),
                    'check:training_plan_exercise.chk_training_plan_execution_shape' => $this->hasCheckConstraint('training_plan_exercise', 'chk_training_plan_execution_shape'),
                    'check:training_session_exercise.chk_training_session_completed' => $this->hasCheckConstraint('training_session_exercise', 'chk_training_session_completed'),
                ]
            ),
            'migracion_v34.sql' => array_merge(
                $this->tableChecks(['access_policy','access_policy_event']),
                [
                    'index:access_policy.uq_access_policy_member_scope' => $this->hasIndex('access_policy', 'uq_access_policy_member_scope'),
                    'index:access_policy.idx_access_policy_expiry' => $this->hasIndex('access_policy', 'idx_access_policy_expiry'),
                    'index:access_policy_event.uq_access_policy_event_idempotency' => $this->hasIndex('access_policy_event', 'uq_access_policy_event_idempotency'),
                    'fk:access_policy.fk_access_policy_member_scope' => $this->hasForeignKey('access_policy', 'fk_access_policy_member_scope'),
                    'fk:access_policy_event.fk_access_policy_event_scope' => $this->hasForeignKey('access_policy_event', 'fk_access_policy_event_scope'),
                    'check:access_policy.chk_access_policy_version' => $this->hasCheckConstraint('access_policy', 'chk_access_policy_version'),
                    'check:access_policy.chk_access_policy_temporary_expiry' => $this->hasCheckConstraint('access_policy', 'chk_access_policy_temporary_expiry'),
                    'check:access_policy.chk_access_policy_interval' => $this->hasCheckConstraint('access_policy', 'chk_access_policy_interval'),
                ]
            ),
            'migracion_v35.sql' => array_merge(
                $this->tableChecks([
                    'restaurant_account', 'restaurant_brand',
                    'restaurant_legal_entity', 'restaurant_location',
                ]),
                [
                    'index:restaurant_account.uq_restaurant_account_company' => $this->hasIndex('restaurant_account', 'uq_restaurant_account_company'),
                    'index:restaurant_account.uq_restaurant_account_idempotency' => $this->hasIndex('restaurant_account', 'uq_restaurant_account_idempotency'),
                    'index:restaurant_account.uq_restaurant_account_scope' => $this->hasIndex('restaurant_account', 'uq_restaurant_account_scope'),
                    'column:restaurant_account.request_fingerprint' => $this->hasColumn('restaurant_account', 'request_fingerprint'),
                    'index:restaurant_brand.uq_restaurant_brand_company_slug' => $this->hasIndex('restaurant_brand', 'uq_restaurant_brand_company_slug'),
                    'index:restaurant_brand.uq_restaurant_brand_scope' => $this->hasIndex('restaurant_brand', 'uq_restaurant_brand_scope'),
                    'index:restaurant_legal_entity.uq_restaurant_legal_company_code' => $this->hasIndex('restaurant_legal_entity', 'uq_restaurant_legal_company_code'),
                    'index:restaurant_legal_entity.uq_restaurant_legal_scope' => $this->hasIndex('restaurant_legal_entity', 'uq_restaurant_legal_scope'),
                    'index:restaurant_location.uq_restaurant_location_company_slug' => $this->hasIndex('restaurant_location', 'uq_restaurant_location_company_slug'),
                    'index:restaurant_location.uq_restaurant_location_scope' => $this->hasIndex('restaurant_location', 'uq_restaurant_location_scope'),
                    'fk:restaurant_account.fk_restaurant_account_company' => $this->hasForeignKey('restaurant_account', 'fk_restaurant_account_company'),
                    'fk:restaurant_brand.fk_restaurant_brand_account_scope' => $this->hasForeignKey('restaurant_brand', 'fk_restaurant_brand_account_scope'),
                    'fk:restaurant_legal_entity.fk_restaurant_legal_account_scope' => $this->hasForeignKey('restaurant_legal_entity', 'fk_restaurant_legal_account_scope'),
                    'fk:restaurant_location.fk_restaurant_location_account_scope' => $this->hasForeignKey('restaurant_location', 'fk_restaurant_location_account_scope'),
                    'fk:restaurant_location.fk_restaurant_location_brand_scope' => $this->hasForeignKey('restaurant_location', 'fk_restaurant_location_brand_scope'),
                    'fk:restaurant_location.fk_restaurant_location_legal_scope' => $this->hasForeignKey('restaurant_location', 'fk_restaurant_location_legal_scope'),
                    'check:restaurant_account.chk_restaurant_account_version' => $this->hasCheckConstraint('restaurant_account', 'chk_restaurant_account_version'),
                    'check:restaurant_brand.chk_restaurant_brand_version' => $this->hasCheckConstraint('restaurant_brand', 'chk_restaurant_brand_version'),
                    'check:restaurant_legal_entity.chk_restaurant_legal_version' => $this->hasCheckConstraint('restaurant_legal_entity', 'chk_restaurant_legal_version'),
                    'check:restaurant_location.chk_restaurant_location_version' => $this->hasCheckConstraint('restaurant_location', 'chk_restaurant_location_version'),
                ]
            ),
            'migracion_v36.sql' => array_merge(
                $this->tableChecks([
                    'restaurant_catalog', 'restaurant_catalog_location', 'restaurant_category',
                    'restaurant_product', 'restaurant_product_category', 'restaurant_product_variant',
                    'restaurant_modifier_group', 'restaurant_modifier', 'restaurant_product_modifier_group',
                    'restaurant_price', 'restaurant_price_history', 'restaurant_availability',
                    'restaurant_availability_history', 'restaurant_product_allergen_declaration',
                    'restaurant_product_media', 'restaurant_catalog_mutation',
                ]),
                [
                    'index:restaurant_location.uq_restaurant_location_brand_scope' => $this->hasIndex('restaurant_location', 'uq_restaurant_location_brand_scope'),
                    'index:restaurant_catalog.uq_restaurant_catalog_scope' => $this->hasIndex('restaurant_catalog', 'uq_restaurant_catalog_scope'),
                    'index:restaurant_catalog.uq_restaurant_catalog_idempotency' => $this->hasIndex('restaurant_catalog', 'uq_restaurant_catalog_idempotency'),
                    'index:restaurant_category.uq_restaurant_category_scope' => $this->hasIndex('restaurant_category', 'uq_restaurant_category_scope'),
                    'index:restaurant_product.uq_restaurant_product_scope' => $this->hasIndex('restaurant_product', 'uq_restaurant_product_scope'),
                    'index:restaurant_product_variant.uq_restaurant_variant_scope' => $this->hasIndex('restaurant_product_variant', 'uq_restaurant_variant_scope'),
                    'index:restaurant_modifier_group.uq_restaurant_modifier_group_scope' => $this->hasIndex('restaurant_modifier_group', 'uq_restaurant_modifier_group_scope'),
                    'index:restaurant_modifier.uq_restaurant_modifier_scope' => $this->hasIndex('restaurant_modifier', 'uq_restaurant_modifier_scope'),
                    'index:restaurant_price.uq_restaurant_price_scope_key' => $this->hasIndex('restaurant_price', 'uq_restaurant_price_scope_key'),
                    'index:restaurant_price_history.uq_restaurant_price_history_idempotency' => $this->hasIndex('restaurant_price_history', 'uq_restaurant_price_history_idempotency'),
                    'index:restaurant_availability.uq_restaurant_availability_scope_key' => $this->hasIndex('restaurant_availability', 'uq_restaurant_availability_scope_key'),
                    'index:restaurant_availability_history.uq_restaurant_availability_history_key' => $this->hasIndex('restaurant_availability_history', 'uq_restaurant_availability_history_key'),
                    'index:restaurant_product_media.uq_restaurant_product_media_key' => $this->hasIndex('restaurant_product_media', 'uq_restaurant_product_media_key'),
                    'index:restaurant_catalog_mutation.uq_restaurant_catalog_mutation_key' => $this->hasIndex('restaurant_catalog_mutation', 'uq_restaurant_catalog_mutation_key'),
                    'fk:restaurant_catalog.fk_restaurant_catalog_brand_scope' => $this->hasForeignKey('restaurant_catalog', 'fk_restaurant_catalog_brand_scope'),
                    'fk:restaurant_catalog_location.fk_restaurant_catalog_location_catalog' => $this->hasForeignKey('restaurant_catalog_location', 'fk_restaurant_catalog_location_catalog'),
                    'fk:restaurant_catalog_location.fk_restaurant_catalog_location_location' => $this->hasForeignKey('restaurant_catalog_location', 'fk_restaurant_catalog_location_location'),
                    'fk:restaurant_category.fk_restaurant_category_catalog_scope' => $this->hasForeignKey('restaurant_category', 'fk_restaurant_category_catalog_scope'),
                    'fk:restaurant_product.fk_restaurant_product_brand_scope' => $this->hasForeignKey('restaurant_product', 'fk_restaurant_product_brand_scope'),
                    'fk:restaurant_product_category.fk_restaurant_product_category_category' => $this->hasForeignKey('restaurant_product_category', 'fk_restaurant_product_category_category'),
                    'fk:restaurant_product_variant.fk_restaurant_variant_product_scope' => $this->hasForeignKey('restaurant_product_variant', 'fk_restaurant_variant_product_scope'),
                    'fk:restaurant_modifier.fk_restaurant_modifier_group_scope' => $this->hasForeignKey('restaurant_modifier', 'fk_restaurant_modifier_group_scope'),
                    'fk:restaurant_product_modifier_group.fk_restaurant_product_modifier_group' => $this->hasForeignKey('restaurant_product_modifier_group', 'fk_restaurant_product_modifier_group'),
                    'fk:restaurant_price.fk_restaurant_price_variant_scope' => $this->hasForeignKey('restaurant_price', 'fk_restaurant_price_variant_scope'),
                    'fk:restaurant_price.fk_restaurant_price_location_scope' => $this->hasForeignKey('restaurant_price', 'fk_restaurant_price_location_scope'),
                    'fk:restaurant_availability.fk_restaurant_availability_location' => $this->hasForeignKey('restaurant_availability', 'fk_restaurant_availability_location'),
                    'fk:restaurant_product_allergen_declaration.fk_restaurant_allergen_product' => $this->hasForeignKey('restaurant_product_allergen_declaration', 'fk_restaurant_allergen_product'),
                    'fk:restaurant_product_media.fk_restaurant_product_media_product' => $this->hasForeignKey('restaurant_product_media', 'fk_restaurant_product_media_product'),
                    'check:restaurant_modifier_group.chk_restaurant_modifier_group_bounds' => $this->hasCheckConstraint('restaurant_modifier_group', 'chk_restaurant_modifier_group_bounds'),
                    'check:restaurant_modifier_group.chk_restaurant_modifier_group_required' => $this->hasCheckConstraint('restaurant_modifier_group', 'chk_restaurant_modifier_group_required'),
                    'check:restaurant_price.chk_restaurant_price_dimensions' => $this->hasCheckConstraint('restaurant_price', 'chk_restaurant_price_dimensions'),
                    'check:restaurant_price.chk_restaurant_price_amount' => $this->hasCheckConstraint('restaurant_price', 'chk_restaurant_price_amount'),
                    'check:restaurant_availability.chk_restaurant_availability_dimensions' => $this->hasCheckConstraint('restaurant_availability', 'chk_restaurant_availability_dimensions'),
                    'check:restaurant_product_media.chk_restaurant_product_media_size' => $this->hasCheckConstraint('restaurant_product_media', 'chk_restaurant_product_media_size'),
                ]
            ),
            default => [],
        };
    }

    /** Huella acumulada del esquema tras v21, incluidas ausencias de legado. */
    private function legacyV21Checks(): array
    {
        $checks = $this->tableChecks([
            'usuario', 'categoria_producto', 'producto', 'venta', 'venta_linea',
            'tipo_membresia', 'socio_membresia', 'suplemento', 'gimnasio',
            'log_actividad', 'intentos_login', 'intentos_gimnasio', 'empresa',
            'mandato_sepa', 'remesa', 'remesa_recibo',
        ]);
        foreach ([
            'usuario' => ['rol', 'activo', 'foto', 'reset_token', 'iban', 'id_gimnasio', 'id_empresa', 'sesiones_desde', 'baja_pendiente', 'anonimizado_en'],
            'producto' => ['iva', 'id_gimnasio', 'stock_minimo'],
            'venta' => ['serie', 'ejercicio', 'numero', 'base_imponible', 'total_iva', 'estado', 'idempotency_key', 'id_gimnasio'],
            'venta_linea' => ['iva', 'base_linea', 'cuota_iva'],
            'tipo_membresia' => ['id_empresa', 'id_gimnasio', 'iva'],
            'socio_membresia' => ['id_suplemento', 'es_prueba', 'estado_pago', 'renovar_auto', 'origen', 'iva', 'idempotency_key', 'id_gimnasio'],
            'suplemento' => ['id_empresa', 'id_gimnasio', 'iva'],
            'gimnasio' => ['id_empresa', 'razon_social', 'cif', 'iban', 'bic', 'identificador_acreedor', 'slug', 'email_acceso'],
            'log_actividad' => ['id_empresa', 'id_gimnasio', 'id_usuario_afectado', 'entidad', 'id_entidad', 'valor_anterior', 'valor_nuevo', 'ip'],
            'remesa' => ['idempotency_key'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $checks['column:' . $table . '.' . $column] = $this->hasColumn($table, $column);
            }
        }
        foreach ([
            ['venta', 'uq_venta_idempotencia'], ['socio_membresia', 'uq_membresia_idempotencia'],
            ['remesa', 'uq_remesa_idempotencia'], ['intentos_login', 'idx_intentos_usuario_fecha'],
            ['intentos_login', 'idx_intentos_ip_fecha'], ['intentos_gimnasio', 'idx_intentos_gym_email_fecha'],
            ['intentos_gimnasio', 'idx_intentos_gym_ip_fecha'], ['usuario', 'idx_usuario_empresa'],
            ['gimnasio', 'idx_gimnasio_empresa'],
        ] as [$table, $index]) {
            $checks['index:' . $table . '.' . $index] = $this->hasIndex($table, $index);
        }
        foreach (['personas', 'curso', 'categoria', 'visitas', 'usuario_curso', 'verificacion_email'] as $legacy) {
            $checks['legacy_table_absent:' . $legacy] = !$this->hasTable($legacy);
        }
        return $checks;
    }

    private function tableChecks(array $tables): array
    {
        $checks = [];
        foreach ($tables as $table) {
            $checks['table:' . $table] = $this->hasTable($table);
        }
        return $checks;
    }

    private function hasTable(string $table): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table'
        );
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.columns '
            . 'WHERE table_schema=DATABASE() AND table_name=:table AND column_name=:column'
        );
        $stmt->execute([':table' => $table, ':column' => $column]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private function hasIndex(string $table, string $index): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics '
            . 'WHERE table_schema=DATABASE() AND table_name=:table AND index_name=:index'
        );
        $stmt->execute([':table' => $table, ':index' => $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function hasForeignKey(string $table, string $constraint): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.referential_constraints '
            . 'WHERE constraint_schema=DATABASE() AND table_name=:table AND constraint_name=:constraint'
        );
        $stmt->execute([':table' => $table, ':constraint' => $constraint]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function hasCheckConstraint(string $table, string $constraint): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.table_constraints "
            . "WHERE constraint_schema=DATABASE() AND table_name=:table "
            . "AND constraint_name=:constraint AND constraint_type='CHECK'"
        );
        $stmt->execute([':table' => $table, ':constraint' => $constraint]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function hasTrigger(string $trigger): bool
    {
        $stmt=$this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name=:trigger'
        );
        $stmt->execute([':trigger'=>$trigger]);
        return (int)$stmt->fetchColumn()===1;
    }

    private function trackingRows(): array
    {
        return $this->db->query(
            'SELECT migration, checksum FROM schema_migrations ORDER BY applied_at, migration'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    private function filesThrough(string $lastName): array
    {
        $result = [];
        foreach ($this->files() as $file) {
            $result[] = $file;
            if (basename($file) === $lastName) {
                return $result;
            }
        }
        throw new RuntimeException('No se encontró ' . $lastName . ' en la cadena de migraciones.');
    }

    private function executeFile(string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('No se pudo leer ' . basename($file) . '.');
        }
        $database = $this->currentDatabase();
        $sql = str_replace(
            ['`portal_de_cursos`', 'portal_de_cursos'],
            ['`' . $database . '`', $database],
            $sql
        );
        $this->db->exec($sql);
    }

    private function currentDatabase(): string
    {
        $database = (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
        if ($database === '' || !preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new RuntimeException('La base activa no es válida para migraciones.');
        }
        return $database;
    }

    private function migrationNumber(string $name): int
    {
        return preg_match('/^migracion_v(\d+)\.sql$/', $name, $match) ? (int) $match[1] : 0;
    }

    private function advisoryLockName(): string
    {
        return 'gimnera:migrations:' . substr(hash('sha256', $this->currentDatabase()), 0, 40);
    }

    private function withAdvisoryLock(callable $operation): mixed
    {
        $name = $this->advisoryLockName();
        $stmt = $this->db->prepare('SELECT GET_LOCK(:name, :timeout)');
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':timeout', $this->lockTimeoutSeconds, PDO::PARAM_INT);
        $stmt->execute();
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Otro migrador mantiene el cerrojo de esta base.');
        }
        try {
            return $operation();
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute([':name' => $name]);
        }
    }

    private function recordFiles(array $files): void
    {
        $version = trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION')) ?: null;
        $stmt = $this->db->prepare(
            'INSERT INTO schema_migrations (migration, checksum, release_version) VALUES (:name,:hash,:version)'
        );
        foreach ($files as $file) {
            $hash = hash_file('sha256', $file);
            if ($hash === false) {
                throw new RuntimeException('No se pudo calcular el checksum de ' . basename($file) . '.');
            }
            $stmt->execute([':name' => basename($file), ':hash' => $hash, ':version' => $version]);
        }
    }
}
