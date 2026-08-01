USE `portal_de_cursos`;

ALTER TABLE `usuario`
    ADD COLUMN IF NOT EXISTS `reset_token` VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS `reset_expira` DATETIME NULL;

CREATE INDEX IF NOT EXISTS `idx_reset_token` ON `usuario` (`reset_token`);
