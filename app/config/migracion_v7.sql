-- Migración v7: suplementos sobre la cuota base.
--
-- El gimnasio cobra una cuota base y, opcionalmente, un plus mensual que da
-- acceso a las disciplinas dirigidas (boxeo, MMA, jiu-jitsu, etc.).
--
-- En lugar de duplicar los tipos de membresía ("Mensual" y "Mensual + artes
-- marciales"), el suplemento es una entidad propia:
--   - Cambiar el precio base o el del plus se hace en un solo sitio.
--   - Los reportes pueden separar cuánto viene de cuotas y cuánto de extras.
--   - Añadir otro plus en el futuro (piscina, taquilla, entrenador personal)
--     no obliga a multiplicar el catálogo de cuotas.
--
-- Una contratación admite como mucho un suplemento. Si algún día hacen falta
-- varios a la vez, se sustituyen estas columnas por una tabla de unión.
--
-- Aplicar a mano desde phpMyAdmin, después de la v6.


-- ---------------------------------------------------------------------------
-- 1. Catálogo de suplementos
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `suplemento` (
    `id_suplemento`   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nombre`          VARCHAR(100)  NOT NULL,
    `descripcion`     VARCHAR(255)      NULL,
    `precio_mensual`  DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    `estado`          ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
    `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_suplemento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 2. Suplemento contratado con la membresía
--
-- Se congelan nombre e importe, igual que en las líneas de venta: si mañana
-- sube el plus a 30 €, las contrataciones anteriores siguen reflejando lo que
-- realmente se cobró.
--
-- `precio_suplemento` guarda el importe TOTAL del periodo (precio mensual por
-- los meses de la cuota), no el mensual, para que sumar ingresos sea directo.
-- ---------------------------------------------------------------------------

ALTER TABLE `socio_membresia`
    ADD COLUMN IF NOT EXISTS `id_suplemento`     INT UNSIGNED     NULL AFTER `id_tipo_membresia`,
    ADD COLUMN IF NOT EXISTS `nombre_suplemento` VARCHAR(100)     NULL AFTER `nombre_tipo`,
    ADD COLUMN IF NOT EXISTS `precio_suplemento` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `precio_pagado`;


-- ---------------------------------------------------------------------------
-- 3. Datos de partida
-- ---------------------------------------------------------------------------

-- Cuota base real del gimnasio: 40 € al mes.
UPDATE `tipo_membresia` SET `precio` = 40.00 WHERE `nombre` = 'Mensual';

-- Plus de disciplinas dirigidas: 25 € al mes.
INSERT INTO `suplemento` (`nombre`, `descripcion`, `precio_mensual`)
SELECT * FROM (SELECT 'Artes marciales', 'Acceso a boxeo, MMA, jiu-jitsu y demás clases dirigidas de contacto', 25.00) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `suplemento` WHERE `nombre` = 'Artes marciales');
