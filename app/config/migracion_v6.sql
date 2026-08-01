-- Migración v6: adaptación del portal de cursos a gestión de gimnasio.
--
-- Añade el modelo de negocio del gimnasio SIN borrar nada de lo anterior:
--   1. Roles: usuario -> socio, profesor -> recepcion.
--   2. `categoria_producto` (copia de `categoria`).
--   3. `producto`  — catálogo con precio y control de stock.
--   4. `venta` + `venta_linea` — ventas de mostrador (cabecera + líneas).
--   5. `tipo_membresia` + `socio_membresia` — catálogo y contratación.
--
-- Las tablas `curso`, `personas`, `categoria` y `visitas` se conservan intactas:
-- esta migración solo AÑADE. Si hay que revertir, basta con no usar las tablas
-- nuevas (y restaurar el ENUM de `usuario`.`rol`, ver paso 1).
--
-- Aplicar a mano desde phpMyAdmin sobre la base de datos del portal, igual que
-- las migraciones v2 a v5. HAZ COPIA DE SEGURIDAD ANTES DE EJECUTARLA.


-- ---------------------------------------------------------------------------
-- 1. Roles: admin / profesor / usuario  ->  admin / recepcion / socio
--
-- Se hace en tres pasos para que ninguna fila quede con un valor fuera del ENUM
-- a mitad del proceso: primero se amplía, luego se traducen los datos y solo al
-- final se retiran los valores antiguos.
-- ---------------------------------------------------------------------------

ALTER TABLE `usuario`
    MODIFY `rol` ENUM('admin','profesor','usuario','recepcion','socio')
    NOT NULL DEFAULT 'usuario';

UPDATE `usuario` SET `rol` = 'socio'     WHERE `rol` = 'usuario';
UPDATE `usuario` SET `rol` = 'recepcion' WHERE `rol` = 'profesor';

ALTER TABLE `usuario`
    MODIFY `rol` ENUM('admin','recepcion','socio')
    NOT NULL DEFAULT 'socio';


-- ---------------------------------------------------------------------------
-- 2. Categorías de producto
--
-- Tabla propia en lugar de reutilizar `categoria`, para que el catálogo del
-- gimnasio no herede las categorías de cursos ni dependa de ellas.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `categoria_producto` (
    `id_categoria`     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nombre_categoria` VARCHAR(100)  NOT NULL,
    PRIMARY KEY (`id_categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copia las categorías existentes conservando sus IDs.
INSERT INTO `categoria_producto` (`id_categoria`, `nombre_categoria`)
SELECT `id_categoria`, `nombre_categoria` FROM `categoria`
ON DUPLICATE KEY UPDATE `nombre_categoria` = VALUES(`nombre_categoria`);

-- Categorías base del gimnasio (solo si no existen ya por nombre).
INSERT INTO `categoria_producto` (`nombre_categoria`)
SELECT * FROM (SELECT 'Bebidas') AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `categoria_producto` WHERE `nombre_categoria` = 'Bebidas');

INSERT INTO `categoria_producto` (`nombre_categoria`)
SELECT * FROM (SELECT 'Suplementos') AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `categoria_producto` WHERE `nombre_categoria` = 'Suplementos');

INSERT INTO `categoria_producto` (`nombre_categoria`)
SELECT * FROM (SELECT 'Merchandising') AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `categoria_producto` WHERE `nombre_categoria` = 'Merchandising');


-- ---------------------------------------------------------------------------
-- 3. Productos
--
-- `stock_minimo` es el umbral por producto para el reporte de bajo stock: una
-- caja de proteína no se repone con el mismo margen que una botella de agua.
-- `stock` se deja con signo a propósito: la aplicación impide que baje de cero
-- (VentaModel::registrar), y así un descuadre queda visible en vez de dar error.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `producto` (
    `id_producto`   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nombre`        VARCHAR(150)  NOT NULL,
    `descripcion`   TEXT              NULL,
    `imagen`        VARCHAR(255)      NULL,
    `precio`        DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    `stock`         INT           NOT NULL DEFAULT 0,
    `stock_minimo`  INT           NOT NULL DEFAULT 5,
    `estado`        ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
    `id_categoria`  INT UNSIGNED      NULL,
    `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_producto`),
    INDEX `idx_producto_estado` (`estado`),
    INDEX `idx_producto_stock`  (`stock`),
    FOREIGN KEY (`id_categoria`) REFERENCES `categoria_producto`(`id_categoria`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 4. Ventas (cabecera + líneas)
--
-- `id_socio` es NULL cuando la venta es a un cliente de paso.
-- `id_usuario_registro` guarda quién cobró (admin o recepción).
-- Las líneas congelan `nombre_producto` y `precio_unitario` en el momento de la
-- venta: si mañana cambia el precio del producto, los reportes de meses
-- anteriores siguen cuadrando.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `venta` (
    `id_venta`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_socio`            INT UNSIGNED      NULL,
    `id_usuario_registro` INT UNSIGNED      NULL,
    `metodo_pago`         ENUM('efectivo','datafono','transferencia') NOT NULL DEFAULT 'efectivo',
    `total`               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `fecha`               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_venta`),
    INDEX `idx_venta_fecha` (`fecha`),
    INDEX `idx_venta_socio` (`id_socio`),
    FOREIGN KEY (`id_socio`)            REFERENCES `usuario`(`id_usuario`) ON DELETE SET NULL,
    FOREIGN KEY (`id_usuario_registro`) REFERENCES `usuario`(`id_usuario`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `venta_linea` (
    `id_linea`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_venta`        INT UNSIGNED  NOT NULL,
    `id_producto`     INT UNSIGNED      NULL,
    `nombre_producto` VARCHAR(150)  NOT NULL,
    `cantidad`        INT UNSIGNED  NOT NULL DEFAULT 1,
    `precio_unitario` DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    `subtotal`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id_linea`),
    INDEX `idx_linea_venta`    (`id_venta`),
    INDEX `idx_linea_producto` (`id_producto`),
    FOREIGN KEY (`id_venta`)    REFERENCES `venta`(`id_venta`)       ON DELETE CASCADE,
    FOREIGN KEY (`id_producto`) REFERENCES `producto`(`id_producto`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 5. Membresías
--
-- `tipo_membresia` es el catálogo (Mensual, Trimestral, Anual) y
-- `socio_membresia` cada contratación concreta.
--
-- No se guarda un campo "activa": el estado se deduce de `fecha_fin` comparada
-- con la fecha de hoy, así nunca queda desincronizado y el aviso de "próximas a
-- vencer" es una simple consulta por rango sobre `idx_socio_membresia_fin`.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `tipo_membresia` (
    `id_tipo_membresia` INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nombre`            VARCHAR(100)  NOT NULL,
    `descripcion`       VARCHAR(255)      NULL,
    `precio`            DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    `duracion_meses`    INT UNSIGNED  NOT NULL DEFAULT 1,
    `estado`            ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
    `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_tipo_membresia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `socio_membresia` (
    `id_socio_membresia` INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `id_socio`           INT UNSIGNED  NOT NULL,
    `id_tipo_membresia`  INT UNSIGNED      NULL,
    `nombre_tipo`        VARCHAR(100)  NOT NULL,
    `precio_pagado`      DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    `metodo_pago`        ENUM('efectivo','datafono','transferencia') NOT NULL DEFAULT 'efectivo',
    `fecha_inicio`       DATE          NOT NULL,
    `fecha_fin`          DATE          NOT NULL,
    `created_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_socio_membresia`),
    INDEX `idx_socio_membresia_socio` (`id_socio`),
    INDEX `idx_socio_membresia_fin`   (`fecha_fin`),
    FOREIGN KEY (`id_socio`)          REFERENCES `usuario`(`id_usuario`)              ON DELETE CASCADE,
    FOREIGN KEY (`id_tipo_membresia`) REFERENCES `tipo_membresia`(`id_tipo_membresia`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tipos de membresía de partida (ajusta precios desde el panel).
INSERT INTO `tipo_membresia` (`nombre`, `descripcion`, `precio`, `duracion_meses`)
SELECT * FROM (SELECT 'Mensual', 'Acceso completo durante 1 mes', 35.00, 1) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `tipo_membresia` WHERE `nombre` = 'Mensual');

INSERT INTO `tipo_membresia` (`nombre`, `descripcion`, `precio`, `duracion_meses`)
SELECT * FROM (SELECT 'Trimestral', 'Acceso completo durante 3 meses', 95.00, 3) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `tipo_membresia` WHERE `nombre` = 'Trimestral');

INSERT INTO `tipo_membresia` (`nombre`, `descripcion`, `precio`, `duracion_meses`)
SELECT * FROM (SELECT 'Anual', 'Acceso completo durante 12 meses', 330.00, 12) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `tipo_membresia` WHERE `nombre` = 'Anual');
