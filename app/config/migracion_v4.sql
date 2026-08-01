USE `portal_de_cursos`;

ALTER TABLE `curso`
    ADD COLUMN IF NOT EXISTS `plazas_maximas` INT NOT NULL DEFAULT 20 AFTER `duracion_horas`;
