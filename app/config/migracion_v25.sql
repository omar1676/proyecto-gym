-- Fase 9: circuito económico operativo y caja por sede.
--
-- Se separan cuatro hechos que antes estaban mezclados:
--   * socio_membresia: lo contratado y su precio histórico;
--   * obligacion_pago: lo que corresponde cobrar;
--   * cobro: dinero presentado/confirmado/devuelto;
--   * caja_*: sesiones y movimientos, sin confundir tarjeta/SEPA con efectivo.
--
-- La migración es aditiva. No elimina contratos, ventas, remesas ni recibos.

CREATE TABLE `obligacion_pago` (
    `id_obligacion` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `id_socio_membresia` INT UNSIGNED NULL,
    `concepto` VARCHAR(190) NOT NULL,
    `importe` DECIMAL(12,2) NOT NULL,
    `fecha_emision` DATE NOT NULL,
    `fecha_vencimiento` DATE NOT NULL,
    `estado` ENUM('pendiente','pagada','vencida','cancelada','exenta','revisar') NOT NULL DEFAULT 'pendiente',
    `origen` ENUM('membresia','ajuste','importacion') NOT NULL DEFAULT 'membresia',
    `id_usuario_creador` INT UNSIGNED NULL,
    `idempotency_key` VARCHAR(80) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_obligacion`),
    UNIQUE KEY `uq_obligacion_membresia` (`id_socio_membresia`),
    UNIQUE KEY `uq_obligacion_idempotencia` (`id_empresa`, `idempotency_key`),
    KEY `idx_obligacion_tenant_estado_fecha` (`id_empresa`, `id_gimnasio`, `estado`, `fecha_vencimiento`),
    KEY `idx_obligacion_socio_estado` (`id_socio`, `estado`, `fecha_vencimiento`),
    CONSTRAINT `chk_obligacion_importe_no_negativo` CHECK (`importe` >= 0),
    CONSTRAINT `fk_obligacion_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_obligacion_gimnasio` FOREIGN KEY (`id_gimnasio`) REFERENCES `gimnasio` (`id_gimnasio`) ON DELETE RESTRICT,
    CONSTRAINT `fk_obligacion_socio` FOREIGN KEY (`id_socio`) REFERENCES `usuario` (`id_usuario`) ON DELETE RESTRICT,
    CONSTRAINT `fk_obligacion_membresia` FOREIGN KEY (`id_socio_membresia`) REFERENCES `socio_membresia` (`id_socio_membresia`) ON DELETE RESTRICT,
    CONSTRAINT `fk_obligacion_usuario` FOREIGN KEY (`id_usuario_creador`) REFERENCES `usuario` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cobro` (
    `id_cobro` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_socio` INT UNSIGNED NOT NULL,
    `id_obligacion` BIGINT UNSIGNED NULL,
    `id_socio_membresia` INT UNSIGNED NULL,
    `id_remesa_recibo` INT UNSIGNED NULL,
    `concepto` VARCHAR(190) NOT NULL,
    `importe` DECIMAL(12,2) NOT NULL,
    `metodo` ENUM('efectivo','tarjeta','domiciliacion','transferencia','otro') NOT NULL,
    `estado` ENUM('presentado','confirmado','devuelto','anulado') NOT NULL,
    `fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fecha_estado` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `id_usuario` INT UNSIGNED NULL,
    `referencia` VARCHAR(190) NULL,
    `origen` ENUM('mostrador','remesa','importacion','ajuste') NOT NULL DEFAULT 'mostrador',
    `idempotency_key` VARCHAR(80) NULL,
    `motivo` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_cobro`),
    UNIQUE KEY `uq_cobro_recibo` (`id_remesa_recibo`),
    UNIQUE KEY `uq_cobro_idempotencia` (`id_empresa`, `idempotency_key`),
    KEY `idx_cobro_tenant_fecha` (`id_empresa`, `id_gimnasio`, `fecha`),
    KEY `idx_cobro_socio_estado` (`id_socio`, `estado`, `fecha`),
    KEY `idx_cobro_obligacion_estado` (`id_obligacion`, `estado`),
    CONSTRAINT `chk_cobro_importe_positivo` CHECK (`importe` > 0),
    CONSTRAINT `fk_cobro_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_cobro_gimnasio` FOREIGN KEY (`id_gimnasio`) REFERENCES `gimnasio` (`id_gimnasio`) ON DELETE RESTRICT,
    CONSTRAINT `fk_cobro_socio` FOREIGN KEY (`id_socio`) REFERENCES `usuario` (`id_usuario`) ON DELETE RESTRICT,
    CONSTRAINT `fk_cobro_obligacion` FOREIGN KEY (`id_obligacion`) REFERENCES `obligacion_pago` (`id_obligacion`) ON DELETE RESTRICT,
    CONSTRAINT `fk_cobro_membresia` FOREIGN KEY (`id_socio_membresia`) REFERENCES `socio_membresia` (`id_socio_membresia`) ON DELETE RESTRICT,
    CONSTRAINT `fk_cobro_recibo` FOREIGN KEY (`id_remesa_recibo`) REFERENCES `remesa_recibo` (`id_recibo`) ON DELETE RESTRICT,
    CONSTRAINT `fk_cobro_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `caja_sesion` (
    `id_sesion_caja` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_usuario_apertura` INT UNSIGNED NOT NULL,
    `fecha_apertura` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `saldo_inicial` DECIMAL(12,2) NOT NULL,
    `id_usuario_cierre` INT UNSIGNED NULL,
    `fecha_cierre` DATETIME NULL,
    `saldo_esperado` DECIMAL(12,2) NULL,
    `saldo_declarado` DECIMAL(12,2) NULL,
    `diferencia` DECIMAL(12,2) NULL,
    `observacion` VARCHAR(500) NULL,
    `estado` ENUM('abierta','cerrada') NOT NULL DEFAULT 'abierta',
    -- NULL para sesiones cerradas; permite garantizar una sola abierta por sede.
    `sede_abierta` INT UNSIGNED GENERATED ALWAYS AS
        (CASE WHEN `estado` = 'abierta' THEN `id_gimnasio` ELSE NULL END) STORED,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_sesion_caja`),
    UNIQUE KEY `uq_caja_una_abierta_por_sede` (`sede_abierta`),
    KEY `idx_caja_tenant_fecha` (`id_empresa`, `id_gimnasio`, `fecha_apertura`),
    CONSTRAINT `chk_caja_saldos_no_negativos` CHECK (`saldo_inicial` >= 0 AND (`saldo_declarado` IS NULL OR `saldo_declarado` >= 0)),
    CONSTRAINT `fk_caja_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_caja_gimnasio` FOREIGN KEY (`id_gimnasio`) REFERENCES `gimnasio` (`id_gimnasio`) ON DELETE RESTRICT,
    CONSTRAINT `fk_caja_usuario_apertura` FOREIGN KEY (`id_usuario_apertura`) REFERENCES `usuario` (`id_usuario`) ON DELETE RESTRICT,
    CONSTRAINT `fk_caja_usuario_cierre` FOREIGN KEY (`id_usuario_cierre`) REFERENCES `usuario` (`id_usuario`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `caja_movimiento` (
    `id_movimiento_caja` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_empresa` INT UNSIGNED NOT NULL,
    `id_gimnasio` INT UNSIGNED NOT NULL,
    `id_sesion_caja` BIGINT UNSIGNED NULL,
    `tipo` ENUM('venta','anulacion_venta','cobro','devolucion','ajuste_entrada','ajuste_salida') NOT NULL,
    `metodo` ENUM('efectivo','tarjeta','domiciliacion','transferencia','otro') NOT NULL,
    `importe` DECIMAL(12,2) NOT NULL,
    `afecta_efectivo` TINYINT(1) NOT NULL DEFAULT 0,
    `id_venta` INT UNSIGNED NULL,
    `id_cobro` BIGINT UNSIGNED NULL,
    `id_usuario` INT UNSIGNED NULL,
    `concepto` VARCHAR(190) NOT NULL,
    `motivo` VARCHAR(255) NULL,
    `idempotency_key` VARCHAR(100) NULL,
    `fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_movimiento_caja`),
    UNIQUE KEY `uq_caja_mov_idempotencia` (`id_empresa`, `idempotency_key`),
    UNIQUE KEY `uq_caja_mov_venta_tipo` (`id_venta`, `tipo`),
    KEY `idx_caja_mov_sesion_fecha` (`id_sesion_caja`, `fecha`),
    KEY `idx_caja_mov_tenant_fecha` (`id_empresa`, `id_gimnasio`, `fecha`),
    KEY `idx_caja_mov_cobro` (`id_cobro`),
    CONSTRAINT `chk_caja_mov_importe_no_cero` CHECK (`importe` <> 0),
    CONSTRAINT `fk_caja_mov_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT,
    CONSTRAINT `fk_caja_mov_gimnasio` FOREIGN KEY (`id_gimnasio`) REFERENCES `gimnasio` (`id_gimnasio`) ON DELETE RESTRICT,
    CONSTRAINT `fk_caja_mov_sesion` FOREIGN KEY (`id_sesion_caja`) REFERENCES `caja_sesion` (`id_sesion_caja`) ON DELETE RESTRICT,
    CONSTRAINT `fk_caja_mov_venta` FOREIGN KEY (`id_venta`) REFERENCES `venta` (`id_venta`) ON DELETE RESTRICT,
    CONSTRAINT `fk_caja_mov_cobro` FOREIGN KEY (`id_cobro`) REFERENCES `cobro` (`id_cobro`) ON DELETE RESTRICT,
    CONSTRAINT `fk_caja_mov_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Una obligación por cada contratación histórica. El precio queda congelado.
INSERT INTO `obligacion_pago`
    (`id_empresa`, `id_gimnasio`, `id_socio`, `id_socio_membresia`, `concepto`,
     `importe`, `fecha_emision`, `fecha_vencimiento`, `estado`, `origen`, `created_at`)
SELECT g.`id_empresa`, sm.`id_gimnasio`, sm.`id_socio`, sm.`id_socio_membresia`,
       CONCAT('Membresía ', sm.`nombre_tipo`,
              IF(sm.`nombre_suplemento` IS NULL OR sm.`nombre_suplemento` = '', '', CONCAT(' + ', sm.`nombre_suplemento`))),
       ROUND(sm.`precio_pagado` + sm.`precio_suplemento`, 2),
       sm.`fecha_inicio`, sm.`fecha_inicio`,
       CASE
           WHEN sm.`es_prueba` = 1 OR (sm.`precio_pagado` + sm.`precio_suplemento`) = 0 THEN 'exenta'
           WHEN sm.`metodo_pago` <> 'transferencia' AND sm.`estado_pago` = 'pagado' THEN 'pagada'
           WHEN EXISTS (
               SELECT 1 FROM `remesa_recibo` rr
               WHERE rr.`id_socio_membresia` = sm.`id_socio_membresia` AND rr.`estado` = 'cobrado'
           ) THEN 'pagada'
           WHEN EXISTS (
               SELECT 1 FROM `remesa_recibo` rr
               WHERE rr.`id_socio_membresia` = sm.`id_socio_membresia` AND rr.`estado` = 'devuelto'
           ) THEN 'vencida'
           WHEN sm.`fecha_inicio` < CURDATE() THEN 'vencida'
           ELSE 'pendiente'
       END,
       'membresia', sm.`created_at`
FROM `socio_membresia` sm
INNER JOIN `gimnasio` g ON g.`id_gimnasio` = sm.`id_gimnasio`;

-- Cobros de mostrador históricos. Las domiciliaciones se derivan de recibos,
-- no del antiguo estado_pago que nacía como "pagado" por defecto.
INSERT INTO `cobro`
    (`id_empresa`, `id_gimnasio`, `id_socio`, `id_obligacion`, `id_socio_membresia`,
     `concepto`, `importe`, `metodo`, `estado`, `fecha`, `fecha_estado`, `origen`, `created_at`)
SELECT o.`id_empresa`, o.`id_gimnasio`, o.`id_socio`, o.`id_obligacion`, o.`id_socio_membresia`,
       o.`concepto`, o.`importe`,
       CASE sm.`metodo_pago` WHEN 'datafono' THEN 'tarjeta' ELSE sm.`metodo_pago` END,
       'confirmado', sm.`created_at`, sm.`created_at`, 'importacion', sm.`created_at`
FROM `obligacion_pago` o
INNER JOIN `socio_membresia` sm ON sm.`id_socio_membresia` = o.`id_socio_membresia`
WHERE sm.`es_prueba` = 0
  AND o.`importe` > 0
  AND sm.`metodo_pago` IN ('efectivo','datafono')
  AND sm.`estado_pago` = 'pagado';

-- Cada recibo SEPA es un intento de cobro trazable; una devolución conserva el
-- mismo registro en estado devuelto, nunca crea ni borra el cobro original.
INSERT INTO `cobro`
    (`id_empresa`, `id_gimnasio`, `id_socio`, `id_obligacion`, `id_socio_membresia`,
     `id_remesa_recibo`, `concepto`, `importe`, `metodo`, `estado`, `fecha`,
     `fecha_estado`, `referencia`, `origen`, `motivo`, `created_at`)
SELECT g.`id_empresa`, r.`id_gimnasio`, rr.`id_socio`, o.`id_obligacion`, rr.`id_socio_membresia`,
       rr.`id_recibo`, rr.`concepto`, rr.`importe`, 'domiciliacion',
       CASE rr.`estado` WHEN 'cobrado' THEN 'confirmado' WHEN 'devuelto' THEN 'devuelto' ELSE 'presentado' END,
       r.`created_at`, COALESCE(rr.`fecha_estado`, r.`created_at`), rr.`referencia_mandato`,
       'remesa', rr.`motivo_devolucion`, r.`created_at`
FROM `remesa_recibo` rr
INNER JOIN `remesa` r ON r.`id_remesa` = rr.`id_remesa`
INNER JOIN `gimnasio` g ON g.`id_gimnasio` = r.`id_gimnasio`
LEFT JOIN `obligacion_pago` o ON o.`id_socio_membresia` = rr.`id_socio_membresia`;

-- El campo heredado se conserva por compatibilidad, pero deja de inventar que
-- una domiciliación sin recibo cobrado está pagada.
UPDATE `socio_membresia` sm
LEFT JOIN `obligacion_pago` o ON o.`id_socio_membresia` = sm.`id_socio_membresia`
SET sm.`estado_pago` = IF(o.`estado` IN ('pagada','exenta'), 'pagado', 'pendiente');

-- Las ventas históricas se reflejan como movimientos operativos. No se inventan
-- sesiones de caja antiguas: id_sesion_caja queda NULL de forma explícita.
INSERT INTO `caja_movimiento`
    (`id_empresa`, `id_gimnasio`, `tipo`, `metodo`, `importe`, `afecta_efectivo`,
     `id_venta`, `id_usuario`, `concepto`, `idempotency_key`, `fecha`, `created_at`)
SELECT g.`id_empresa`, v.`id_gimnasio`, 'venta',
       CASE v.`metodo_pago` WHEN 'datafono' THEN 'tarjeta' ELSE v.`metodo_pago` END,
       v.`total`, IF(v.`metodo_pago` = 'efectivo', 1, 0), v.`id_venta`, v.`id_usuario_registro`,
       CONCAT('Venta ', v.`serie`, '-', v.`ejercicio`, '-', LPAD(v.`numero`, 6, '0')),
       CONCAT('migracion-venta-', v.`id_venta`), v.`fecha`, v.`fecha`
FROM `venta` v
INNER JOIN `gimnasio` g ON g.`id_gimnasio` = v.`id_gimnasio`;

INSERT INTO `caja_movimiento`
    (`id_empresa`, `id_gimnasio`, `tipo`, `metodo`, `importe`, `afecta_efectivo`,
     `id_venta`, `id_usuario`, `concepto`, `motivo`, `idempotency_key`, `fecha`, `created_at`)
SELECT g.`id_empresa`, v.`id_gimnasio`, 'anulacion_venta',
       CASE v.`metodo_pago` WHEN 'datafono' THEN 'tarjeta' ELSE v.`metodo_pago` END,
       -v.`total`, IF(v.`metodo_pago` = 'efectivo', 1, 0), v.`id_venta`, v.`id_usuario_anulacion`,
       CONCAT('Anulación venta ', v.`serie`, '-', v.`ejercicio`, '-', LPAD(v.`numero`, 6, '0')),
       v.`motivo_anulacion`, CONCAT('migracion-anulacion-', v.`id_venta`),
       COALESCE(v.`anulada_en`, v.`fecha`), COALESCE(v.`anulada_en`, v.`fecha`)
FROM `venta` v
INNER JOIN `gimnasio` g ON g.`id_gimnasio` = v.`id_gimnasio`
WHERE v.`estado` = 'anulada';
