-- Migración v8: varios gimnasios (multi-sede) y auditoría con trazabilidad.
--
-- PARTE 1 — Multi-sede
--   Cada usuario, producto, venta y membresía pertenece a un gimnasio.
--   Un empleado trabaja en una sola sede; el sistema deduce cuál al iniciar
--   sesión, sin selector en el login.
--   El rol `propietario` está por encima del admin: no pertenece a ninguna sede
--   (id_gimnasio NULL) y ve los datos de todas.
--
--   Los catálogos compartidos (tipos de cuota y suplementos) admiten
--   id_gimnasio NULL = "disponible en todo el grupo". Así los precios son
--   comunes por defecto y se pueden particularizar por sede si hace falta.
--
-- PARTE 2 — Auditoría
--   `log_actividad` pasa de guardar solo texto libre a registrar también sobre
--   QUIÉN se actuó y qué valor cambió: "Dani cambió el vencimiento de Omar
--   del 30/08 al 30/09" deja de depender de cómo se redactó el detalle.
--
-- Todos los datos existentes se asignan al gimnasio creado en el paso 1.3,
-- así que nada queda huérfano.
--
-- Aplicar a mano desde phpMyAdmin, después de la v7. HAZ COPIA ANTES.


-- ===========================================================================
-- PARTE 1 — MULTI-SEDE
-- ===========================================================================

-- 1.1 Tabla de gimnasios
CREATE TABLE IF NOT EXISTS `gimnasio` (
    `id_gimnasio` INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nombre`      VARCHAR(120)  NOT NULL,
    `direccion`   VARCHAR(255)      NULL,
    `telefono`    VARCHAR(20)       NULL,
    `email`       VARCHAR(255)      NULL,
    `activo`      TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_gimnasio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1.2 Rol propietario
--     Se amplía el ENUM primero; no se retira ningún valor, así que no hay
--     riesgo de dejar filas inválidas.
ALTER TABLE `usuario`
    MODIFY `rol` ENUM('propietario','admin','recepcion','socio')
    NOT NULL DEFAULT 'socio';

-- 1.3 Sede por defecto para todo lo que ya existe
INSERT INTO `gimnasio` (`nombre`)
SELECT * FROM (SELECT 'Gimnasio principal') AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `gimnasio`);

-- 1.4 Columna de sede en cada tabla con datos propios de un gimnasio
ALTER TABLE `usuario`
    ADD COLUMN IF NOT EXISTS `id_gimnasio` INT UNSIGNED NULL AFTER `rol`;

ALTER TABLE `producto`
    ADD COLUMN IF NOT EXISTS `id_gimnasio` INT UNSIGNED NULL AFTER `id_categoria`;

ALTER TABLE `venta`
    ADD COLUMN IF NOT EXISTS `id_gimnasio` INT UNSIGNED NULL AFTER `id_usuario_registro`;

ALTER TABLE `socio_membresia`
    ADD COLUMN IF NOT EXISTS `id_gimnasio` INT UNSIGNED NULL AFTER `id_socio`;

-- NULL en estas dos = catálogo común a todas las sedes.
ALTER TABLE `tipo_membresia`
    ADD COLUMN IF NOT EXISTS `id_gimnasio` INT UNSIGNED NULL AFTER `id_tipo_membresia`;

ALTER TABLE `suplemento`
    ADD COLUMN IF NOT EXISTS `id_gimnasio` INT UNSIGNED NULL AFTER `id_suplemento`;

-- 1.5 Asignar los datos existentes a la sede por defecto
SET @sede := (SELECT MIN(`id_gimnasio`) FROM `gimnasio`);

UPDATE `usuario`         SET `id_gimnasio` = @sede WHERE `id_gimnasio` IS NULL AND `rol` <> 'propietario';
UPDATE `producto`        SET `id_gimnasio` = @sede WHERE `id_gimnasio` IS NULL;
UPDATE `venta`           SET `id_gimnasio` = @sede WHERE `id_gimnasio` IS NULL;
UPDATE `socio_membresia` SET `id_gimnasio` = @sede WHERE `id_gimnasio` IS NULL;

-- 1.6 Índices y claves foráneas
--     Van después de rellenar los datos para que las FK no fallen.
ALTER TABLE `usuario`         ADD INDEX IF NOT EXISTS `idx_usuario_gimnasio`  (`id_gimnasio`);
ALTER TABLE `producto`        ADD INDEX IF NOT EXISTS `idx_producto_gimnasio` (`id_gimnasio`);
ALTER TABLE `venta`           ADD INDEX IF NOT EXISTS `idx_venta_gimnasio`    (`id_gimnasio`);
ALTER TABLE `socio_membresia` ADD INDEX IF NOT EXISTS `idx_sm_gimnasio`       (`id_gimnasio`);


-- ===========================================================================
-- PARTE 2 — AUDITORÍA
-- ===========================================================================

-- Sobre quién se actuó, qué cambió y en qué sede.
-- `valor_anterior` y `valor_nuevo` son texto libre a propósito: sirven igual
-- para una fecha, un precio o un rol, y el log solo se lee, nunca se opera.
ALTER TABLE `log_actividad`
    ADD COLUMN IF NOT EXISTS `id_usuario_afectado` INT UNSIGNED NULL AFTER `id_usuario`,
    ADD COLUMN IF NOT EXISTS `entidad`             VARCHAR(40)  NULL AFTER `accion`,
    ADD COLUMN IF NOT EXISTS `id_entidad`          INT UNSIGNED NULL AFTER `entidad`,
    ADD COLUMN IF NOT EXISTS `valor_anterior`      VARCHAR(255) NULL AFTER `detalle`,
    ADD COLUMN IF NOT EXISTS `valor_nuevo`         VARCHAR(255) NULL AFTER `valor_anterior`,
    ADD COLUMN IF NOT EXISTS `ip`                  VARCHAR(45)  NULL AFTER `valor_nuevo`,
    ADD COLUMN IF NOT EXISTS `id_gimnasio`         INT UNSIGNED NULL AFTER `ip`;

UPDATE `log_actividad` SET `id_gimnasio` = @sede WHERE `id_gimnasio` IS NULL;

ALTER TABLE `log_actividad`
    ADD INDEX IF NOT EXISTS `idx_log_afectado` (`id_usuario_afectado`),
    ADD INDEX IF NOT EXISTS `idx_log_gimnasio` (`id_gimnasio`),
    ADD INDEX IF NOT EXISTS `idx_log_entidad`  (`entidad`, `id_entidad`);
