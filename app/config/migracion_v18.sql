-- Migración v18: cambiar la contraseña cierra las demás sesiones.
--
-- Hasta ahora, cambiar la contraseña no echaba a nadie. Si alguien te dejaba la
-- sesión abierta en otro ordenador —o te habían entrado en la cuenta—, cambiar
-- la clave no servía de nada: la sesión antigua seguía viva hasta que caducaba
-- por inactividad.
--
-- Con esta marca, cualquier sesión abierta ANTES de la fecha guardada deja de
-- valer en la siguiente petición. La sesión desde la que se cambia la clave
-- sigue funcionando, que es lo que espera quien lo hace.
--
-- Aplicar a mano desde phpMyAdmin, después de la v17.

ALTER TABLE `usuario`
    ADD COLUMN `sesiones_desde` DATETIME NULL
    COMMENT 'Las sesiones abiertas antes de esta fecha dejan de ser válidas';
