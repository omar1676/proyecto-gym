-- Migración v12: domiciliación SEPA (adeudos directos CORE).
--
-- Permite cobrar las cuotas en la cuenta del socio: se firma un mandato, se
-- agrupan los cobros en una remesa y se genera un fichero XML (norma
-- pain.008.001.02) que se sube a la banca electrónica del gimnasio.
--
-- Tres piezas:
--   1. Datos del acreedor en `gimnasio` — quién cobra: razón social, IBAN, BIC
--      e identificador de acreedor SEPA. Sin ellos el banco rechaza la remesa.
--   2. `mandato_sepa` — la autorización firmada por cada socio. Es obligatoria:
--      sin mandato, el socio puede devolver el recibo hasta 13 meses después
--      en lugar de 8 semanas.
--   3. `remesa` + `remesa_recibo` — cada envío al banco y sus cobros, con el
--      estado de cada uno para poder registrar las devoluciones.
--
-- El IBAN y el importe se congelan en el recibo: si el socio cambia de cuenta
-- o sube la cuota, las remesas ya enviadas siguen reflejando lo que se cobró.
--
-- Aplicar a mano desde phpMyAdmin, después de la v11.


-- ---------------------------------------------------------------------------
-- 1. Datos del acreedor (el gimnasio que cobra)
-- ---------------------------------------------------------------------------

ALTER TABLE `gimnasio`
    ADD COLUMN IF NOT EXISTS `razon_social`             VARCHAR(150) NULL AFTER `nombre`,
    ADD COLUMN IF NOT EXISTS `cif`                      VARCHAR(20)  NULL AFTER `razon_social`,
    ADD COLUMN IF NOT EXISTS `iban`                     VARCHAR(34)  NULL AFTER `email`,
    ADD COLUMN IF NOT EXISTS `bic`                      VARCHAR(11)  NULL AFTER `iban`,
    -- Identificador de acreedor SEPA que asigna el banco (en España, ES__ZZZ + CIF).
    ADD COLUMN IF NOT EXISTS `identificador_acreedor`   VARCHAR(35)  NULL AFTER `bic`;


-- ---------------------------------------------------------------------------
-- 2. Mandatos
--
-- `referencia` es la que viaja al banco y debe ser única y estable en el tiempo.
-- `iban` se copia aquí porque el mandato autoriza una cuenta concreta: si el
-- socio cambia de banco hay que firmar un mandato nuevo, no editar el viejo.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `mandato_sepa` (
    `id_mandato`   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_socio`     INT UNSIGNED  NOT NULL,
    `id_gimnasio`  INT UNSIGNED      NULL,
    `referencia`   VARCHAR(35)   NOT NULL,
    `iban`         VARCHAR(34)   NOT NULL,
    `fecha_firma`  DATE          NOT NULL,
    `tipo`         ENUM('recurrente','unico') NOT NULL DEFAULT 'recurrente',
    `estado`       ENUM('activo','revocado')  NOT NULL DEFAULT 'activo',
    -- Se pone a 1 tras el primer adeudo cobrado: marca si toca enviarlo como
    -- FRST (primero) o RCUR (recurrente) en el fichero XML.
    `primer_cobro_hecho` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_mandato`),
    UNIQUE KEY `uq_mandato_referencia` (`referencia`),
    INDEX `idx_mandato_socio` (`id_socio`, `estado`),
    FOREIGN KEY (`id_socio`) REFERENCES `usuario`(`id_usuario`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 3. Remesas y recibos
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `remesa` (
    `id_remesa`      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_gimnasio`    INT UNSIGNED      NULL,
    `concepto`       VARCHAR(140)  NOT NULL,
    `fecha_cobro`    DATE          NOT NULL,
    `importe_total`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `num_recibos`    INT UNSIGNED  NOT NULL DEFAULT 0,
    `estado`         ENUM('borrador','enviada','cobrada') NOT NULL DEFAULT 'borrador',
    `id_usuario_creador` INT UNSIGNED  NULL,
    `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_remesa`),
    INDEX `idx_remesa_gimnasio` (`id_gimnasio`, `fecha_cobro`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `remesa_recibo` (
    `id_recibo`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_remesa`           INT UNSIGNED  NOT NULL,
    `id_socio`            INT UNSIGNED      NULL,
    `id_socio_membresia`  INT UNSIGNED      NULL,
    `nombre_socio`        VARCHAR(200)  NOT NULL,
    `referencia_mandato`  VARCHAR(35)   NOT NULL,
    `fecha_firma_mandato` DATE          NOT NULL,
    `iban`                VARCHAR(34)   NOT NULL,
    `importe`             DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    `concepto`            VARCHAR(140)  NOT NULL,
    `secuencia`           ENUM('FRST','RCUR') NOT NULL DEFAULT 'RCUR',
    `estado`              ENUM('pendiente','cobrado','devuelto') NOT NULL DEFAULT 'pendiente',
    `motivo_devolucion`   VARCHAR(255)      NULL,
    `fecha_estado`        DATETIME          NULL,
    PRIMARY KEY (`id_recibo`),
    INDEX `idx_recibo_remesa` (`id_remesa`),
    INDEX `idx_recibo_socio`  (`id_socio`, `estado`),
    FOREIGN KEY (`id_remesa`) REFERENCES `remesa`(`id_remesa`) ON DELETE CASCADE,
    FOREIGN KEY (`id_socio`)  REFERENCES `usuario`(`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
