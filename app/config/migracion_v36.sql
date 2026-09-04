-- Migración v36: foundation de menú y catálogo de Gimnera Restaurants.
--
-- Este esquema es deliberadamente domain-first: no crea pedidos, mesas, QR,
-- cocina, pagos, recetas, stock ni fiscalidad. Todas las relaciones críticas
-- conservan empresa/account/brand para que MariaDB también rechace cruces de
-- tenant aunque la capa PHP falle.

ALTER TABLE `restaurant_location`
    ADD UNIQUE KEY `uq_restaurant_location_brand_scope`
        (`id_restaurant_location`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`);

CREATE TABLE `restaurant_catalog` (
    `id_restaurant_catalog` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `description` VARCHAR(500) NULL,
    `status` ENUM('DRAFT','ACTIVE','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `idempotency_key` CHAR(36) NOT NULL,
    `request_fingerprint` CHAR(64) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_catalog`),
    UNIQUE KEY `uq_restaurant_catalog_slug` (`id_empresa`,`id_restaurant_brand`,`slug`),
    UNIQUE KEY `uq_restaurant_catalog_idempotency` (`id_empresa`,`idempotency_key`),
    UNIQUE KEY `uq_restaurant_catalog_scope`
        (`id_restaurant_catalog`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`),
    KEY `idx_restaurant_catalog_brand_status`
        (`id_empresa`,`id_restaurant_brand`,`status`,`id_restaurant_catalog`),
    CONSTRAINT `fk_restaurant_catalog_brand_scope`
        FOREIGN KEY (`id_restaurant_brand`,`id_restaurant_account`,`id_empresa`)
        REFERENCES `restaurant_brand` (`id_restaurant_brand`,`id_restaurant_account`,`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_catalog_version` CHECK (`version` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restaurant_catalog_location` (
    `id_restaurant_catalog` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_location` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `status` ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_catalog`,`id_restaurant_location`),
    KEY `idx_restaurant_catalog_location_lookup`
        (`id_empresa`,`id_restaurant_brand`,`id_restaurant_location`,`status`),
    CONSTRAINT `fk_restaurant_catalog_location_catalog`
        FOREIGN KEY (`id_restaurant_catalog`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_catalog`
            (`id_restaurant_catalog`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `fk_restaurant_catalog_location_location`
        FOREIGN KEY (`id_restaurant_location`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_location`
            (`id_restaurant_location`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restaurant_category` (
    `id_restaurant_category` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_catalog` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `description` VARCHAR(500) NULL,
    `status` ENUM('ACTIVE','INACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `idempotency_key` CHAR(36) NOT NULL,
    `request_fingerprint` CHAR(64) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_category`),
    UNIQUE KEY `uq_restaurant_category_slug` (`id_restaurant_catalog`,`slug`),
    UNIQUE KEY `uq_restaurant_category_idempotency` (`id_empresa`,`idempotency_key`),
    UNIQUE KEY `uq_restaurant_category_scope`
        (`id_restaurant_category`,`id_restaurant_catalog`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`),
    KEY `idx_restaurant_category_listing`
        (`id_restaurant_catalog`,`status`,`sort_order`,`id_restaurant_category`),
    CONSTRAINT `fk_restaurant_category_catalog_scope`
        FOREIGN KEY (`id_restaurant_catalog`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_catalog`
            (`id_restaurant_catalog`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_category_version` CHECK (`version` >= 1),
    CONSTRAINT `chk_restaurant_category_order` CHECK (`sort_order` <= 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restaurant_product` (
    `id_restaurant_product` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `slug` VARCHAR(120) NOT NULL,
    `description` VARCHAR(2000) NULL,
    `status` ENUM('DRAFT','ACTIVE','INACTIVE','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `idempotency_key` CHAR(36) NOT NULL,
    `request_fingerprint` CHAR(64) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_product`),
    UNIQUE KEY `uq_restaurant_product_slug` (`id_empresa`,`id_restaurant_brand`,`slug`),
    UNIQUE KEY `uq_restaurant_product_idempotency` (`id_empresa`,`idempotency_key`),
    UNIQUE KEY `uq_restaurant_product_scope`
        (`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`),
    KEY `idx_restaurant_product_listing`
        (`id_empresa`,`id_restaurant_brand`,`status`,`name`,`id_restaurant_product`),
    CONSTRAINT `fk_restaurant_product_brand_scope`
        FOREIGN KEY (`id_restaurant_brand`,`id_restaurant_account`,`id_empresa`)
        REFERENCES `restaurant_brand` (`id_restaurant_brand`,`id_restaurant_account`,`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_product_version` CHECK (`version` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restaurant_product_category` (
    `id_restaurant_product` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_category` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_catalog` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_product`,`id_restaurant_category`),
    KEY `idx_restaurant_product_category_listing`
        (`id_restaurant_category`,`sort_order`,`id_restaurant_product`),
    CONSTRAINT `fk_restaurant_product_category_product`
        FOREIGN KEY (`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_product`
            (`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `fk_restaurant_product_category_category`
        FOREIGN KEY (`id_restaurant_category`,`id_restaurant_catalog`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_category`
            (`id_restaurant_category`,`id_restaurant_catalog`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_product_category_order` CHECK (`sort_order` <= 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restaurant_product_variant` (
    `id_restaurant_product_variant` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_product` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `label` VARCHAR(120) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `status` ENUM('ACTIVE','INACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `idempotency_key` CHAR(36) NOT NULL,
    `request_fingerprint` CHAR(64) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_product_variant`),
    UNIQUE KEY `uq_restaurant_variant_slug` (`id_restaurant_product`,`slug`),
    UNIQUE KEY `uq_restaurant_variant_idempotency` (`id_empresa`,`idempotency_key`),
    UNIQUE KEY `uq_restaurant_variant_scope`
        (`id_restaurant_product_variant`,`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`),
    KEY `idx_restaurant_variant_listing`
        (`id_restaurant_product`,`status`,`sort_order`,`id_restaurant_product_variant`),
    CONSTRAINT `fk_restaurant_variant_product_scope`
        FOREIGN KEY (`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_product`
            (`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_variant_version` CHECK (`version` >= 1),
    CONSTRAINT `chk_restaurant_variant_order` CHECK (`sort_order` <= 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restaurant_modifier_group` (
    `id_restaurant_modifier_group` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `is_required` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `min_selections` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `max_selections` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('ACTIVE','INACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `idempotency_key` CHAR(36) NOT NULL,
    `request_fingerprint` CHAR(64) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_modifier_group`),
    UNIQUE KEY `uq_restaurant_modifier_group_slug` (`id_empresa`,`id_restaurant_brand`,`slug`),
    UNIQUE KEY `uq_restaurant_modifier_group_idempotency` (`id_empresa`,`idempotency_key`),
    UNIQUE KEY `uq_restaurant_modifier_group_scope`
        (`id_restaurant_modifier_group`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`),
    KEY `idx_restaurant_modifier_group_listing`
        (`id_empresa`,`id_restaurant_brand`,`status`,`sort_order`,`id_restaurant_modifier_group`),
    CONSTRAINT `fk_restaurant_modifier_group_brand_scope`
        FOREIGN KEY (`id_restaurant_brand`,`id_restaurant_account`,`id_empresa`)
        REFERENCES `restaurant_brand` (`id_restaurant_brand`,`id_restaurant_account`,`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_modifier_group_bounds`
        CHECK (`min_selections` <= `max_selections` AND `max_selections` <= 50),
    CONSTRAINT `chk_restaurant_modifier_group_required`
        CHECK ((`is_required` = 0 AND `min_selections` = 0) OR (`is_required` = 1 AND `min_selections` >= 1)),
    CONSTRAINT `chk_restaurant_modifier_group_version` CHECK (`version` >= 1),
    CONSTRAINT `chk_restaurant_modifier_group_order` CHECK (`sort_order` <= 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restaurant_modifier` (
    `id_restaurant_modifier` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_modifier_group` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `price_delta_minor` BIGINT NOT NULL DEFAULT 0,
    `currency` CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'EUR',
    `status` ENUM('ACTIVE','INACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `idempotency_key` CHAR(36) NOT NULL,
    `request_fingerprint` CHAR(64) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_modifier`),
    UNIQUE KEY `uq_restaurant_modifier_slug` (`id_restaurant_modifier_group`,`slug`),
    UNIQUE KEY `uq_restaurant_modifier_idempotency` (`id_empresa`,`idempotency_key`),
    UNIQUE KEY `uq_restaurant_modifier_scope`
        (`id_restaurant_modifier`,`id_restaurant_modifier_group`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`),
    KEY `idx_restaurant_modifier_listing`
        (`id_restaurant_modifier_group`,`status`,`sort_order`,`id_restaurant_modifier`),
    CONSTRAINT `fk_restaurant_modifier_group_scope`
        FOREIGN KEY (`id_restaurant_modifier_group`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_modifier_group`
            (`id_restaurant_modifier_group`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_modifier_delta`
        CHECK (`price_delta_minor` BETWEEN -999999999999 AND 999999999999),
    CONSTRAINT `chk_restaurant_modifier_currency` CHECK (`currency` = 'EUR'),
    CONSTRAINT `chk_restaurant_modifier_version` CHECK (`version` >= 1),
    CONSTRAINT `chk_restaurant_modifier_order` CHECK (`sort_order` <= 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restaurant_product_modifier_group` (
    `id_restaurant_product` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_modifier_group` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_product`,`id_restaurant_modifier_group`),
    KEY `idx_restaurant_product_modifier_listing`
        (`id_restaurant_product`,`sort_order`,`id_restaurant_modifier_group`),
    CONSTRAINT `fk_restaurant_product_modifier_product`
        FOREIGN KEY (`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_product`
            (`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `fk_restaurant_product_modifier_group`
        FOREIGN KEY (`id_restaurant_modifier_group`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_modifier_group`
            (`id_restaurant_modifier_group`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_product_modifier_order` CHECK (`sort_order` <= 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restaurant_price` (
    `id_restaurant_price` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_product` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_product_variant` BIGINT UNSIGNED NULL,
    `id_restaurant_location` BIGINT UNSIGNED NULL,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `scope_type` ENUM('BRAND','LOCATION','CHANNEL','LOCATION_CHANNEL') NOT NULL,
    `channel` ENUM('IN_STORE','QR','TAKEAWAY','WEB','DELIVERY') NULL,
    `scope_key` CHAR(64) NOT NULL,
    `amount_minor` BIGINT UNSIGNED NOT NULL,
    `currency` CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'EUR',
    `status` ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `idempotency_key` CHAR(36) NOT NULL,
    `request_fingerprint` CHAR(64) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_price`),
    UNIQUE KEY `uq_restaurant_price_scope_key` (`id_empresa`,`scope_key`),
    UNIQUE KEY `uq_restaurant_price_idempotency` (`id_empresa`,`idempotency_key`),
    UNIQUE KEY `uq_restaurant_price_scope`
        (`id_restaurant_price`,`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`),
    KEY `idx_restaurant_price_resolution`
        (`id_empresa`,`id_restaurant_brand`,`id_restaurant_product`,`status`,`scope_type`,`id_restaurant_location`,`channel`),
    KEY `idx_restaurant_price_variant` (`id_restaurant_product_variant`,`id_restaurant_product`),
    CONSTRAINT `fk_restaurant_price_product_scope`
        FOREIGN KEY (`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_product`
            (`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `fk_restaurant_price_variant_scope`
        FOREIGN KEY (`id_restaurant_product_variant`,`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_product_variant`
            (`id_restaurant_product_variant`,`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `fk_restaurant_price_location_scope`
        FOREIGN KEY (`id_restaurant_location`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_location`
            (`id_restaurant_location`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_price_amount` CHECK (`amount_minor` <= 999999999999),
    CONSTRAINT `chk_restaurant_price_currency` CHECK (`currency` = 'EUR'),
    CONSTRAINT `chk_restaurant_price_dimensions` CHECK (
        (`scope_type` = 'BRAND' AND `id_restaurant_location` IS NULL AND `channel` IS NULL)
        OR (`scope_type` = 'LOCATION' AND `id_restaurant_location` IS NOT NULL AND `channel` IS NULL)
        OR (`scope_type` = 'CHANNEL' AND `id_restaurant_location` IS NULL AND `channel` IS NOT NULL)
        OR (`scope_type` = 'LOCATION_CHANNEL' AND `id_restaurant_location` IS NOT NULL AND `channel` IS NOT NULL)
    ),
    CONSTRAINT `chk_restaurant_price_version` CHECK (`version` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restaurant_price_history` (
    `id_restaurant_price_history` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_price` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_product` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `old_amount_minor` BIGINT UNSIGNED NULL,
    `new_amount_minor` BIGINT UNSIGNED NOT NULL,
    `result_version` INT UNSIGNED NOT NULL,
    `currency` CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `id_actor` INT UNSIGNED NOT NULL,
    `idempotency_key` CHAR(36) NOT NULL,
    `request_fingerprint` CHAR(64) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_price_history`),
    UNIQUE KEY `uq_restaurant_price_history_idempotency` (`id_empresa`,`idempotency_key`),
    KEY `idx_restaurant_price_history_price` (`id_restaurant_price`,`created_at`),
    CONSTRAINT `fk_restaurant_price_history_price`
        FOREIGN KEY (`id_restaurant_price`,`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_price`
            (`id_restaurant_price`,`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `fk_restaurant_price_history_actor`
        FOREIGN KEY (`id_actor`) REFERENCES `usuario` (`id_usuario`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_price_history_amounts`
        CHECK ((`old_amount_minor` IS NULL OR `old_amount_minor` <= 999999999999) AND `new_amount_minor` <= 999999999999),
    CONSTRAINT `chk_restaurant_price_history_currency` CHECK (`currency` = 'EUR')
    ,CONSTRAINT `chk_restaurant_price_history_version` CHECK (`result_version` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restaurant_availability` (
    `id_restaurant_availability` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_product` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_product_variant` BIGINT UNSIGNED NULL,
    `id_restaurant_location` BIGINT UNSIGNED NULL,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `scope_type` ENUM('BRAND','LOCATION','CHANNEL','LOCATION_CHANNEL') NOT NULL,
    `channel` ENUM('IN_STORE','QR','TAKEAWAY','WEB','DELIVERY') NULL,
    `scope_key` CHAR(64) NOT NULL,
    `is_available` TINYINT(1) UNSIGNED NOT NULL,
    `reason` VARCHAR(255) NULL,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `idempotency_key` CHAR(36) NOT NULL,
    `request_fingerprint` CHAR(64) NOT NULL,
    `updated_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_availability`),
    UNIQUE KEY `uq_restaurant_availability_scope_key` (`id_empresa`,`scope_key`),
    UNIQUE KEY `uq_restaurant_availability_idempotency` (`id_empresa`,`idempotency_key`),
    KEY `idx_restaurant_availability_resolution`
        (`id_empresa`,`id_restaurant_brand`,`id_restaurant_product`,`scope_type`,`id_restaurant_location`,`channel`),
    CONSTRAINT `fk_restaurant_availability_product`
        FOREIGN KEY (`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_product`
            (`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `fk_restaurant_availability_variant`
        FOREIGN KEY (`id_restaurant_product_variant`,`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_product_variant`
            (`id_restaurant_product_variant`,`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `fk_restaurant_availability_location`
        FOREIGN KEY (`id_restaurant_location`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_location`
            (`id_restaurant_location`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `fk_restaurant_availability_actor`
        FOREIGN KEY (`updated_by`) REFERENCES `usuario` (`id_usuario`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_availability_value` CHECK (`is_available` IN (0,1)),
    CONSTRAINT `chk_restaurant_availability_dimensions` CHECK (
        (`scope_type` = 'BRAND' AND `id_restaurant_location` IS NULL AND `channel` IS NULL)
        OR (`scope_type` = 'LOCATION' AND `id_restaurant_location` IS NOT NULL AND `channel` IS NULL)
        OR (`scope_type` = 'CHANNEL' AND `id_restaurant_location` IS NULL AND `channel` IS NOT NULL)
        OR (`scope_type` = 'LOCATION_CHANNEL' AND `id_restaurant_location` IS NOT NULL AND `channel` IS NOT NULL)
    ),
    CONSTRAINT `chk_restaurant_availability_version` CHECK (`version` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restaurant_availability_history` (
    `id_restaurant_availability_history` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_availability` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_product` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `old_is_available` TINYINT(1) UNSIGNED NULL,
    `new_is_available` TINYINT(1) UNSIGNED NOT NULL,
    `result_version` INT UNSIGNED NOT NULL,
    `id_actor` INT UNSIGNED NOT NULL,
    `idempotency_key` CHAR(36) NOT NULL,
    `request_fingerprint` CHAR(64) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_availability_history`),
    UNIQUE KEY `uq_restaurant_availability_history_key` (`id_empresa`,`idempotency_key`),
    KEY `idx_restaurant_availability_history_item` (`id_restaurant_availability`,`created_at`),
    CONSTRAINT `fk_restaurant_availability_history_item`
        FOREIGN KEY (`id_restaurant_availability`)
        REFERENCES `restaurant_availability` (`id_restaurant_availability`) ON DELETE RESTRICT,
    CONSTRAINT `fk_restaurant_availability_history_actor`
        FOREIGN KEY (`id_actor`) REFERENCES `usuario` (`id_usuario`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_availability_history_values`
        CHECK ((`old_is_available` IS NULL OR `old_is_available` IN (0,1)) AND `new_is_available` IN (0,1)),
    CONSTRAINT `chk_restaurant_availability_history_version` CHECK (`result_version` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restaurant_product_allergen_declaration` (
    `id_restaurant_allergen_declaration` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_product` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `declaration_code` VARCHAR(40) NOT NULL,
    `label` VARCHAR(120) NOT NULL,
    `statement` VARCHAR(500) NULL,
    `source` VARCHAR(255) NULL,
    `status` ENUM('DECLARED','WITHDRAWN') NOT NULL DEFAULT 'DECLARED',
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `idempotency_key` CHAR(36) NOT NULL,
    `request_fingerprint` CHAR(64) NOT NULL,
    `updated_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_allergen_declaration`),
    UNIQUE KEY `uq_restaurant_allergen_code` (`id_restaurant_product`,`declaration_code`),
    UNIQUE KEY `uq_restaurant_allergen_idempotency` (`id_empresa`,`idempotency_key`),
    KEY `idx_restaurant_allergen_product` (`id_restaurant_product`,`status`,`id_restaurant_allergen_declaration`),
    CONSTRAINT `fk_restaurant_allergen_product`
        FOREIGN KEY (`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_product`
            (`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `fk_restaurant_allergen_actor`
        FOREIGN KEY (`updated_by`) REFERENCES `usuario` (`id_usuario`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_allergen_version` CHECK (`version` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restaurant_product_media` (
    `id_restaurant_product_media` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_product` BIGINT UNSIGNED NOT NULL,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `storage_key` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `mime_type` ENUM('image/jpeg','image/png','image/webp') NOT NULL,
    `byte_size` BIGINT UNSIGNED NOT NULL,
    `sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `alt_text` VARCHAR(180) NOT NULL,
    `source` VARCHAR(255) NULL,
    `license` VARCHAR(120) NULL,
    `status` ENUM('ACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `idempotency_key` CHAR(36) NOT NULL,
    `request_fingerprint` CHAR(64) NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_product_media`),
    UNIQUE KEY `uq_restaurant_product_media_key` (`id_empresa`,`storage_key`),
    UNIQUE KEY `uq_restaurant_product_media_idempotency` (`id_empresa`,`idempotency_key`),
    KEY `idx_restaurant_product_media_listing`
        (`id_restaurant_product`,`status`,`sort_order`,`id_restaurant_product_media`),
    CONSTRAINT `fk_restaurant_product_media_product`
        FOREIGN KEY (`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`)
        REFERENCES `restaurant_product`
            (`id_restaurant_product`,`id_restaurant_account`,`id_empresa`,`id_restaurant_brand`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_product_media_size` CHECK (`byte_size` BETWEEN 1 AND 10485760),
    CONSTRAINT `chk_restaurant_product_media_version` CHECK (`version` >= 1),
    CONSTRAINT `chk_restaurant_product_media_order` CHECK (`sort_order` <= 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restaurant_catalog_mutation` (
    `id_restaurant_catalog_mutation` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_restaurant_account` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_restaurant_brand` BIGINT UNSIGNED NOT NULL,
    `entity_type` ENUM('CATALOG','CATEGORY','PRODUCT','VARIANT','MODIFIER_GROUP','MODIFIER','PRICE','AVAILABILITY','ALLERGEN','MEDIA') NOT NULL,
    `entity_id` BIGINT UNSIGNED NOT NULL,
    `operation` VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `idempotency_key` CHAR(36) NOT NULL,
    `request_fingerprint` CHAR(64) NOT NULL,
    `result_version` INT UNSIGNED NOT NULL,
    `id_actor` INT UNSIGNED NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id_restaurant_catalog_mutation`),
    UNIQUE KEY `uq_restaurant_catalog_mutation_key` (`id_empresa`,`idempotency_key`),
    KEY `idx_restaurant_catalog_mutation_entity`
        (`id_empresa`,`entity_type`,`entity_id`,`created_at`),
    CONSTRAINT `fk_restaurant_catalog_mutation_brand`
        FOREIGN KEY (`id_restaurant_brand`,`id_restaurant_account`,`id_empresa`)
        REFERENCES `restaurant_brand` (`id_restaurant_brand`,`id_restaurant_account`,`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_restaurant_catalog_mutation_actor`
        FOREIGN KEY (`id_actor`) REFERENCES `usuario` (`id_usuario`) ON DELETE RESTRICT,
    CONSTRAINT `chk_restaurant_catalog_mutation_version` CHECK (`result_version` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
