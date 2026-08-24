-- Fase 24.1: proyección diaria de Retention para consultas UX paginadas.
-- No recalcula reglas: persiste exactamente el resultado producido por
-- RetentionPolicy durante cada retention_run.

CREATE TABLE `retention_member_snapshot` (
    `id_retention_member_snapshot` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_retention_run` BIGINT UNSIGNED NOT NULL,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `evaluation_date` DATE NOT NULL,
    `state` ENUM('INSUFFICIENT_DATA','NORMAL','ATTENTION','HIGH_ATTENTION') NOT NULL,
    `activity_family` ENUM('GYM','BOXEO','TATAMI','GENERAL') NOT NULL DEFAULT 'GENERAL',
    `baseline_visits` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `recent_visits` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `baseline_weekly_rate` DECIMAL(7,2) NOT NULL DEFAULT 0.00,
    `recent_weekly_rate` DECIMAL(7,2) NOT NULL DEFAULT 0.00,
    `drop_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `reason_code` VARCHAR(64) NOT NULL,
    `last_attendance_utc` DATETIME NULL,
    `created_at_utc` DATETIME NOT NULL,
    PRIMARY KEY (`id_retention_member_snapshot`),
    UNIQUE KEY `uq_retention_snapshot_run_member` (`id_retention_run`, `id_socio`),
    UNIQUE KEY `uq_retention_snapshot_scope` (`id_retention_member_snapshot`, `id_empresa`, `id_gimnasio`, `id_socio`),
    KEY `idx_retention_snapshot_dashboard` (`id_empresa`, `id_gimnasio`, `id_retention_run`, `state`, `activity_family`),
    KEY `idx_retention_snapshot_member` (`id_empresa`, `id_socio`, `evaluation_date`),
    CONSTRAINT `fk_retention_snapshot_run`
        FOREIGN KEY (`id_retention_run`) REFERENCES `retention_run` (`id_retention_run`) ON DELETE RESTRICT,
    CONSTRAINT `fk_retention_snapshot_company`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_retention_snapshot_site_scope`
        FOREIGN KEY (`id_gimnasio`, `id_empresa`)
        REFERENCES `gimnasio` (`id_gimnasio`, `id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_retention_snapshot_member_scope`
        FOREIGN KEY (`id_socio`, `id_empresa`)
        REFERENCES `usuario` (`id_usuario`, `id_empresa`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance_daily_visit` (
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `local_date` DATE NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `occurred_at_utc` DATETIME NOT NULL,
    `activity_family` ENUM('GYM','BOXEO','TATAMI','GENERAL') NOT NULL DEFAULT 'GENERAL',
    `event_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id_empresa`, `id_socio`, `local_date`),
    KEY `idx_attendance_daily_recent` (`id_empresa`, `occurred_at_utc`, `id_socio`),
    KEY `idx_attendance_daily_scope` (`id_empresa`, `id_gimnasio`, `occurred_at_utc`),
    CONSTRAINT `fk_attendance_daily_company`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE CASCADE,
    CONSTRAINT `fk_attendance_daily_site_scope`
        FOREIGN KEY (`id_gimnasio`, `id_empresa`)
        REFERENCES `gimnasio` (`id_gimnasio`, `id_empresa`) ON DELETE CASCADE,
    CONSTRAINT `fk_attendance_daily_member_scope`
        FOREIGN KEY (`id_socio`, `id_empresa`)
        REFERENCES `usuario` (`id_usuario`, `id_empresa`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `attendance_daily_visit`
    (`id_empresa`,`id_socio`,`local_date`,`id_gimnasio`,`occurred_at_utc`,`activity_family`,`event_count`)
SELECT a.`id_empresa`,a.`id_socio`,a.`local_date`,
       CAST(SUBSTRING_INDEX(GROUP_CONCAT(a.`id_gimnasio` ORDER BY a.`occurred_at_utc` DESC,a.`id_attendance_event` DESC),',',1) AS UNSIGNED),
       MAX(a.`occurred_at_utc`),
       CASE WHEN COUNT(*)=COUNT(a.`activity_family`) AND COUNT(DISTINCT a.`activity_family`)=1
            THEN MAX(a.`activity_family`) ELSE 'GENERAL' END,
       LEAST(COUNT(*),65535)
  FROM `attendance_event` a
 GROUP BY a.`id_empresa`,a.`id_socio`,a.`local_date`;

CREATE TRIGGER `trg_attendance_daily_after_insert`
AFTER INSERT ON `attendance_event`
FOR EACH ROW
INSERT INTO `attendance_daily_visit`
    (`id_empresa`,`id_socio`,`local_date`,`id_gimnasio`,`occurred_at_utc`,`activity_family`,`event_count`)
VALUES
    (NEW.`id_empresa`,NEW.`id_socio`,NEW.`local_date`,NEW.`id_gimnasio`,NEW.`occurred_at_utc`,COALESCE(NEW.`activity_family`,'GENERAL'),1)
ON DUPLICATE KEY UPDATE
    `id_gimnasio`=IF(NEW.`occurred_at_utc`>`occurred_at_utc`,NEW.`id_gimnasio`,`id_gimnasio`),
    `occurred_at_utc`=GREATEST(`occurred_at_utc`,NEW.`occurred_at_utc`),
    `activity_family`=IF(`activity_family`='GENERAL' OR NEW.`activity_family` IS NULL OR `activity_family`<>NEW.`activity_family`,'GENERAL',`activity_family`),
    `event_count`=LEAST(`event_count`+1,65535);

ALTER TABLE `attendance_event`
    ADD KEY `idx_attendance_recent_daily` (`id_empresa`, `local_date`, `occurred_at_utc`, `id_socio`);
