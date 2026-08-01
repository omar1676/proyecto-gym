CREATE DATABASE IF NOT EXISTS `portal_de_cursos`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `portal_de_cursos`;

CREATE TABLE IF NOT EXISTS `usuario` (
    `id_usuario`       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nombre`           VARCHAR(100)  NOT NULL,
    `apellidos`        VARCHAR(150)  NOT NULL,
    `dni`              VARCHAR(20)   NOT NULL UNIQUE,
    `telefono`         VARCHAR(20)       NULL,
    `email`            VARCHAR(255)  NOT NULL UNIQUE,
    `fecha_nacimiento` DATE              NULL,
    `pais`             VARCHAR(100)      NULL,
    `provincia`        VARCHAR(100)      NULL,
    `localidad`        VARCHAR(100)      NULL,
    `direccion`        VARCHAR(255)      NULL,
    `codigo_postal`    VARCHAR(10)       NULL,
    `genero`           VARCHAR(30)       NULL,
    `nombre_usuario`   VARCHAR(60)   NOT NULL UNIQUE,
    `foto`             VARCHAR(255)      NULL,
    `contrasena`       VARCHAR(255)  NOT NULL,
    `activo`           TINYINT(1)    NOT NULL DEFAULT 1,
    `rol`              ENUM('admin','profesor','usuario') NOT NULL DEFAULT 'usuario',
    `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `categoria` (
    `id_categoria`     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nombre_categoria` VARCHAR(100)  NOT NULL,
    PRIMARY KEY (`id_categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `curso` (
    `id_curso`       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `titulo`         VARCHAR(150)  NOT NULL,
    `descripcion`    TEXT              NULL,
    `precio`         DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    `duracion_horas` INT UNSIGNED  NOT NULL DEFAULT 0,
    `nivel`          VARCHAR(50)       NULL,
    `estado`         ENUM('activo','inactivo','borrador') NOT NULL DEFAULT 'activo',
    `id_categoria`   INT UNSIGNED      NULL,
    `id_profesor`    INT UNSIGNED      NULL,
    `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_curso`),
    FOREIGN KEY (`id_categoria`) REFERENCES `categoria`(`id_categoria`) ON DELETE SET NULL,
    FOREIGN KEY (`id_profesor`)  REFERENCES `usuario`(`id_usuario`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `personas` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_usuario` INT UNSIGNED      NULL,
    `id_curso`   INT UNSIGNED      NULL,
    `nombre`     VARCHAR(100)  NOT NULL,
    `apellidos`  VARCHAR(100)  NOT NULL DEFAULT '',
    `telefono`   VARCHAR(20)       NULL,
    `email`      VARCHAR(100)      NULL,
    `estado`     ENUM('pendiente','inscrito','rechazado','espera') NOT NULL DEFAULT 'pendiente',
    `fecha`      DATE              NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS `personas_fecha_insert`;
CREATE TRIGGER `personas_fecha_insert`
BEFORE INSERT ON `personas`
FOR EACH ROW SET NEW.fecha = IFNULL(NEW.fecha, CURDATE());

CREATE TABLE IF NOT EXISTS `visitas` (
    `id`     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `pagina` VARCHAR(100)  NOT NULL DEFAULT 'curso',
    `fecha`  DATE              NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS `visitas_fecha_insert`;
CREATE TRIGGER `visitas_fecha_insert`
BEFORE INSERT ON `visitas`
FOR EACH ROW SET NEW.fecha = IFNULL(NEW.fecha, CURDATE());
