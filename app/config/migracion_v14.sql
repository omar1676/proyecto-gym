-- Migración v14: credenciales propias de cada gimnasio.
--
-- El acceso queda en dos niveles:
--   1. El GIMNASIO se identifica con su email y contraseña
--      (p. ej. cleto.reyes.villaviciosa@gmail.com). Esto abre la puerta del
--      local y decide qué marca se muestra.
--   2. Dentro, cada EMPLEADO entra con su usuario corto (daniel, kevin, pedro).
--
-- Ventaja frente a listar los gimnasios en la pantalla de acceso: desde fuera
-- no se puede saber qué gimnasios existen ni quién trabaja en ellos. Y como la
-- primera barrera ya protege, las claves de empleado pueden ser cortas y
-- cómodas de teclear en el mostrador.
--
-- `contrasena_acceso` guarda un hash bcrypt, nunca la contraseña en claro.
--
-- Aplicar a mano desde phpMyAdmin, después de la v13.

ALTER TABLE `gimnasio`
    ADD COLUMN IF NOT EXISTS `email_acceso`      VARCHAR(255) NULL AFTER `slug`,
    ADD COLUMN IF NOT EXISTS `contrasena_acceso` VARCHAR(255) NULL AFTER `email_acceso`;

-- El email identifica al gimnasio en el acceso, así que no puede repetirse.
ALTER TABLE `gimnasio`
    ADD UNIQUE KEY IF NOT EXISTS `uq_gimnasio_email_acceso` (`email_acceso`);

-- Registro de intentos fallidos contra el acceso de gimnasio, para poder
-- bloquear por fuerza bruta igual que se hace con los empleados.
CREATE TABLE IF NOT EXISTS `intentos_gimnasio` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address`    VARCHAR(45)  NOT NULL,
    `email`         VARCHAR(255)     NULL,
    `fecha_intento` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_intento_gim` (`ip_address`, `fecha_intento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
