-- Migración v28: contexto uniforme y correlacionable de auditoría.
-- Aditiva y forward-only; los eventos históricos conservan NULL cuando el
-- dato no existía. Los eventos nuevos se completan desde LogModel.

ALTER TABLE `log_actividad`
    ADD COLUMN IF NOT EXISTS `event_id` CHAR(36) NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `correlation_id` CHAR(36) NULL AFTER `event_id`,
    ADD COLUMN IF NOT EXISTS `actor_type` VARCHAR(16) NOT NULL DEFAULT 'usuario' AFTER `id_usuario`,
    ADD COLUMN IF NOT EXISTS `origin` VARCHAR(16) NOT NULL DEFAULT 'SYSTEM' AFTER `resultado`,
    ADD COLUMN IF NOT EXISTS `reason_code` VARCHAR(64) NULL AFTER `origin`,
    ADD COLUMN IF NOT EXISTS `metadata_json` LONGTEXT NULL AFTER `valor_nuevo`,
    ADD UNIQUE KEY IF NOT EXISTS `uq_log_event_id` (`event_id`),
    ADD INDEX IF NOT EXISTS `idx_log_correlation` (`correlation_id`),
    ADD INDEX IF NOT EXISTS `idx_log_empresa_origen_fecha` (`id_empresa`, `origin`, `fecha`);
