-- Migración v13: identidad visual por gimnasio y acceso en dos pasos.
--
-- El acceso pasa a tener dos pantallas:
--   1. La de la plataforma, con nuestra marca, donde se elige el gimnasio.
--   2. La del gimnasio elegido, con SU logo y SUS colores, donde entra su equipo.
--
-- Para eso cada sede guarda:
--   `slug`            identificador corto para la URL (sede-centro), de modo que
--                     cada gimnasio pueda tener un enlace directo a su acceso.
--   `logo`            nombre del archivo subido a public/assets/gimnasios/.
--   `color_primario`  color de su marca, usado en botones y cabecera.
--   `color_texto`     color del texto sobre ese primario: un logo con fondo
--                     amarillo necesita texto oscuro, uno azul lo necesita claro.
--                     Se guarda en vez de calcularlo para poder afinarlo a mano.
--
-- Los valores por defecto son los del panel actual, así que hasta que se suba
-- un logo todo se ve exactamente igual que ahora.
--
-- Aplicar a mano desde phpMyAdmin, después de la v12.

ALTER TABLE `gimnasio`
    ADD COLUMN IF NOT EXISTS `slug`           VARCHAR(60)  NULL AFTER `nombre`,
    ADD COLUMN IF NOT EXISTS `logo`           VARCHAR(255) NULL AFTER `slug`,
    ADD COLUMN IF NOT EXISTS `color_primario` VARCHAR(7)   NOT NULL DEFAULT '#4f46e5' AFTER `logo`,
    ADD COLUMN IF NOT EXISTS `color_texto`    VARCHAR(7)   NOT NULL DEFAULT '#ffffff' AFTER `color_primario`;

-- El slug identifica la sede en la URL, así que no puede repetirse.
-- Se rellena a partir del nombre para las sedes que ya existen.
UPDATE `gimnasio`
   SET `slug` = LOWER(
        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        TRIM(`nombre`),
        ' ', '-'), 'á','a'), 'é','e'), 'í','i'), 'ó','o'), 'ú','u'), 'ñ','n'), '.', '')
   )
 WHERE `slug` IS NULL OR `slug` = '';

-- Si dos sedes generasen el mismo slug, se desempata con el id.
UPDATE `gimnasio` g
  INNER JOIN (
      SELECT `slug` FROM `gimnasio` GROUP BY `slug` HAVING COUNT(*) > 1
  ) d ON d.`slug` = g.`slug`
   SET g.`slug` = CONCAT(g.`slug`, '-', g.`id_gimnasio`);

ALTER TABLE `gimnasio`
    ADD UNIQUE KEY IF NOT EXISTS `uq_gimnasio_slug` (`slug`);
