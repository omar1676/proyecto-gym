-- Migración v11: IBAN del socio para los cobros por transferencia.
--
-- El IBAN se guarda en `usuario` y no en cada cobro porque es un dato de la
-- persona: la misma cuenta sirve para la cuota de este mes y la del siguiente.
-- Las contrataciones ya guardan su propio `metodo_pago`, así que se puede saber
-- cuáles se cobraron por transferencia sin duplicar el número de cuenta.
--
-- Se almacena normalizado (sin espacios y en mayúsculas) para que las
-- comparaciones y las búsquedas no dependan de cómo lo teclee cada empleado.
-- 34 caracteres es la longitud máxima de un IBAN según la norma ISO 13616.
--
-- Aplicar a mano desde phpMyAdmin, después de la v10.

ALTER TABLE `usuario`
    ADD COLUMN IF NOT EXISTS `iban` VARCHAR(34) NULL AFTER `telefono`;
