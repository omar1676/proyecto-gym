-- Fase 26: política lógica de acceso independiente del proveedor físico.
--
-- No contiene biometría, credenciales externas ni comandos de apertura.
-- Los instantes se persisten siempre en UTC. La fila access_policy es el
-- estado actual y access_policy_event conserva el historial inmutable.

CREATE TABLE `access_policy` (
    `id_access_policy` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `state` ENUM('ALLOWED','TEMPORARY','SUSPENDED','DENIED','PERMANENT_BLOCK') NOT NULL,
    `starts_at_utc` DATETIME NULL,
    `expires_at_utc` DATETIME NULL,
    `suspended_until_utc` DATETIME NULL,
    `reason_code` VARCHAR(64) NOT NULL,
    `reason_note` VARCHAR(255) NULL,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED NULL,
    `updated_by` INT UNSIGNED NULL,
    `created_at_utc` DATETIME NOT NULL,
    `updated_at_utc` DATETIME NOT NULL,
    PRIMARY KEY (`id_access_policy`),
    UNIQUE KEY `uq_access_policy_member_scope` (`id_empresa`,`id_gimnasio`,`id_socio`),
    UNIQUE KEY `uq_access_policy_full_scope` (`id_access_policy`,`id_empresa`,`id_gimnasio`,`id_socio`),
    KEY `idx_access_policy_expiry` (`state`,`expires_at_utc`,`id_access_policy`),
    KEY `idx_access_policy_dashboard` (`id_empresa`,`id_gimnasio`,`state`,`expires_at_utc`),
    CONSTRAINT `chk_access_policy_version` CHECK (`version` >= 1),
    CONSTRAINT `chk_access_policy_temporary_expiry` CHECK (`state` <> 'TEMPORARY' OR `expires_at_utc` IS NOT NULL),
    CONSTRAINT `chk_access_policy_interval` CHECK (`expires_at_utc` IS NULL OR `starts_at_utc` IS NULL OR `expires_at_utc` > `starts_at_utc`),
    CONSTRAINT `fk_access_policy_company`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_access_policy_site_scope`
        FOREIGN KEY (`id_gimnasio`,`id_empresa`)
        REFERENCES `gimnasio` (`id_gimnasio`,`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_access_policy_member_scope`
        FOREIGN KEY (`id_socio`,`id_empresa`,`id_gimnasio`)
        REFERENCES `usuario` (`id_usuario`,`id_empresa`,`id_gimnasio`) ON DELETE RESTRICT,
    CONSTRAINT `fk_access_policy_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `usuario` (`id_usuario`) ON DELETE SET NULL,
    CONSTRAINT `fk_access_policy_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `usuario` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `access_policy_event` (
    `id_access_policy_event` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_id` CHAR(36) NOT NULL,
    `correlation_id` CHAR(36) NOT NULL,
    `id_access_policy` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `id_actor` INT UNSIGNED NULL,
    `actor_role` VARCHAR(32) NOT NULL,
    `origin` ENUM('WEB','CRON','SYSTEM','API','MOBILE') NOT NULL,
    `action` VARCHAR(64) NOT NULL,
    `previous_state` ENUM('ALLOWED','TEMPORARY','SUSPENDED','DENIED','PERMANENT_BLOCK') NULL,
    `new_state` ENUM('ALLOWED','TEMPORARY','SUSPENDED','DENIED','PERMANENT_BLOCK') NOT NULL,
    `starts_at_utc` DATETIME NULL,
    `expires_at_utc` DATETIME NULL,
    `reason_code` VARCHAR(64) NOT NULL,
    `result` ENUM('SUCCESS','REJECTED') NOT NULL,
    `idempotency_key` CHAR(64) NOT NULL,
    `created_at_utc` DATETIME NOT NULL,
    PRIMARY KEY (`id_access_policy_event`),
    UNIQUE KEY `uq_access_policy_event_id` (`event_id`),
    UNIQUE KEY `uq_access_policy_event_idempotency` (`id_empresa`,`idempotency_key`),
    KEY `idx_access_policy_event_member` (`id_empresa`,`id_gimnasio`,`id_socio`,`created_at_utc`),
    KEY `idx_access_policy_event_action` (`id_empresa`,`action`,`created_at_utc`),
    CONSTRAINT `fk_access_policy_event_scope`
        FOREIGN KEY (`id_access_policy`,`id_empresa`,`id_gimnasio`,`id_socio`)
        REFERENCES `access_policy` (`id_access_policy`,`id_empresa`,`id_gimnasio`,`id_socio`) ON DELETE RESTRICT,
    CONSTRAINT `fk_access_policy_event_actor`
        FOREIGN KEY (`id_actor`) REFERENCES `usuario` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
