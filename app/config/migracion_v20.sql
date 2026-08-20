-- Migración v20: empresa cliente por encima de las sedes (SaaS multi-tenant).
-- No borra ni renumera datos. Todo lo existente se asigna a una empresa inicial.

CREATE TABLE IF NOT EXISTS `empresa` (
    `id_empresa`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nombre`           VARCHAR(150) NOT NULL,
    `nombre_comercial` VARCHAR(150) NULL,
    `email`            VARCHAR(255) NULL,
    `telefono`         VARCHAR(30)  NULL,
    `logo`             VARCHAR(255) NULL,
    `color_primario`   VARCHAR(7)   NOT NULL DEFAULT '#4f46e5',
    `color_texto`      VARCHAR(7)   NOT NULL DEFAULT '#ffffff',
    `configuracion`    JSON         NULL,
    `plan`             VARCHAR(50)  NULL,
    `modulos`          JSON         NULL,
    `estado`           ENUM('activa','inactiva') NOT NULL DEFAULT 'activa',
    `fecha_alta`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_empresa`),
    UNIQUE KEY `uq_empresa_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `empresa` (`nombre`, `nombre_comercial`, `email`, `telefono`)
SELECT 'Centro Deportivo Cleto Reyes', 'Cleto Reyes',
       (SELECT `email` FROM `gimnasio` ORDER BY `id_gimnasio` LIMIT 1),
       (SELECT `telefono` FROM `gimnasio` ORDER BY `id_gimnasio` LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM `empresa`);

SET @empresa_inicial := (SELECT MIN(`id_empresa`) FROM `empresa`);

ALTER TABLE `gimnasio`
    ADD COLUMN IF NOT EXISTS `id_empresa` INT UNSIGNED NULL AFTER `id_gimnasio`;
UPDATE `gimnasio` SET `id_empresa` = @empresa_inicial WHERE `id_empresa` IS NULL;
ALTER TABLE `gimnasio`
    MODIFY `id_empresa` INT UNSIGNED NOT NULL,
    ADD INDEX `idx_gimnasio_empresa` (`id_empresa`),
    ADD CONSTRAINT `fk_gimnasio_empresa`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT;

-- El antiguo rol empresa era el operador global de la plataforma.
ALTER TABLE `usuario`
    MODIFY `rol` ENUM('empresa','superadmin','direccion','admin','recepcion','socio')
    NOT NULL DEFAULT 'socio';
UPDATE `usuario` SET `rol` = 'superadmin' WHERE `rol` = 'empresa';
ALTER TABLE `usuario`
    MODIFY `rol` ENUM('superadmin','direccion','admin','recepcion','socio')
    NOT NULL DEFAULT 'socio',
    ADD COLUMN IF NOT EXISTS `id_empresa` INT UNSIGNED NULL AFTER `rol`;

UPDATE `usuario` u
LEFT JOIN `gimnasio` g ON g.`id_gimnasio` = u.`id_gimnasio`
SET u.`id_empresa` = g.`id_empresa`
WHERE u.`rol` <> 'superadmin' AND u.`id_empresa` IS NULL;
ALTER TABLE `usuario`
    ADD INDEX `idx_usuario_empresa` (`id_empresa`),
    ADD CONSTRAINT `fk_usuario_empresa`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT;

-- Catálogos compartidos: NULL de sede significa común dentro de SU empresa.
ALTER TABLE `tipo_membresia`
    ADD COLUMN IF NOT EXISTS `id_empresa` INT UNSIGNED NULL AFTER `id_tipo_membresia`;
ALTER TABLE `suplemento`
    ADD COLUMN IF NOT EXISTS `id_empresa` INT UNSIGNED NULL AFTER `id_suplemento`;

UPDATE `tipo_membresia` t
LEFT JOIN `gimnasio` g ON g.`id_gimnasio` = t.`id_gimnasio`
SET t.`id_empresa` = COALESCE(g.`id_empresa`, @empresa_inicial)
WHERE t.`id_empresa` IS NULL;
UPDATE `suplemento` s
LEFT JOIN `gimnasio` g ON g.`id_gimnasio` = s.`id_gimnasio`
SET s.`id_empresa` = COALESCE(g.`id_empresa`, @empresa_inicial)
WHERE s.`id_empresa` IS NULL;

ALTER TABLE `tipo_membresia`
    MODIFY `id_empresa` INT UNSIGNED NOT NULL,
    ADD INDEX `idx_tipo_empresa` (`id_empresa`),
    ADD CONSTRAINT `fk_tipo_empresa`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT;
ALTER TABLE `suplemento`
    MODIFY `id_empresa` INT UNSIGNED NOT NULL,
    ADD INDEX `idx_suplemento_empresa` (`id_empresa`),
    ADD CONSTRAINT `fk_suplemento_empresa`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT;

-- El log conserva sede y añade empresa para acciones globales de dirección.
ALTER TABLE `log_actividad`
    ADD COLUMN IF NOT EXISTS `id_empresa` INT UNSIGNED NULL AFTER `id_gimnasio`;
UPDATE `log_actividad` l
LEFT JOIN `gimnasio` g ON g.`id_gimnasio` = l.`id_gimnasio`
SET l.`id_empresa` = COALESCE(g.`id_empresa`, @empresa_inicial)
WHERE l.`id_empresa` IS NULL;
ALTER TABLE `log_actividad`
    ADD INDEX `idx_log_empresa` (`id_empresa`),
    ADD CONSTRAINT `fk_log_empresa`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT;
