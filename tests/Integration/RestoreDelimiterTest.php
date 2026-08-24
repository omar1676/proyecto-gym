<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/SchemaMigrationTestFactory.php';
require_once dirname(__DIR__, 2) . '/app/helpers/SqlDumpImporter.php';

$fixture = SchemaMigrationTestFactory::create('restore_delimiter');
$artifact = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gimnera_f241_delimiter_' . bin2hex(random_bytes(6)) . '.sql.gz';
try {
    $sql = <<<'SQL'
CREATE TABLE delimiter_restore_probe (id INT PRIMARY KEY AUTO_INCREMENT, value_text VARCHAR(40) NOT NULL);
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`synthetic_absent`@`localhost`*/ /*!50003 TRIGGER delimiter_restore_probe_before_insert
BEFORE INSERT ON delimiter_restore_probe FOR EACH ROW
BEGIN
  SET NEW.value_text = UPPER(NEW.value_text);
END */;;
DELIMITER ;
INSERT INTO delimiter_restore_probe (value_text) VALUES ('restored');
SQL;
    $handle = gzopen($artifact, 'wb9');
    if (!$handle) throw new RuntimeException('No se pudo crear el dump sintético.');
    gzwrite($handle, $sql);
    gzclose($handle);

    SqlDumpImporter::import($fixture['db'], $artifact);
    check('restore acepta DELIMITER de mysqldump',
        (int) $fixture['db']->query("SELECT COUNT(*) FROM information_schema.triggers
            WHERE trigger_schema=DATABASE() AND trigger_name='delimiter_restore_probe_before_insert'")->fetchColumn() === 1);
    check('trigger restaurado conserva su comportamiento',
        (string) $fixture['db']->query('SELECT value_text FROM delimiter_restore_probe LIMIT 1')->fetchColumn() === 'RESTORED');
} finally {
    @unlink($artifact);
    SchemaMigrationTestFactory::drop($fixture);
}

finishTests();
