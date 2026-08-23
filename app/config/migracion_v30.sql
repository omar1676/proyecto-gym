-- Fase 23: control optimista de ediciones del perfil de socio.
-- Aditiva y forward-only. La versión evita lost updates silenciosos.

ALTER TABLE `usuario`
    ADD COLUMN `profile_version` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `sesiones_desde`;
