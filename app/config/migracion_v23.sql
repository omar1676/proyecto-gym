-- Fase 7: paginación y última membresía por socio.
-- Índices separados para ámbito empresa y ámbito sede; ambos respetan el
-- orden estable del listado y permiten detenerse tras la página solicitada.

ALTER TABLE `usuario`
  ADD INDEX `idx_usuario_empresa_rol_orden`
    (`id_empresa`, `rol`, `apellidos`, `nombre`, `id_usuario`),
  ADD INDEX `idx_usuario_sede_rol_orden`
    (`id_gimnasio`, `rol`, `apellidos`, `nombre`, `id_usuario`);

ALTER TABLE `socio_membresia`
  ADD INDEX `idx_sm_socio_fin`
    (`id_socio`, `fecha_fin`, `id_socio_membresia`);
