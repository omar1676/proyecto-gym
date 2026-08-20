-- Fase 10: frontera genérica y segura para control de acceso.
--
-- No contiene biometría, comandos de apertura ni configuración de hardware.
-- MySQL actúa como outbox sencilla y persistente para el piloto.

ALTER TABLE `gimnasio`
    ADD UNIQUE KEY `uq_gimnasio_access_scope` (`id_gimnasio`, `id_empresa`);

ALTER TABLE `usuario`
    ADD UNIQUE KEY `uq_usuario_access_scope` (`id_usuario`, `id_empresa`, `id_gimnasio`);

CREATE TABLE `access_identity_map` (
    `id_map` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `provider` VARCHAR(64) NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `external_identity_id` VARCHAR(190) NOT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_map`),
    UNIQUE KEY `uq_access_external_identity` (`id_empresa`, `provider`, `external_identity_id`),
    UNIQUE KEY `uq_access_member_provider_site` (`id_empresa`, `provider`, `id_socio`, `id_gimnasio`),
    KEY `idx_access_map_scope` (`id_empresa`, `id_gimnasio`, `status`),
    CONSTRAINT `fk_access_map_site_scope`
        FOREIGN KEY (`id_gimnasio`, `id_empresa`)
        REFERENCES `gimnasio` (`id_gimnasio`, `id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_access_map_member_scope`
        FOREIGN KEY (`id_socio`, `id_empresa`, `id_gimnasio`)
        REFERENCES `usuario` (`id_usuario`, `id_empresa`, `id_gimnasio`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `access_sync_job` (
    `id_job` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `id_usuario` INT UNSIGNED NULL,
    `provider` VARCHAR(64) NOT NULL,
    `decision_state` ENUM('PERMITIDO','BLOQUEADO','REVISAR') NOT NULL,
    `reason_code` VARCHAR(64) NOT NULL,
    `decision_version` VARCHAR(64) NOT NULL,
    `decision_at` DATETIME NOT NULL,
    `correlation_id` CHAR(36) NOT NULL,
    `idempotency_key` CHAR(64) NOT NULL,
    `status` ENUM('PENDING','PROCESSING','SYNCED','FAILED','RETRY') NOT NULL DEFAULT 'PENDING',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `next_attempt_at` DATETIME NULL,
    `last_error_code` VARCHAR(64) NULL,
    `provider_result_code` VARCHAR(64) NULL,
    `locked_at` DATETIME NULL,
    `locked_by` VARCHAR(64) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_job`),
    UNIQUE KEY `uq_access_job_idempotency` (`id_empresa`, `provider`, `idempotency_key`),
    UNIQUE KEY `uq_access_job_correlation` (`id_empresa`, `correlation_id`),
    KEY `idx_access_job_ready` (`status`, `next_attempt_at`, `created_at`),
    KEY `idx_access_job_scope` (`id_empresa`, `id_gimnasio`, `status`, `created_at`),
    CONSTRAINT `chk_access_job_attempts` CHECK (`attempts` <= `max_attempts`),
    CONSTRAINT `fk_access_job_site_scope`
        FOREIGN KEY (`id_gimnasio`, `id_empresa`)
        REFERENCES `gimnasio` (`id_gimnasio`, `id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_access_job_member_scope`
        FOREIGN KEY (`id_socio`, `id_empresa`, `id_gimnasio`)
        REFERENCES `usuario` (`id_usuario`, `id_empresa`, `id_gimnasio`) ON DELETE RESTRICT,
    CONSTRAINT `fk_access_job_actor`
        FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `access_control_audit` (
    `id_audit` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `id_usuario` INT UNSIGNED NULL,
    `id_job` BIGINT UNSIGNED NULL,
    `actor_process` VARCHAR(64) NOT NULL,
    `provider` VARCHAR(64) NOT NULL,
    `action` VARCHAR(64) NOT NULL,
    `decision_state` ENUM('PERMITIDO','BLOQUEADO','REVISAR') NOT NULL,
    `reason_code` VARCHAR(64) NOT NULL,
    `result_code` VARCHAR(64) NOT NULL,
    `correlation_id` CHAR(36) NOT NULL,
    `latency_ms` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_audit`),
    KEY `idx_access_audit_scope` (`id_empresa`, `id_gimnasio`, `created_at`),
    KEY `idx_access_audit_member` (`id_empresa`, `id_socio`, `created_at`),
    KEY `idx_access_audit_correlation` (`id_empresa`, `correlation_id`),
    CONSTRAINT `fk_access_audit_site_scope`
        FOREIGN KEY (`id_gimnasio`, `id_empresa`)
        REFERENCES `gimnasio` (`id_gimnasio`, `id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_access_audit_member_scope`
        FOREIGN KEY (`id_socio`, `id_empresa`, `id_gimnasio`)
        REFERENCES `usuario` (`id_usuario`, `id_empresa`, `id_gimnasio`) ON DELETE RESTRICT,
    CONSTRAINT `fk_access_audit_actor`
        FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON DELETE SET NULL,
    CONSTRAINT `fk_access_audit_job`
        FOREIGN KEY (`id_job`) REFERENCES `access_sync_job` (`id_job`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
