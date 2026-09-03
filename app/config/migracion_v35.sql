-- Migración v35: foundation organizativa de Gimnera Restaurants.
--
-- Mantiene el dominio separado de Gym. `empresa` es, de forma transitoria,
-- el límite tenant de Platform; marca, entidad legal y local son conceptos
-- propios de Restaurants y quedan protegidos por claves compuestas de ámbito.

CREATE TABLE IF NOT EXISTS `restaurant_account` (
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_empresa` INT UNSIGNED NOT NULL,
    `idempotency_key` CHAR(36) NOT NULL,
    `request_fingerprint` CHAR(64) NOT NULL,
    `status` ENUM('CONFIGURING','ACTIVE','SUSPENDED','CANCELLED') NOT NULL DEFAULT 'CONFIGURING',
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_account`),
    UNIQUE KEY `uq_restaurant_account_company` (`id_empresa`),
    UNIQUE KEY `uq_restaurant_account_idempotency` (`idempotency_key`),
    UNIQUE KEY `uq_restaurant_account_scope` (`id_restaurant_account`,`id_empresa`),
    CONSTRAINT `fk_restaurant_account_company`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_account_version` CHECK (`version` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restaurant_brand` (
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `slug` VARCHAR(80) NOT NULL,
    `status` ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_brand`),
    UNIQUE KEY `uq_restaurant_brand_company_slug` (`id_empresa`,`slug`),
    UNIQUE KEY `uq_restaurant_brand_scope` (`id_restaurant_brand`,`id_restaurant_account`,`id_empresa`),
    KEY `idx_restaurant_brand_account` (`id_restaurant_account`,`id_empresa`),
    CONSTRAINT `fk_restaurant_brand_account_scope`
        FOREIGN KEY (`id_restaurant_account`,`id_empresa`)
        REFERENCES `restaurant_account` (`id_restaurant_account`,`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_brand_version` CHECK (`version` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restaurant_legal_entity` (
    `id_restaurant_legal_entity` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `name` VARCHAR(180) NOT NULL,
    `code` VARCHAR(80) NOT NULL,
    `status` ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_legal_entity`),
    UNIQUE KEY `uq_restaurant_legal_company_code` (`id_empresa`,`code`),
    UNIQUE KEY `uq_restaurant_legal_scope` (`id_restaurant_legal_entity`,`id_restaurant_account`,`id_empresa`),
    KEY `idx_restaurant_legal_account` (`id_restaurant_account`,`id_empresa`),
    CONSTRAINT `fk_restaurant_legal_account_scope`
        FOREIGN KEY (`id_restaurant_account`,`id_empresa`)
        REFERENCES `restaurant_account` (`id_restaurant_account`,`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_legal_version` CHECK (`version` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `restaurant_location` (
    `id_restaurant_location` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_legal_entity` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `slug` VARCHAR(80) NOT NULL,
    `timezone` VARCHAR(64) NOT NULL,
    `status` ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_location`),
    UNIQUE KEY `uq_restaurant_location_company_slug` (`id_empresa`,`slug`),
    UNIQUE KEY `uq_restaurant_location_scope` (`id_restaurant_location`,`id_restaurant_account`,`id_empresa`),
    KEY `idx_restaurant_location_account` (`id_restaurant_account`,`id_empresa`),
    KEY `idx_restaurant_location_brand_scope` (`id_restaurant_brand`,`id_restaurant_account`,`id_empresa`),
    KEY `idx_restaurant_location_legal_scope` (`id_restaurant_legal_entity`,`id_restaurant_account`,`id_empresa`),
    CONSTRAINT `fk_restaurant_location_account_scope`
        FOREIGN KEY (`id_restaurant_account`,`id_empresa`)
        REFERENCES `restaurant_account` (`id_restaurant_account`,`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_restaurant_location_brand_scope`
        FOREIGN KEY (`id_restaurant_brand`,`id_restaurant_account`,`id_empresa`)
        REFERENCES `restaurant_brand` (`id_restaurant_brand`,`id_restaurant_account`,`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_restaurant_location_legal_scope`
        FOREIGN KEY (`id_restaurant_legal_entity`,`id_restaurant_account`,`id_empresa`)
        REFERENCES `restaurant_legal_entity` (`id_restaurant_legal_entity`,`id_restaurant_account`,`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_location_version` CHECK (`version` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
