-- Fase 5: registro reproducible de migraciones y versión de esquema.
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration VARCHAR(100) NOT NULL PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    release_version VARCHAR(50) NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
