-- Fase 22: onboarding SaaS repetible y catálogos tenant-aware.
-- Forward-only. Las empresas ya operativas se consideran ACTIVE; solo las
-- creadas por el nuevo servicio empiezan inactivas y pasan por revisión.

ALTER TABLE `empresa`
    ADD COLUMN `slug` VARCHAR(80) NULL AFTER `nombre_comercial`,
    ADD COLUMN `onboarding_key` CHAR(36) NULL AFTER `slug`,
    ADD COLUMN `onboarding_state`
        ENUM('CONFIGURING','READY_FOR_REVIEW','ACTIVE','CANCELLED')
        NOT NULL DEFAULT 'ACTIVE' AFTER `onboarding_key`,
    ADD COLUMN `onboarding_updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        AFTER `onboarding_state`;

UPDATE `empresa`
   SET `slug` = CONCAT('legacy-', `id_empresa`)
 WHERE `slug` IS NULL OR `slug` = '';

ALTER TABLE `empresa`
    MODIFY `slug` VARCHAR(80) NOT NULL,
    ADD UNIQUE KEY `uq_empresa_slug` (`slug`),
    ADD UNIQUE KEY `uq_empresa_onboarding_key` (`onboarding_key`),
    ADD INDEX `idx_empresa_onboarding_state` (`onboarding_state`, `estado`);

-- La misma persona puede pertenecer a dos clientes diferentes. El login de
-- nivel 1 ya determina la empresa, por lo que las identidades humanas son
-- únicas dentro de su tenant. El scope 0 queda reservado a la plataforma.
ALTER TABLE `usuario`
    DROP INDEX `dni`,
    DROP INDEX `email`,
    DROP INDEX `nombre_usuario`,
    MODIFY `dni` VARCHAR(20) NULL,
    ADD COLUMN `identidad_empresa_scope` INT UNSIGNED
        GENERATED ALWAYS AS (IFNULL(`id_empresa`, 0)) STORED AFTER `id_empresa`,
    ADD UNIQUE KEY `uq_usuario_empresa_dni` (`identidad_empresa_scope`, `dni`),
    ADD UNIQUE KEY `uq_usuario_empresa_email` (`identidad_empresa_scope`, `email`),
    ADD UNIQUE KEY `uq_usuario_empresa_username` (`identidad_empresa_scope`, `nombre_usuario`);

-- La concurrencia no puede crear dos sedes con el mismo nombre dentro de una
-- empresa. El slug y el email técnico siguen siendo globales porque se usan
-- antes de disponer de un TenantContext.
ALTER TABLE `gimnasio`
    ADD UNIQUE KEY `uq_gimnasio_empresa_nombre` (`id_empresa`, `nombre`);

-- categoria_producto era global. Se conserva cada categoría existente para
-- la primera empresa que la usa y se crea una copia por cada empresa adicional
-- que ya tenga productos vinculados a ella. No se borra ningún producto.
ALTER TABLE `categoria_producto`
    ADD COLUMN `id_empresa` INT UNSIGNED NULL AFTER `id_categoria`;

UPDATE `categoria_producto` cp
   SET cp.`id_empresa` = COALESCE(
       (SELECT MIN(g.`id_empresa`)
          FROM `producto` p
          JOIN `gimnasio` g ON g.`id_gimnasio` = p.`id_gimnasio`
         WHERE p.`id_categoria` = cp.`id_categoria`),
       (SELECT MIN(e.`id_empresa`) FROM `empresa` e)
   );

INSERT INTO `categoria_producto` (`id_empresa`, `nombre_categoria`)
SELECT DISTINCT g.`id_empresa`, cp.`nombre_categoria`
  FROM `producto` p
  JOIN `gimnasio` g ON g.`id_gimnasio` = p.`id_gimnasio`
  JOIN `categoria_producto` cp ON cp.`id_categoria` = p.`id_categoria`
  LEFT JOIN `categoria_producto` existente
    ON existente.`id_empresa` = g.`id_empresa`
   AND existente.`nombre_categoria` = cp.`nombre_categoria`
 WHERE existente.`id_categoria` IS NULL;

UPDATE `producto` p
JOIN `gimnasio` g ON g.`id_gimnasio` = p.`id_gimnasio`
JOIN `categoria_producto` origen ON origen.`id_categoria` = p.`id_categoria`
JOIN `categoria_producto` destino
  ON destino.`id_empresa` = g.`id_empresa`
 AND destino.`nombre_categoria` = origen.`nombre_categoria`
SET p.`id_categoria` = destino.`id_categoria`
WHERE origen.`id_empresa` <> g.`id_empresa`;

ALTER TABLE `categoria_producto`
    MODIFY `id_empresa` INT UNSIGNED NOT NULL,
    ADD UNIQUE KEY `uq_categoria_empresa_nombre` (`id_empresa`, `nombre_categoria`),
    ADD INDEX `idx_categoria_empresa` (`id_empresa`),
    ADD CONSTRAINT `fk_categoria_empresa`
        FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`) ON DELETE RESTRICT;
