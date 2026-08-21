-- Migración v27: invariantes económicos de concurrencia y resultado de auditoría.
-- Forward-only. Si existen duplicados activos, la migración falla de forma
-- explícita para que se saneen antes de declarar el esquema actualizado.

ALTER TABLE `mandato_sepa`
    ADD COLUMN IF NOT EXISTS `idempotency_key` VARCHAR(80) NULL AFTER `primer_cobro_hecho`,
    ADD COLUMN IF NOT EXISTS `socio_activo_unico` INT UNSIGNED
        GENERATED ALWAYS AS (CASE WHEN `estado` = 'activo' THEN `id_socio` ELSE NULL END) STORED,
    ADD UNIQUE KEY IF NOT EXISTS `uq_mandato_idempotencia` (`id_gimnasio`, `idempotency_key`),
    ADD UNIQUE KEY IF NOT EXISTS `uq_mandato_socio_activo` (`socio_activo_unico`);

ALTER TABLE `remesa_recibo`
    ADD COLUMN IF NOT EXISTS `membresia_en_cobro` INT UNSIGNED
        GENERATED ALWAYS AS (
            CASE WHEN `estado` IN ('pendiente', 'cobrado') THEN `id_socio_membresia` ELSE NULL END
        ) STORED,
    ADD UNIQUE KEY IF NOT EXISTS `uq_recibo_membresia_en_cobro` (`membresia_en_cobro`);

ALTER TABLE `log_actividad`
    ADD COLUMN IF NOT EXISTS `resultado` VARCHAR(16) NOT NULL DEFAULT 'exito' AFTER `accion`,
    ADD INDEX IF NOT EXISTS `idx_log_empresa_resultado_fecha` (`id_empresa`, `resultado`, `fecha`);
