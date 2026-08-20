-- Fase 8: lotes de importación, informe por fila y mapa de IDs externos.
-- Los datos de negocio nunca toman empresa/sede desde el archivo importado.

CREATE TABLE IF NOT EXISTS migration_batch (
    id_batch BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    id_empresa INT UNSIGNED NOT NULL,
    id_gimnasio INT UNSIGNED NULL,
    id_usuario INT UNSIGNED NULL,
    source_system VARCHAR(64) NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_key VARCHAR(80) NULL,
    file_hash CHAR(64) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    attempt_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM(
        'uploaded','dry_run_ready','importing','partial',
        'completed','completed_with_warnings','failed','expired'
    ) NOT NULL DEFAULT 'uploaded',
    mode ENUM('dry_run','import') NOT NULL DEFAULT 'dry_run',
    delimiter CHAR(1) NOT NULL DEFAULT ',',
    headers_json LONGTEXT NOT NULL,
    mapping_json LONGTEXT NULL,
    options_json LONGTEXT NULL,
    row_count INT UNSIGNED NOT NULL DEFAULT 0,
    valid_count INT UNSIGNED NOT NULL DEFAULT 0,
    warning_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    imported_count INT UNSIGNED NOT NULL DEFAULT 0,
    linked_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_committed_row INT UNSIGNED NOT NULL DEFAULT 0,
    backup_reference VARCHAR(255) NULL,
    backup_verified_at DATETIME NULL,
    failure_code VARCHAR(80) NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_batch),
    UNIQUE KEY uq_migration_batch_uuid (uuid),
    UNIQUE KEY uq_migration_batch_attempt (
        id_empresa, source_system, entity_type, file_hash, attempt_no
    ),
    KEY idx_migration_batch_tenant (id_empresa, id_gimnasio, created_at),
    KEY idx_migration_batch_expiry (status, expires_at),
    CONSTRAINT fk_migration_batch_empresa
        FOREIGN KEY (id_empresa) REFERENCES empresa(id_empresa) ON DELETE RESTRICT,
    CONSTRAINT fk_migration_batch_gimnasio
        FOREIGN KEY (id_gimnasio) REFERENCES gimnasio(id_gimnasio) ON DELETE SET NULL,
    CONSTRAINT fk_migration_batch_usuario
        FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS migration_batch_issue (
    id_issue BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_batch BIGINT UNSIGNED NOT NULL,
    row_number INT UNSIGNED NOT NULL,
    severity ENUM('ERROR','WARNING') NOT NULL,
    field_name VARCHAR(64) NULL,
    problem_code VARCHAR(80) NOT NULL,
    message VARCHAR(500) NOT NULL,
    value_excerpt VARCHAR(255) NULL,
    proposed_action VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_issue),
    KEY idx_migration_issue_batch_row (id_batch, row_number),
    CONSTRAINT fk_migration_issue_batch
        FOREIGN KEY (id_batch) REFERENCES migration_batch(id_batch) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS migration_batch_row (
    id_row BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_batch BIGINT UNSIGNED NOT NULL,
    row_number INT UNSIGNED NOT NULL,
    row_hash CHAR(64) NOT NULL,
    external_id VARCHAR(190) NULL,
    classification ENUM(
        'NEW','SAFE_MATCH','POSSIBLE_DUPLICATE','CONFLICT','INVALID'
    ) NOT NULL,
    status ENUM('ready','link','review','rejected','imported','linked') NOT NULL,
    normalized_json LONGTEXT NOT NULL,
    internal_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_row),
    UNIQUE KEY uq_migration_row_number (id_batch, row_number),
    KEY idx_migration_row_pending (id_batch, status, row_number),
    KEY idx_migration_row_external (id_batch, external_id),
    CONSTRAINT fk_migration_row_batch
        FOREIGN KEY (id_batch) REFERENCES migration_batch(id_batch) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS migration_entity_map (
    id_map BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_empresa INT UNSIGNED NOT NULL,
    id_gimnasio INT UNSIGNED NULL,
    source_system VARCHAR(64) NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    external_id VARCHAR(190) NOT NULL,
    internal_id BIGINT UNSIGNED NOT NULL,
    id_batch BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_map),
    UNIQUE KEY uq_migration_external (
        id_empresa, source_system, entity_type, external_id
    ),
    KEY idx_migration_internal (id_empresa, entity_type, internal_id),
    KEY idx_migration_map_batch (id_batch),
    CONSTRAINT fk_migration_map_empresa
        FOREIGN KEY (id_empresa) REFERENCES empresa(id_empresa) ON DELETE RESTRICT,
    CONSTRAINT fk_migration_map_gimnasio
        FOREIGN KEY (id_gimnasio) REFERENCES gimnasio(id_gimnasio) ON DELETE SET NULL,
    CONSTRAINT fk_migration_map_batch
        FOREIGN KEY (id_batch) REFERENCES migration_batch(id_batch) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
