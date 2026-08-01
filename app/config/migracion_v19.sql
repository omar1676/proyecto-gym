-- Migración v19: bajas de socios (derecho al olvido del RGPD).
--
-- Un socio puede pedir que borres sus datos. La forma de cumplirlo SIN romper la
-- contabilidad es anonimizar, no borrar la fila: los datos personales se limpian
-- (nombre, DNI, email, teléfono, IBAN, foto) pero la ficha se queda como
-- "Cliente dado de baja", así las ventas y cuotas siguen existiendo sin nombre.
-- Borrar la fila entera arrastraría en cascada sus membresías, que Hacienda
-- exige conservar.
--
-- El circuito tiene dos pasos a propósito: primero un admin MARCA al socio para
-- baja (reversible), y después admin o empresa CONFIRMA el borrado (definitivo).
-- Así una baja no se ejecuta de un solo clic accidental.
--
-- Aplicar a mano desde phpMyAdmin, después de la v18.

ALTER TABLE `usuario`
    ADD COLUMN `baja_pendiente`      TINYINT(1)   NOT NULL DEFAULT 0
        COMMENT 'Marcado por un admin para borrar sus datos',
    ADD COLUMN `baja_marcada_en`     DATETIME     NULL,
    ADD COLUMN `baja_marcada_por`    INT UNSIGNED NULL
        COMMENT 'Quién lo marcó, para que la lista diga de dónde viene',
    ADD COLUMN `anonimizado_en`      DATETIME     NULL
        COMMENT 'Fecha en que se borraron sus datos personales';
