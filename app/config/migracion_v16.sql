-- Migración v16: el rol `propietario` pasa a llamarse `empresa`.
--
-- El nombre era ambiguo: sonaba al dueño del gimnasio, cuando en realidad es
-- quien explota la plataforma. Con la nomenclatura nueva:
--
--   empresa    nosotros. Da de alta gimnasios, ve los datos de todas las sedes
--              y crea a los administradores de cada cliente.
--   admin      el responsable de UN gimnasio: su personal, sus productos,
--              sus socios y su caja.
--   recepcion  mostrador de ese gimnasio: ventas y socios.
--   socio      cliente del gimnasio. Es un dato del negocio, no accede al panel.
--
-- Se amplía el ENUM antes de traducir los valores para que ninguna fila quede
-- fuera del conjunto permitido a mitad del proceso.
--
-- Aplicar a mano desde phpMyAdmin, después de la v15.

ALTER TABLE `usuario`
    MODIFY `rol` ENUM('empresa','propietario','admin','recepcion','socio')
    NOT NULL DEFAULT 'socio';

UPDATE `usuario` SET `rol` = 'empresa' WHERE `rol` = 'propietario';

ALTER TABLE `usuario`
    MODIFY `rol` ENUM('empresa','admin','recepcion','socio')
    NOT NULL DEFAULT 'socio';
