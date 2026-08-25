-- Fase 25A: foundation de entrenamiento profesional multidisciplina.
-- Forward-only. No contiene datos personales, biometría ni automatización.

CREATE TABLE `training_exercise` (
    `id_training_exercise` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_empresa` INT UNSIGNED NULL,
    `catalog_scope` INT UNSIGNED GENERATED ALWAYS AS (IFNULL(`id_empresa`, 0)) STORED,
    `name` VARCHAR(140) NOT NULL,
    `slug` VARCHAR(160) NOT NULL,
    `discipline` ENUM('GYM','STRENGTH','BOXEO','MMA','BJJ','CONDITIONING','GENERAL') NOT NULL,
    `short_description` VARCHAR(500) NULL,
    `preparation` TEXT NULL,
    `execution_instructions` TEXT NULL,
    `breathing` TEXT NULL,
    `common_errors` TEXT NULL,
    `technical_notes` TEXT NULL,
    `muscle_group` ENUM('PECHO','ESPALDA','PIERNAS','HOMBROS','BICEPS','TRICEPS','CORE','FULL_BODY','OTROS') NULL,
    `equipment` ENUM('PESO_CORPORAL','BARRA','MANCUERNAS','MAQUINA','POLEA','BANCO','SACO','MANOPLAS','COMBA','TATAMI','BATTLE_ROPE','CARDIO','OTRO') NULL,
    `difficulty` ENUM('INICIAL','INTERMEDIO','AVANZADO') NOT NULL DEFAULT 'INICIAL',
    `execution_type` ENUM('REPS','TIME','ROUNDS','DISTANCE','CIRCUIT','TECHNIQUE') NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED NULL,
    `created_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id_training_exercise`),
    UNIQUE KEY `uq_training_exercise_scope_slug` (`catalog_scope`, `slug`),
    UNIQUE KEY `uq_training_exercise_scope_id` (`id_training_exercise`, `catalog_scope`),
    KEY `idx_training_exercise_catalog` (`catalog_scope`, `active`, `discipline`, `name`),
    CONSTRAINT `fk_training_exercise_company`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `chk_training_exercise_active` CHECK (`active` IN (0,1)),
    CONSTRAINT `chk_training_exercise_slug` CHECK (`slug` REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_exercise_media` (
    `id_training_exercise_media` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_training_exercise` BIGINT UNSIGNED NOT NULL,
    `catalog_scope` INT UNSIGNED NOT NULL,
    `media_type` ENUM('IMAGE','VIDEO_REFERENCE') NOT NULL,
    `storage_key` VARCHAR(190) NULL,
    `external_url` VARCHAR(500) NULL,
    `mime_type` VARCHAR(80) NULL,
    `size_bytes` BIGINT UNSIGNED NULL,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `alt_text` VARCHAR(255) NOT NULL,
    `source` VARCHAR(255) NULL,
    `license` VARCHAR(120) NULL,
    `attribution` VARCHAR(500) NULL,
    `created_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_training_exercise_media`),
    UNIQUE KEY `uq_training_media_order` (`id_training_exercise`, `sort_order`),
    KEY `idx_training_media_scope` (`catalog_scope`, `id_training_exercise`),
    CONSTRAINT `fk_training_media_exercise_scope`
        FOREIGN KEY (`id_training_exercise`, `catalog_scope`)
        REFERENCES `training_exercise` (`id_training_exercise`, `catalog_scope`) ON DELETE CASCADE,
    CONSTRAINT `chk_training_media_reference` CHECK (
        (`media_type`='IMAGE' AND `storage_key` IS NOT NULL AND `external_url` IS NULL)
        OR (`media_type`='VIDEO_REFERENCE' AND `storage_key` IS NULL AND `external_url` IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_template` (
    `id_training_template` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_empresa` INT UNSIGNED NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `slug` VARCHAR(180) NOT NULL,
    `description` TEXT NULL,
    `objective` ENUM('FUERZA','HIPERTROFIA','ACONDICIONAMIENTO','TECNICA','MOVILIDAD','GENERAL','PREPARACION_FISICA') NOT NULL DEFAULT 'GENERAL',
    `level` ENUM('INICIAL','INTERMEDIO','AVANZADO','TODOS') NOT NULL DEFAULT 'TODOS',
    `days_per_week` TINYINT UNSIGNED NOT NULL,
    `status` ENUM('DRAFT','ACTIVE','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
    `created_by` INT UNSIGNED NOT NULL,
    `created_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id_training_template`),
    UNIQUE KEY `uq_training_template_company_slug` (`id_empresa`, `slug`),
    UNIQUE KEY `uq_training_template_scope` (`id_training_template`, `id_empresa`),
    KEY `idx_training_template_list` (`id_empresa`, `status`, `name`),
    CONSTRAINT `fk_training_template_company`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_training_template_creator_scope`
        FOREIGN KEY (`created_by`, `id_empresa`) REFERENCES `usuario` (`id_usuario`, `id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `chk_training_template_days` CHECK (`days_per_week` BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_template_discipline` (
    `id_training_template` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `discipline` ENUM('GYM','STRENGTH','BOXEO','MMA','BJJ','CONDITIONING','GENERAL') NOT NULL,
    PRIMARY KEY (`id_training_template`, `discipline`),
    CONSTRAINT `fk_training_template_discipline_scope`
        FOREIGN KEY (`id_training_template`, `id_empresa`)
        REFERENCES `training_template` (`id_training_template`, `id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_template_day` (
    `id_training_template_day` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_training_template` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `day_order` TINYINT UNSIGNED NOT NULL,
    `objective` ENUM('FUERZA','HIPERTROFIA','ACONDICIONAMIENTO','TECNICA','MOVILIDAD','GENERAL','PREPARACION_FISICA') NULL,
    `notes` TEXT NULL,
    PRIMARY KEY (`id_training_template_day`),
    UNIQUE KEY `uq_training_template_day_order` (`id_training_template`, `day_order`),
    UNIQUE KEY `uq_training_template_day_scope` (`id_training_template_day`, `id_empresa`),
    CONSTRAINT `fk_training_template_day_scope`
        FOREIGN KEY (`id_training_template`, `id_empresa`)
        REFERENCES `training_template` (`id_training_template`, `id_empresa`) ON DELETE CASCADE,
    CONSTRAINT `chk_training_template_day_order` CHECK (`day_order` BETWEEN 1 AND 31)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_template_block` (
    `id_training_template_block` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_training_template_day` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `block_type` ENUM('WARMUP','TECHNIQUE','STRENGTH','CIRCUIT','CONDITIONING','COOLDOWN','GENERAL') NOT NULL DEFAULT 'GENERAL',
    `block_order` TINYINT UNSIGNED NOT NULL,
    `circuit_rounds` SMALLINT UNSIGNED NULL,
    `round_rest_seconds` SMALLINT UNSIGNED NULL,
    `notes` TEXT NULL,
    PRIMARY KEY (`id_training_template_block`),
    UNIQUE KEY `uq_training_template_block_order` (`id_training_template_day`, `block_order`),
    UNIQUE KEY `uq_training_template_block_scope` (`id_training_template_block`, `id_empresa`),
    CONSTRAINT `fk_training_template_block_day_scope`
        FOREIGN KEY (`id_training_template_day`, `id_empresa`)
        REFERENCES `training_template_day` (`id_training_template_day`, `id_empresa`) ON DELETE CASCADE,
    CONSTRAINT `chk_training_template_block_order` CHECK (`block_order` BETWEEN 1 AND 64),
    CONSTRAINT `chk_training_template_circuit` CHECK (
        (`block_type`='CIRCUIT' AND `circuit_rounds` IS NOT NULL AND `circuit_rounds` >= 1)
        OR (`block_type`<>'CIRCUIT' AND `circuit_rounds` IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_template_exercise` (
    `id_training_template_exercise` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_training_template_block` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_training_exercise` BIGINT UNSIGNED NOT NULL,
    `exercise_catalog_scope` INT UNSIGNED NOT NULL,
    `execution_type` ENUM('REPS','TIME','ROUNDS','DISTANCE','CIRCUIT','TECHNIQUE') NOT NULL,
    `item_order` SMALLINT UNSIGNED NOT NULL,
    `sets_count` SMALLINT UNSIGNED NULL,
    `reps_count` SMALLINT UNSIGNED NULL,
    `load_kg` DECIMAL(8,3) NULL,
    `duration_seconds` INT UNSIGNED NULL,
    `rounds_count` SMALLINT UNSIGNED NULL,
    `round_duration_seconds` INT UNSIGNED NULL,
    `rest_seconds` INT UNSIGNED NULL,
    `distance_value` DECIMAL(10,2) NULL,
    `distance_unit` ENUM('M','KM') NULL,
    `work_seconds` INT UNSIGNED NULL,
    `transition_seconds` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id_training_template_exercise`),
    UNIQUE KEY `uq_training_template_exercise_order` (`id_training_template_block`, `item_order`),
    KEY `idx_training_template_exercise_scope` (`id_empresa`, `id_training_template_exercise`),
    CONSTRAINT `fk_training_template_exercise_block_scope`
        FOREIGN KEY (`id_training_template_block`, `id_empresa`)
        REFERENCES `training_template_block` (`id_training_template_block`, `id_empresa`) ON DELETE CASCADE,
    CONSTRAINT `fk_training_template_exercise_catalog_scope`
        FOREIGN KEY (`id_training_exercise`, `exercise_catalog_scope`)
        REFERENCES `training_exercise` (`id_training_exercise`, `catalog_scope`) ON DELETE RESTRICT,
    CONSTRAINT `chk_training_template_item_order` CHECK (`item_order` BETWEEN 1 AND 1000),
    CONSTRAINT `chk_training_template_nonnegative` CHECK (
        (`load_kg` IS NULL OR `load_kg` >= 0) AND
        (`rest_seconds` IS NULL OR `rest_seconds` >= 0) AND
        (`transition_seconds` IS NULL OR `transition_seconds` >= 0)
    ),
    CONSTRAINT `chk_training_template_execution_shape` CHECK (
        (`execution_type`='REPS' AND `sets_count` IS NOT NULL AND `reps_count` IS NOT NULL AND `duration_seconds` IS NULL AND `rounds_count` IS NULL AND `distance_value` IS NULL AND `work_seconds` IS NULL)
        OR (`execution_type`='TIME' AND `sets_count` IS NOT NULL AND `duration_seconds` IS NOT NULL AND `rounds_count` IS NULL AND `distance_value` IS NULL AND `work_seconds` IS NULL)
        OR (`execution_type`='ROUNDS' AND `rounds_count` IS NOT NULL AND `round_duration_seconds` IS NOT NULL AND `sets_count` IS NULL AND `duration_seconds` IS NULL AND `distance_value` IS NULL AND `work_seconds` IS NULL)
        OR (`execution_type`='DISTANCE' AND `sets_count` IS NOT NULL AND `distance_value` IS NOT NULL AND `distance_unit` IS NOT NULL AND `duration_seconds` IS NULL AND `rounds_count` IS NULL AND `work_seconds` IS NULL)
        OR (`execution_type`='CIRCUIT' AND (`work_seconds` IS NOT NULL OR `reps_count` IS NOT NULL) AND `transition_seconds` IS NOT NULL AND `sets_count` IS NULL AND `duration_seconds` IS NULL AND `rounds_count` IS NULL AND `distance_value` IS NULL)
        OR (`execution_type`='TECHNIQUE' AND (`duration_seconds` IS NOT NULL OR (`rounds_count` IS NOT NULL AND `round_duration_seconds` IS NOT NULL)) AND `sets_count` IS NULL AND `distance_value` IS NULL AND `work_seconds` IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_plan` (
    `id_training_plan` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `source_template_id` BIGINT UNSIGNED NULL,
    `name` VARCHAR(160) NOT NULL,
    `objective` ENUM('FUERZA','HIPERTROFIA','ACONDICIONAMIENTO','TECNICA','MOVILIDAD','GENERAL','PREPARACION_FISICA') NOT NULL DEFAULT 'GENERAL',
    `start_date` DATE NOT NULL,
    `end_date` DATE NULL,
    `status` ENUM('DRAFT','ACTIVE','COMPLETED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
    `notes` TEXT NULL,
    `created_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id_training_plan`),
    UNIQUE KEY `uq_training_plan_scope` (`id_training_plan`, `id_empresa`, `id_gimnasio`, `id_socio`),
    KEY `idx_training_plan_member` (`id_empresa`, `id_gimnasio`, `id_socio`, `status`, `start_date`),
    CONSTRAINT `fk_training_plan_company`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_training_plan_site_scope`
        FOREIGN KEY (`id_gimnasio`, `id_empresa`) REFERENCES `gimnasio` (`id_gimnasio`, `id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_training_plan_member_scope`
        FOREIGN KEY (`id_socio`, `id_empresa`) REFERENCES `usuario` (`id_usuario`, `id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_training_plan_creator_scope`
        FOREIGN KEY (`created_by`, `id_empresa`) REFERENCES `usuario` (`id_usuario`, `id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_training_plan_template_scope`
        FOREIGN KEY (`source_template_id`, `id_empresa`) REFERENCES `training_template` (`id_training_template`, `id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `chk_training_plan_dates` CHECK (`end_date` IS NULL OR `end_date` >= `start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_plan_discipline` (
    `id_training_plan` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `discipline` ENUM('GYM','STRENGTH','BOXEO','MMA','BJJ','CONDITIONING','GENERAL') NOT NULL,
    PRIMARY KEY (`id_training_plan`, `discipline`),
    CONSTRAINT `fk_training_plan_discipline_scope`
        FOREIGN KEY (`id_training_plan`, `id_empresa`, `id_gimnasio`, `id_socio`)
        REFERENCES `training_plan` (`id_training_plan`, `id_empresa`, `id_gimnasio`, `id_socio`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_plan_day` (
    `id_training_plan_day` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_training_plan` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `day_order` TINYINT UNSIGNED NOT NULL,
    `objective` ENUM('FUERZA','HIPERTROFIA','ACONDICIONAMIENTO','TECNICA','MOVILIDAD','GENERAL','PREPARACION_FISICA') NULL,
    `notes` TEXT NULL,
    PRIMARY KEY (`id_training_plan_day`),
    UNIQUE KEY `uq_training_plan_day_order` (`id_training_plan`, `day_order`),
    UNIQUE KEY `uq_training_plan_day_scope` (`id_training_plan_day`, `id_empresa`, `id_gimnasio`, `id_socio`),
    CONSTRAINT `fk_training_plan_day_scope`
        FOREIGN KEY (`id_training_plan`, `id_empresa`, `id_gimnasio`, `id_socio`)
        REFERENCES `training_plan` (`id_training_plan`, `id_empresa`, `id_gimnasio`, `id_socio`) ON DELETE CASCADE,
    CONSTRAINT `chk_training_plan_day_order` CHECK (`day_order` BETWEEN 1 AND 31)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_plan_block` (
    `id_training_plan_block` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_training_plan_day` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `block_type` ENUM('WARMUP','TECHNIQUE','STRENGTH','CIRCUIT','CONDITIONING','COOLDOWN','GENERAL') NOT NULL DEFAULT 'GENERAL',
    `block_order` TINYINT UNSIGNED NOT NULL,
    `circuit_rounds` SMALLINT UNSIGNED NULL,
    `round_rest_seconds` SMALLINT UNSIGNED NULL,
    `notes` TEXT NULL,
    PRIMARY KEY (`id_training_plan_block`),
    UNIQUE KEY `uq_training_plan_block_order` (`id_training_plan_day`, `block_order`),
    UNIQUE KEY `uq_training_plan_block_scope` (`id_training_plan_block`, `id_empresa`, `id_gimnasio`, `id_socio`),
    CONSTRAINT `fk_training_plan_block_day_scope`
        FOREIGN KEY (`id_training_plan_day`, `id_empresa`, `id_gimnasio`, `id_socio`)
        REFERENCES `training_plan_day` (`id_training_plan_day`, `id_empresa`, `id_gimnasio`, `id_socio`) ON DELETE CASCADE,
    CONSTRAINT `chk_training_plan_block_order` CHECK (`block_order` BETWEEN 1 AND 64),
    CONSTRAINT `chk_training_plan_circuit` CHECK (
        (`block_type`='CIRCUIT' AND `circuit_rounds` IS NOT NULL AND `circuit_rounds` >= 1)
        OR (`block_type`<>'CIRCUIT' AND `circuit_rounds` IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_plan_exercise` (
    `id_training_plan_exercise` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_training_plan_block` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `source_exercise_id` BIGINT UNSIGNED NULL,
    `exercise_name` VARCHAR(140) NOT NULL,
    `discipline` ENUM('GYM','STRENGTH','BOXEO','MMA','BJJ','CONDITIONING','GENERAL') NOT NULL,
    `instructions` TEXT NULL,
    `execution_type` ENUM('REPS','TIME','ROUNDS','DISTANCE','CIRCUIT','TECHNIQUE') NOT NULL,
    `item_order` SMALLINT UNSIGNED NOT NULL,
    `sets_count` SMALLINT UNSIGNED NULL,
    `reps_count` SMALLINT UNSIGNED NULL,
    `load_kg` DECIMAL(8,3) NULL,
    `duration_seconds` INT UNSIGNED NULL,
    `rounds_count` SMALLINT UNSIGNED NULL,
    `round_duration_seconds` INT UNSIGNED NULL,
    `rest_seconds` INT UNSIGNED NULL,
    `distance_value` DECIMAL(10,2) NULL,
    `distance_unit` ENUM('M','KM') NULL,
    `work_seconds` INT UNSIGNED NULL,
    `transition_seconds` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id_training_plan_exercise`),
    UNIQUE KEY `uq_training_plan_exercise_order` (`id_training_plan_block`, `item_order`),
    UNIQUE KEY `uq_training_plan_exercise_scope` (`id_training_plan_exercise`, `id_empresa`, `id_gimnasio`, `id_socio`),
    CONSTRAINT `fk_training_plan_exercise_block_scope`
        FOREIGN KEY (`id_training_plan_block`, `id_empresa`, `id_gimnasio`, `id_socio`)
        REFERENCES `training_plan_block` (`id_training_plan_block`, `id_empresa`, `id_gimnasio`, `id_socio`) ON DELETE CASCADE,
    -- 1001-2000 queda reservado únicamente al cambio de orden transaccional.
    -- Las escrituras de dominio aceptan exclusivamente el rango final 1-1000.
    CONSTRAINT `chk_training_plan_item_order` CHECK (`item_order` BETWEEN 1 AND 2000),
    CONSTRAINT `chk_training_plan_nonnegative` CHECK (
        (`load_kg` IS NULL OR `load_kg` >= 0) AND
        (`rest_seconds` IS NULL OR `rest_seconds` >= 0) AND
        (`transition_seconds` IS NULL OR `transition_seconds` >= 0)
    ),
    CONSTRAINT `chk_training_plan_execution_shape` CHECK (
        (`execution_type`='REPS' AND `sets_count` IS NOT NULL AND `reps_count` IS NOT NULL AND `duration_seconds` IS NULL AND `rounds_count` IS NULL AND `distance_value` IS NULL AND `work_seconds` IS NULL)
        OR (`execution_type`='TIME' AND `sets_count` IS NOT NULL AND `duration_seconds` IS NOT NULL AND `rounds_count` IS NULL AND `distance_value` IS NULL AND `work_seconds` IS NULL)
        OR (`execution_type`='ROUNDS' AND `rounds_count` IS NOT NULL AND `round_duration_seconds` IS NOT NULL AND `sets_count` IS NULL AND `duration_seconds` IS NULL AND `distance_value` IS NULL AND `work_seconds` IS NULL)
        OR (`execution_type`='DISTANCE' AND `sets_count` IS NOT NULL AND `distance_value` IS NOT NULL AND `distance_unit` IS NOT NULL AND `duration_seconds` IS NULL AND `rounds_count` IS NULL AND `work_seconds` IS NULL)
        OR (`execution_type`='CIRCUIT' AND (`work_seconds` IS NOT NULL OR `reps_count` IS NOT NULL) AND `transition_seconds` IS NOT NULL AND `sets_count` IS NULL AND `duration_seconds` IS NULL AND `rounds_count` IS NULL AND `distance_value` IS NULL)
        OR (`execution_type`='TECHNIQUE' AND (`duration_seconds` IS NOT NULL OR (`rounds_count` IS NOT NULL AND `round_duration_seconds` IS NOT NULL)) AND `sets_count` IS NULL AND `distance_value` IS NULL AND `work_seconds` IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_plan_exercise_media` (
    `id_training_plan_exercise_media` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_training_plan_exercise` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `media_type` ENUM('IMAGE','VIDEO_REFERENCE') NOT NULL,
    `storage_key` VARCHAR(190) NULL,
    `external_url` VARCHAR(500) NULL,
    `mime_type` VARCHAR(80) NULL,
    `sort_order` SMALLINT UNSIGNED NOT NULL,
    `alt_text` VARCHAR(255) NOT NULL,
    `source` VARCHAR(255) NULL,
    `license` VARCHAR(120) NULL,
    `attribution` VARCHAR(500) NULL,
    PRIMARY KEY (`id_training_plan_exercise_media`),
    UNIQUE KEY `uq_training_plan_media_order` (`id_training_plan_exercise`, `sort_order`),
    CONSTRAINT `fk_training_plan_media_exercise_scope`
        FOREIGN KEY (`id_training_plan_exercise`, `id_empresa`, `id_gimnasio`, `id_socio`)
        REFERENCES `training_plan_exercise` (`id_training_plan_exercise`, `id_empresa`, `id_gimnasio`, `id_socio`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_assignment` (
    `id_training_assignment` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_training_plan` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `assigned_by` INT UNSIGNED NOT NULL,
    `status` ENUM('ACTIVE','ENDED') NOT NULL DEFAULT 'ACTIVE',
    `active_member_scope` INT UNSIGNED GENERATED ALWAYS AS (IF(`status`='ACTIVE',`id_socio`,NULL)) STORED,
    `idempotency_key` CHAR(64) NOT NULL,
    `assigned_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ended_at_utc` DATETIME NULL,
    PRIMARY KEY (`id_training_assignment`),
    UNIQUE KEY `uq_training_assignment_idempotency` (`id_empresa`, `idempotency_key`),
    UNIQUE KEY `uq_training_assignment_active_member` (`id_empresa`, `active_member_scope`),
    KEY `idx_training_assignment_member_history` (`id_empresa`, `id_socio`, `assigned_at_utc`),
    CONSTRAINT `fk_training_assignment_plan_scope`
        FOREIGN KEY (`id_training_plan`, `id_empresa`, `id_gimnasio`, `id_socio`)
        REFERENCES `training_plan` (`id_training_plan`, `id_empresa`, `id_gimnasio`, `id_socio`) ON DELETE RESTRICT,
    CONSTRAINT `fk_training_assignment_actor_scope`
        FOREIGN KEY (`assigned_by`, `id_empresa`) REFERENCES `usuario` (`id_usuario`, `id_empresa`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_session` (
    `id_training_session` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_training_plan` BIGINT UNSIGNED NOT NULL,
    `id_training_plan_day` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `session_date` DATE NOT NULL,
    `status` ENUM('PENDING','COMPLETED','SKIPPED') NOT NULL DEFAULT 'PENDING',
    `notes` TEXT NULL,
    `idempotency_key` CHAR(64) NOT NULL,
    `completed_at_utc` DATETIME NULL,
    `created_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id_training_session`),
    UNIQUE KEY `uq_training_session_idempotency` (`id_empresa`, `idempotency_key`),
    UNIQUE KEY `uq_training_session_scope` (`id_training_session`, `id_empresa`, `id_gimnasio`, `id_socio`),
    KEY `idx_training_session_member` (`id_empresa`, `id_socio`, `session_date`, `status`),
    CONSTRAINT `fk_training_session_plan_scope`
        FOREIGN KEY (`id_training_plan`, `id_empresa`, `id_gimnasio`, `id_socio`)
        REFERENCES `training_plan` (`id_training_plan`, `id_empresa`, `id_gimnasio`, `id_socio`) ON DELETE RESTRICT,
    CONSTRAINT `fk_training_session_day_scope`
        FOREIGN KEY (`id_training_plan_day`, `id_empresa`, `id_gimnasio`, `id_socio`)
        REFERENCES `training_plan_day` (`id_training_plan_day`, `id_empresa`, `id_gimnasio`, `id_socio`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_session_exercise` (
    `id_training_session_exercise` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_training_session` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `id_training_plan_exercise` BIGINT UNSIGNED NOT NULL,
    `completed` TINYINT(1) NOT NULL DEFAULT 0,
    `actual_reps` SMALLINT UNSIGNED NULL,
    `actual_load_kg` DECIMAL(8,3) NULL,
    `actual_duration_seconds` INT UNSIGNED NULL,
    `actual_rounds` SMALLINT UNSIGNED NULL,
    `notes` TEXT NULL,
    PRIMARY KEY (`id_training_session_exercise`),
    UNIQUE KEY `uq_training_session_exercise` (`id_training_session`, `id_training_plan_exercise`),
    CONSTRAINT `fk_training_session_exercise_session_scope`
        FOREIGN KEY (`id_training_session`, `id_empresa`, `id_gimnasio`, `id_socio`)
        REFERENCES `training_session` (`id_training_session`, `id_empresa`, `id_gimnasio`, `id_socio`) ON DELETE CASCADE,
    CONSTRAINT `fk_training_session_exercise_plan_scope`
        FOREIGN KEY (`id_training_plan_exercise`, `id_empresa`, `id_gimnasio`, `id_socio`)
        REFERENCES `training_plan_exercise` (`id_training_plan_exercise`, `id_empresa`, `id_gimnasio`, `id_socio`) ON DELETE RESTRICT,
    CONSTRAINT `chk_training_session_completed` CHECK (`completed` IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo global inicial, escrito para Gimnera y sin recursos externos.
INSERT INTO `training_exercise`
(`id_empresa`,`name`,`slug`,`discipline`,`short_description`,`execution_instructions`,`muscle_group`,`equipment`,`difficulty`,`execution_type`,`created_by`)
VALUES
(NULL,'Press banca','press-banca','GYM','Empuje horizontal con barra.','Mantén apoyo estable, controla el descenso y evita rebotes.','PECHO','BARRA','INTERMEDIO','REPS',NULL),
(NULL,'Sentadilla','sentadilla','STRENGTH','Patrón de sentadilla con barra.','Mantén el tronco estable y adapta el recorrido a una técnica segura.','PIERNAS','BARRA','INTERMEDIO','REPS',NULL),
(NULL,'Trabajo de saco','trabajo-saco','BOXEO','Rounds de golpeo sobre saco.','Mantén guardia, desplazamiento y control técnico durante cada round.',NULL,'SACO','INTERMEDIO','ROUNDS',NULL),
(NULL,'Combinación jab-directo-crochet-salida','combinacion-jab-directo-crochet-salida','BOXEO','Secuencia técnica de boxeo.','Encadena jab, directo, crochet y salida lateral con control.',NULL,'MANOPLAS','INTERMEDIO','TECHNIQUE',NULL),
(NULL,'Drill técnico MMA','drill-tecnico-mma','MMA','Trabajo técnico estructurado por rounds.','Prioriza precisión y control sobre intensidad.',NULL,'TATAMI','INTERMEDIO','TECHNIQUE',NULL),
(NULL,'Trabajo posicional BJJ','trabajo-posicional-bjj','BJJ','Rounds desde una posición acordada.','Define posición inicial, objetivo y condición de reinicio.',NULL,'TATAMI','INTERMEDIO','ROUNDS',NULL),
(NULL,'Sprawls','sprawls','CONDITIONING','Trabajo de acondicionamiento con sprawls.','Mantén control lumbar y vuelve a posición estable.',NULL,'PESO_CORPORAL','INTERMEDIO','TIME',NULL),
(NULL,'Ground and pound','ground-and-pound','MMA','Trabajo técnico de golpeo desde posición de suelo.','Usar material y control apropiados para el contexto técnico.',NULL,'SACO','INTERMEDIO','TIME',NULL),
(NULL,'Battle ropes','battle-ropes','CONDITIONING','Intervalos con cuerdas de batalla.','Mantén base estable y ritmo controlado.','FULL_BODY','BATTLE_ROPE','INICIAL','TIME',NULL);
