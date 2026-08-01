-- Migración v10: periodo de prueba con acceso pendiente de pago.
--
-- Recepción puede abrir el acceso a alguien que aún no ha pagado, durante un
-- número limitado de días (5 por defecto). Si en ese plazo nadie confirma el
-- pago, el acceso se cierra por sí solo.
--
-- No hace falta ninguna tarea programada que vaya cerrando pruebas: el acceso
-- se decide comparando `fecha_fin` con la fecha de hoy, así que al llegar el
-- día la prueba deja de estar vigente automáticamente. Si más adelante se
-- conecta el torno (Dorlet), basta con enviarle esa misma `fecha_fin`.
--
-- `estado_pago` se aplica a TODAS las contrataciones, no solo a las pruebas:
-- así también se puede dejar constancia de una cuota normal pendiente de cobro.
--
-- Aplicar a mano desde phpMyAdmin, después de la v9.

ALTER TABLE `socio_membresia`
    ADD COLUMN IF NOT EXISTS `es_prueba`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `nombre_suplemento`,
    ADD COLUMN IF NOT EXISTS `estado_pago` ENUM('pendiente','pagado') NOT NULL DEFAULT 'pagado' AFTER `es_prueba`;

-- Todo lo contratado hasta ahora se dio por cobrado.
UPDATE `socio_membresia` SET `estado_pago` = 'pagado' WHERE `estado_pago` IS NULL;

-- El listado de socios filtra por estas dos columnas en cada carga del panel.
ALTER TABLE `socio_membresia`
    ADD INDEX IF NOT EXISTS `idx_sm_prueba` (`es_prueba`, `estado_pago`);
