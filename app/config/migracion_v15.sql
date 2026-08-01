-- Migración v15: limpieza final del portal de cursos.
--
-- Elimina lo que quedaba del proyecto original y que el gimnasio ya no usa.
--
-- ⚠️ ANTES DE APLICARLA EN PRODUCCIÓN
--    Esta migración BORRA tablas y columnas con sus datos. En el entorno de
--    desarrollo todas estaban vacías, pero en producción pueden conservar el
--    histórico del portal de cursos. Comprueba antes:
--
--      SELECT COUNT(*) FROM curso;
--      SELECT COUNT(*) FROM personas;
--      SELECT COUNT(*) FROM categoria;
--
--    Si devuelven filas que quieras conservar, expórtalas primero. Y en
--    cualquier caso, haz copia de seguridad completa: esto no se deshace.


-- ---------------------------------------------------------------------------
-- 1. Tablas del portal de cursos
--
-- `personas` guardaba las inscripciones, `curso` y `categoria` el catálogo y
-- `visitas` un contador de la web pública. Nada de eso existe ya en el código.
-- ---------------------------------------------------------------------------

DROP TRIGGER IF EXISTS `personas_fecha_insert`;
DROP TRIGGER IF EXISTS `visitas_fecha_insert`;

DROP TABLE IF EXISTS `personas`;
DROP TABLE IF EXISTS `curso`;
DROP TABLE IF EXISTS `categoria`;
DROP TABLE IF EXISTS `visitas`;
DROP TABLE IF EXISTS `usuario_curso`;

-- El registro público desapareció con el portal, así que ya no se verifican
-- correos de alta.
DROP TABLE IF EXISTS `verificacion_email`;


-- ---------------------------------------------------------------------------
-- 2. Columnas de `usuario` que ningún formulario rellena
--
-- Venían del registro público del portal, que pedía la dirección completa.
-- El alta de socio del gimnasio solo pide nombre, apellidos, DNI, teléfono,
-- email e IBAN, así que estas columnas se guardaban siempre a NULL.
-- ---------------------------------------------------------------------------

ALTER TABLE `usuario`
    DROP COLUMN IF EXISTS `fecha_nacimiento`,
    DROP COLUMN IF EXISTS `pais`,
    DROP COLUMN IF EXISTS `provincia`,
    DROP COLUMN IF EXISTS `localidad`,
    DROP COLUMN IF EXISTS `direccion`,
    DROP COLUMN IF EXISTS `codigo_postal`,
    DROP COLUMN IF EXISTS `genero`,
    DROP COLUMN IF EXISTS `email_verificado`;


-- ---------------------------------------------------------------------------
-- 3. Columna `imagen` de producto
--
-- Se mantiene: el panel de productos sí permite subir una foto por producto.
-- (Anotado aquí para que no se borre por confusión con las de curso.)
-- ---------------------------------------------------------------------------
