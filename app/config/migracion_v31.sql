-- Fase 24: motor de retención determinista basado en eventos genéricos de asistencia.
-- No contiene biometría ni integra ningún proveedor físico. Todos los datos
-- quedan vinculados a empresa, sede y socio mediante claves tenant-aware.

ALTER TABLE `tipo_membresia`
    ADD UNIQUE KEY `uq_tipo_membresia_scope` (`id_tipo_membresia`, `id_empresa`);

ALTER TABLE `usuario`
    ADD UNIQUE KEY `uq_usuario_company_scope` (`id_usuario`, `id_empresa`);

CREATE TABLE `retention_config` (
    `id_empresa` INT UNSIGNED NOT NULL,
    `timezone` VARCHAR(64) NOT NULL DEFAULT 'Europe/Madrid',
    `baseline_days` SMALLINT UNSIGNED NOT NULL DEFAULT 56,
    `recent_days` SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    `min_history_days` SMALLINT UNSIGNED NOT NULL DEFAULT 28,
    `min_baseline_visits` SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    `min_baseline_active_weeks` SMALLINT UNSIGNED NOT NULL DEFAULT 4,
    `min_baseline_weekly_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.75,
    `attention_drop_pct` DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    `high_attention_drop_pct` DECIMAL(5,2) NOT NULL DEFAULT 75.00,
    `cooldown_days` SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    `template_general` VARCHAR(700) NOT NULL,
    `template_gym` VARCHAR(700) NOT NULL,
    `template_boxeo` VARCHAR(700) NOT NULL,
    `template_tatami` VARCHAR(700) NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_empresa`),
    CONSTRAINT `chk_retention_windows` CHECK (`baseline_days` >= 28 AND `recent_days` >= 7),
    CONSTRAINT `chk_retention_thresholds` CHECK (
        `attention_drop_pct` >= 0 AND `attention_drop_pct` <= 100
        AND `high_attention_drop_pct` >= `attention_drop_pct`
        AND `high_attention_drop_pct` <= 100
    ),
    CONSTRAINT `fk_retention_config_company`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `retention_config` (
    `id_empresa`, `template_general`, `template_gym`, `template_boxeo`, `template_tatami`
)
SELECT e.`id_empresa`,
       'Hola, {nombre}. ¡Hace unos días que no te vemos! Esperamos que todo vaya bien. Cuando quieras volver a entrenar, aquí te esperamos.',
       'Hola, {nombre}. ¡Hace unos días que no te vemos por el gimnasio! Esperamos que todo vaya genial. Las pesas te echan de menos. Cuando quieras volver a entrenar, aquí te esperamos.',
       'Hola, {nombre}. ¡Hace unos días que no te vemos por boxeo! Esperamos que todo vaya bien. Los guantes te echan de menos. Cuando quieras volver, aquí te esperamos.',
       'Hola, {nombre}. ¡Hace unos días que no te vemos por el tatami! Esperamos que todo vaya genial. El tatami te echa de menos. Cuando quieras volver a entrenar, aquí te esperamos.'
  FROM `empresa` e;

CREATE TABLE `retention_activity_mapping` (
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_tipo_membresia` INT UNSIGNED NOT NULL,
    `activity_family` ENUM('GYM','BOXEO','TATAMI') NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_empresa`, `id_tipo_membresia`, `activity_family`),
    KEY `idx_retention_mapping_type` (`id_tipo_membresia`, `id_empresa`),
    CONSTRAINT `fk_retention_mapping_company`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_retention_mapping_membership_scope`
        FOREIGN KEY (`id_tipo_membresia`, `id_empresa`)
        REFERENCES `tipo_membresia` (`id_tipo_membresia`, `id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mapeo inicial genérico; puede personalizarse por tenant sin cambiar código.
INSERT IGNORE INTO `retention_activity_mapping` (`id_empresa`, `id_tipo_membresia`, `activity_family`)
SELECT `id_empresa`, `id_tipo_membresia`, 'GYM'
  FROM `tipo_membresia`
 WHERE LOWER(`nombre`) LIKE '%gimnasio%' OR LOWER(`nombre`) LIKE '%pesas%';
INSERT IGNORE INTO `retention_activity_mapping` (`id_empresa`, `id_tipo_membresia`, `activity_family`)
SELECT `id_empresa`, `id_tipo_membresia`, 'BOXEO'
  FROM `tipo_membresia`
 WHERE LOWER(`nombre`) LIKE '%boxeo%';
INSERT IGNORE INTO `retention_activity_mapping` (`id_empresa`, `id_tipo_membresia`, `activity_family`)
SELECT `id_empresa`, `id_tipo_membresia`, 'TATAMI'
  FROM `tipo_membresia`
 WHERE LOWER(`nombre`) LIKE '%mma%'
    OR LOWER(`nombre`) LIKE '%bjj%'
    OR LOWER(`nombre`) LIKE '%jiu-jitsu%'
    OR LOWER(`nombre`) LIKE '%jiu jitsu%'
    OR LOWER(`nombre`) LIKE '%tatami%';

CREATE TABLE `attendance_event` (
    `id_attendance_event` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_id` CHAR(36) NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `occurred_at_utc` DATETIME NOT NULL,
    `local_date` DATE NOT NULL,
    `event_type` ENUM('ATTENDANCE') NOT NULL DEFAULT 'ATTENDANCE',
    `source` ENUM('MANUAL','IMPORT','ACCESS_PROVIDER','API') NOT NULL,
    `external_reference` VARCHAR(190) NULL,
    `idempotency_key` CHAR(64) NOT NULL,
    `activity_family` ENUM('GYM','BOXEO','TATAMI') NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_attendance_event`),
    UNIQUE KEY `uq_attendance_event_id` (`event_id`),
    UNIQUE KEY `uq_attendance_idempotency` (`id_empresa`, `idempotency_key`),
    UNIQUE KEY `uq_attendance_external` (`id_empresa`, `source`, `external_reference`),
    KEY `idx_attendance_member_date` (`id_empresa`, `id_socio`, `local_date`),
    KEY `idx_attendance_scope_date` (`id_empresa`, `id_gimnasio`, `local_date`),
    CONSTRAINT `fk_attendance_company`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_attendance_site_scope`
        FOREIGN KEY (`id_gimnasio`, `id_empresa`)
        REFERENCES `gimnasio` (`id_gimnasio`, `id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_attendance_member_scope`
        FOREIGN KEY (`id_socio`, `id_empresa`)
        REFERENCES `usuario` (`id_usuario`, `id_empresa`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `retention_run` (
    `id_retention_run` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_id` CHAR(36) NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `evaluation_date` DATE NOT NULL,
    `algorithm_version` VARCHAR(32) NOT NULL,
    `status` ENUM('RUNNING','COMPLETED','FAILED') NOT NULL DEFAULT 'RUNNING',
    `evaluated_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `insufficient_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `normal_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `attention_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `high_attention_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `returned_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `error_code` VARCHAR(64) NULL,
    `started_at_utc` DATETIME NOT NULL,
    `finished_at_utc` DATETIME NULL,
    PRIMARY KEY (`id_retention_run`),
    UNIQUE KEY `uq_retention_run_id` (`run_id`),
    UNIQUE KEY `uq_retention_run_daily` (`id_empresa`, `evaluation_date`, `algorithm_version`),
    KEY `idx_retention_run_status` (`status`, `started_at_utc`),
    CONSTRAINT `fk_retention_run_company`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `retention_detection` (
    `id_retention_detection` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `detection_id` CHAR(36) NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `id_retention_run` BIGINT UNSIGNED NOT NULL,
    `evaluation_date` DATE NOT NULL,
    `level` ENUM('ATTENTION','HIGH_ATTENTION') NOT NULL,
    `status` ENUM('OPEN','REVIEWED','DISMISSED','POSTPONED','CONTACTED','RETURNED') NOT NULL DEFAULT 'OPEN',
    `activity_family` ENUM('GYM','BOXEO','TATAMI','GENERAL') NOT NULL DEFAULT 'GENERAL',
    `baseline_visits` SMALLINT UNSIGNED NOT NULL,
    `recent_visits` SMALLINT UNSIGNED NOT NULL,
    `baseline_weekly_rate` DECIMAL(7,2) NOT NULL,
    `recent_weekly_rate` DECIMAL(7,2) NOT NULL,
    `drop_pct` DECIMAL(5,2) NOT NULL,
    `last_attendance_utc` DATETIME NULL,
    `detected_at_utc` DATETIME NOT NULL,
    `cooldown_until` DATE NOT NULL,
    `next_review_at` DATE NULL,
    `contacted_at_utc` DATETIME NULL,
    `returned_at_utc` DATETIME NULL,
    `days_to_return` SMALLINT UNSIGNED NULL,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `updated_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_retention_detection`),
    UNIQUE KEY `uq_retention_detection_id` (`detection_id`),
    UNIQUE KEY `uq_retention_detection_daily` (`id_empresa`, `id_socio`, `evaluation_date`),
    UNIQUE KEY `uq_retention_detection_scope` (`id_retention_detection`, `id_empresa`, `id_gimnasio`, `id_socio`),
    KEY `idx_retention_inbox` (`id_empresa`, `id_gimnasio`, `status`, `level`, `detected_at_utc`),
    KEY `idx_retention_member_cooldown` (`id_empresa`, `id_socio`, `cooldown_until`),
    CONSTRAINT `fk_retention_detection_company`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_retention_detection_site_scope`
        FOREIGN KEY (`id_gimnasio`, `id_empresa`)
        REFERENCES `gimnasio` (`id_gimnasio`, `id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_retention_detection_member_scope`
        FOREIGN KEY (`id_socio`, `id_empresa`)
        REFERENCES `usuario` (`id_usuario`, `id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_retention_detection_run`
        FOREIGN KEY (`id_retention_run`) REFERENCES `retention_run` (`id_retention_run`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `retention_action` (
    `id_retention_action` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `action_id` CHAR(36) NOT NULL,
    `id_retention_detection` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `id_actor` INT UNSIGNED NULL,
    `action` ENUM('REVIEW','DISMISS','POSTPONE','CONTACT_MANUAL','RETURN_AUTO') NOT NULL,
    `reason` VARCHAR(255) NULL,
    `idempotency_key` CHAR(64) NOT NULL,
    `created_at_utc` DATETIME NOT NULL,
    PRIMARY KEY (`id_retention_action`),
    UNIQUE KEY `uq_retention_action_id` (`action_id`),
    UNIQUE KEY `uq_retention_action_idempotency` (`id_empresa`, `idempotency_key`),
    KEY `idx_retention_action_detection` (`id_retention_detection`, `created_at_utc`),
    CONSTRAINT `fk_retention_action_detection_scope`
        FOREIGN KEY (`id_retention_detection`, `id_empresa`, `id_gimnasio`, `id_socio`)
        REFERENCES `retention_detection` (`id_retention_detection`, `id_empresa`, `id_gimnasio`, `id_socio`) ON DELETE RESTRICT,
    CONSTRAINT `fk_retention_action_actor`
        FOREIGN KEY (`id_actor`) REFERENCES `usuario` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
