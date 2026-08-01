USE `portal_de_cursos`;

CREATE TABLE IF NOT EXISTS `log_actividad` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_usuario` INT UNSIGNED      NULL,
    `accion`     VARCHAR(100)  NOT NULL,
    `detalle`    VARCHAR(500)      NULL,
    `fecha`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_fecha` (`fecha`),
    INDEX `idx_id_usuario` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `verificacion_email` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_usuario` INT UNSIGNED  NOT NULL,
    `token`      VARCHAR(64)   NOT NULL,
    `expira`     DATETIME      NOT NULL,
    `usado`      TINYINT(1)    NOT NULL DEFAULT 0,
    `creado`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_token` (`token`),
    INDEX `idx_id_usuario` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `usuario`
    ADD COLUMN IF NOT EXISTS `email_verificado` TINYINT(1) NOT NULL DEFAULT 0;
