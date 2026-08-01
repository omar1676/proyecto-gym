USE `portal_de_cursos`;

ALTER TABLE `usuario`
    ADD COLUMN IF NOT EXISTS `rol`
    ENUM('admin','profesor','usuario') NOT NULL DEFAULT 'usuario';

ALTER TABLE `curso`
    ADD COLUMN IF NOT EXISTS `estado`
    ENUM('activo','inactivo','borrador') NOT NULL DEFAULT 'activo';

ALTER TABLE `personas`
    ADD COLUMN IF NOT EXISTS `id_usuario` INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS `id_curso`   INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS `intentos_login` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `ip_address`    VARCHAR(45)   NOT NULL,
    `usuario`       VARCHAR(100)      NULL,
    `fecha_intento` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_ip` (`ip_address`, `fecha_intento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `usuario` ADD COLUMN IF NOT EXISTS `foto` VARCHAR(255) NULL;

ALTER TABLE `usuario` ADD COLUMN IF NOT EXISTS `activo` TINYINT(1) NOT NULL DEFAULT 1;
