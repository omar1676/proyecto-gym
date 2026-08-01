-- Migración v17: facturación de verdad — IVA, numeración y anulación sin borrado.
--
-- Tres problemas que arregla, en orden de gravedad:
--
--   1. Anular una venta hacía DELETE. Un registro de cobro no puede desaparecer:
--      ni para Hacienda, ni para cuadrar la caja, ni para saber quién anuló qué.
--      A partir de aquí la venta se queda y cambia de estado.
--   2. No había número de ticket. Sin numeración correlativa no hay documento
--      que entregar al cliente ni forma de referirse a una venta concreta.
--   3. No había IVA en ninguna parte, así que el sistema no podía emitir nada
--      con validez fiscal.
--
-- Criterio de precios: los precios que ya están guardados son PVP CON IVA
-- INCLUIDO, que es como se teclean en el mostrador y como los ve el socio. La
-- base imponible se calcula hacia atrás (precio / (1 + iva/100)). Así esta
-- migración no cambia lo que paga nadie: solo desglosa lo que ya se cobraba.
--
-- El 21 % por defecto es el tipo general. Si alguna cuota tributa a otro tipo,
-- se cambia en la ficha de esa cuota: cada línea guarda el suyo.
--
-- Aplicar a mano desde phpMyAdmin, después de la v16.

/* --- 1. Tipos de IVA en los catálogos ------------------------------------- */

ALTER TABLE `producto`
    ADD COLUMN `iva` DECIMAL(4,2) NOT NULL DEFAULT 21.00 AFTER `precio`;

ALTER TABLE `tipo_membresia`
    ADD COLUMN `iva` DECIMAL(4,2) NOT NULL DEFAULT 21.00 AFTER `precio`;

ALTER TABLE `suplemento`
    ADD COLUMN `iva` DECIMAL(4,2) NOT NULL DEFAULT 21.00 AFTER `precio_mensual`;

/* --- 2. La venta: numeración, desglose y estado ---------------------------- */

ALTER TABLE `venta`
    ADD COLUMN `serie`     VARCHAR(8)       NOT NULL DEFAULT 'A' AFTER `id_venta`,
    ADD COLUMN `ejercicio` SMALLINT UNSIGNED    NULL AFTER `serie`,
    ADD COLUMN `numero`    INT UNSIGNED         NULL AFTER `ejercicio`,
    ADD COLUMN `base_imponible` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `total`,
    ADD COLUMN `total_iva`      DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `base_imponible`,
    ADD COLUMN `estado` ENUM('activa','anulada') NOT NULL DEFAULT 'activa' AFTER `total_iva`,
    ADD COLUMN `anulada_en`           DATETIME     NULL AFTER `estado`,
    ADD COLUMN `id_usuario_anulacion` INT UNSIGNED NULL AFTER `anulada_en`,
    ADD COLUMN `motivo_anulacion`     VARCHAR(255) NULL AFTER `id_usuario_anulacion`;

-- El correlativo es por sede, serie y año: dos locales pueden emitir a la vez
-- sin pisarse, y cada 1 de enero se empieza otra vez por el 1.
ALTER TABLE `venta`
    ADD UNIQUE KEY `uq_venta_numero` (`id_gimnasio`, `serie`, `ejercicio`, `numero`);

ALTER TABLE `venta_linea`
    ADD COLUMN `iva`        DECIMAL(4,2)  NOT NULL DEFAULT 21.00 AFTER `precio_unitario`,
    ADD COLUMN `base_linea` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `subtotal`,
    ADD COLUMN `cuota_iva`  DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `base_linea`;

/* --- 3. Renovación automática de cuotas ----------------------------------- */

-- Solo se renuevan solas las cuotas domiciliadas: las de efectivo o datáfono
-- hay que cobrarlas en mostrador, así que renovarlas sin cobrar sería regalar
-- el acceso. El socio puede pedir que no se le renueve y esto se pone a 0.
ALTER TABLE `socio_membresia`
    ADD COLUMN `renovar_auto` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `origen` ENUM('mostrador','automatica') NOT NULL DEFAULT 'mostrador',
    -- El IVA se congela al contratar, como el precio: si mañana cambia el tipo
    -- de la cuota, lo ya cobrado tiene que seguir desglosándose como se cobró.
    ADD COLUMN `iva` DECIMAL(4,2) NOT NULL DEFAULT 21.00 AFTER `precio_suplemento`;

UPDATE `socio_membresia`
   SET `renovar_auto` = 1
 WHERE `metodo_pago` = 'transferencia' AND `es_prueba` = 0;

/* --- 4. Numerar lo que ya estaba ------------------------------------------ */

-- Las ventas anteriores se numeran por orden de fecha para que el histórico
-- también tenga número. La variable se reinicia con cada sede y año.
SET @sede = NULL, @anio = NULL, @n = 0;

UPDATE `venta` v
JOIN (
    SELECT id_venta,
           @n := IF(@sede <=> id_gimnasio AND @anio = YEAR(fecha), @n + 1, 1) AS num,
           @sede := id_gimnasio AS s,
           @anio := YEAR(fecha) AS a
    FROM `venta`
    ORDER BY id_gimnasio, YEAR(fecha), fecha, id_venta
) AS orden ON orden.id_venta = v.id_venta
SET v.numero    = orden.num,
    v.ejercicio = YEAR(v.fecha);

/* --- 5. Desglosar el IVA de lo ya cobrado --------------------------------- */

UPDATE `venta_linea` l
   SET l.base_linea = ROUND(l.subtotal / (1 + l.iva / 100), 2),
       l.cuota_iva  = ROUND(l.subtotal - (l.subtotal / (1 + l.iva / 100)), 2);

UPDATE `venta` v
   SET v.base_imponible = COALESCE((SELECT SUM(l.base_linea) FROM `venta_linea` l WHERE l.id_venta = v.id_venta), 0),
       v.total_iva      = COALESCE((SELECT SUM(l.cuota_iva)  FROM `venta_linea` l WHERE l.id_venta = v.id_venta), 0);

/* --- 6. Integridad: la sede como clave foránea ---------------------------- */

-- Había índice pero no clave foránea, así que la base permitía filas apuntando
-- a una sede que ya no existe. ON DELETE RESTRICT: una sede con movimientos no
-- se borra por accidente; para cerrarla ya está el interruptor `activo`.
ALTER TABLE `venta`
    ADD CONSTRAINT `fk_venta_gimnasio`
    FOREIGN KEY (`id_gimnasio`) REFERENCES `gimnasio` (`id_gimnasio`) ON DELETE RESTRICT;

ALTER TABLE `socio_membresia`
    ADD CONSTRAINT `fk_sm_gimnasio`
    FOREIGN KEY (`id_gimnasio`) REFERENCES `gimnasio` (`id_gimnasio`) ON DELETE RESTRICT;
