-- Migración v5: añade columna `imagen` a la tabla `curso`
-- para permitir que el admin suba una imagen personalizada por curso.
-- Si la columna queda NULL, el portal usa la imagen automática de Lorem Flickr.

ALTER TABLE curso
    ADD COLUMN imagen VARCHAR(255) DEFAULT NULL AFTER descripcion;
