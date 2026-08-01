-- Migración v9: disciplinas de contacto como suplementos independientes.
--
-- Sustituye el suplemento genérico "Artes marciales" por uno por disciplina
-- (Boxeo, MMA, Jiu-jitsu), todos al mismo precio: 25 €/mes.
--
-- Las contrataciones ya hechas NO se tocan: `socio_membresia` congela el nombre
-- y el importe del suplemento en el momento de la venta, así que los socios que
-- contrataron "Artes marciales" siguen mostrando eso en su histórico y en los
-- reportes. El suplemento antiguo solo se desactiva, para que deje de ofrecerse
-- en el alta sin romper lo anterior.
--
-- Aplicar a mano desde phpMyAdmin, después de la v8.

INSERT INTO `suplemento` (`nombre`, `descripcion`, `precio_mensual`)
SELECT * FROM (SELECT 'Boxeo', 'Acceso a las clases dirigidas de boxeo', 25.00) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `suplemento` WHERE `nombre` = 'Boxeo');

INSERT INTO `suplemento` (`nombre`, `descripcion`, `precio_mensual`)
SELECT * FROM (SELECT 'MMA', 'Acceso a las clases dirigidas de artes marciales mixtas', 25.00) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `suplemento` WHERE `nombre` = 'MMA');

INSERT INTO `suplemento` (`nombre`, `descripcion`, `precio_mensual`)
SELECT * FROM (SELECT 'Jiu-jitsu', 'Acceso a las clases dirigidas de jiu-jitsu', 25.00) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `suplemento` WHERE `nombre` = 'Jiu-jitsu');

-- Se retira de la oferta, no se borra: hay contrataciones que lo referencian.
UPDATE `suplemento` SET `estado` = 'inactivo' WHERE `nombre` = 'Artes marciales';
