<?php
require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/SqlDumpImporter.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$options = getopt('', ['database:', 'target:', 'files::', 'files-target::', 'recreate', 'existing-empty']);
$backup = isset($options['database']) ? realpath((string) $options['database']) : false;
$target = (string) ($options['target'] ?? '');
if (!$backup || !is_file($backup)) { fwrite(STDERR, "Falta --database=<dump.sql[.gz]> válido.\n"); exit(1); }
if (!preg_match('/^[a-zA-Z0-9_]+$/', $target) || stripos($target, 'restore') === false || in_array($target, [DB_NAME, DB_NAME_PRUEBAS], true)) {
    fwrite(STDERR, "El destino debe ser independiente, contener 'restore' y no coincidir con trabajo/test.\n"); exit(1);
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET;
    $admin = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $exists = (int) $admin->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=" . $admin->quote($target))->fetchColumn();
    $existingEmpty = isset($options['existing-empty']);
    if ($existingEmpty) {
        if (!$exists) throw new RuntimeException('--existing-empty exige una base temporal precreada.');
        $tablesExisting = (int) $admin->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . $admin->quote($target))->fetchColumn();
        if ($tablesExisting !== 0) throw new RuntimeException('La base temporal precreada no está vacía.');
    } else {
        if ($exists && !isset($options['recreate'])) throw new RuntimeException('La base destino ya existe; usa otra, --existing-empty o --recreate explícitamente.');
        if ($exists) $admin->exec('DROP DATABASE `' . $target . '`');
        $admin->exec('CREATE DATABASE `' . $target . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    $db = new PDO($dsn . ';dbname=' . $target, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    SqlDumpImporter::import($db, $backup);
    $tables = (int) $db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
    if ($tables < 5) throw new RuntimeException('La restauración produjo un número de tablas no válido.');

    $filesRestored = 0;
    if (!empty($options['files'])) {
        $files = realpath((string) $options['files']);
        $filesTarget = (string) ($options['files-target'] ?? '');
        if (!$files || $filesTarget === '') throw new RuntimeException('Para archivos se requieren --files y --files-target.');
        if (file_exists($filesTarget) && count(scandir($filesTarget)) > 2) throw new RuntimeException('El destino de archivos debe estar vacío.');
        if (!is_dir($filesTarget) && !mkdir($filesTarget, 0750, true)) throw new RuntimeException('No se pudo crear el destino de archivos.');
        $phar = new PharData($files);
        $phar->extractTo($filesTarget, null, false);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($filesTarget, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) if ($item->isFile()) $filesRestored++;
        if (!is_file(rtrim($filesTarget, '/\\') . '/BACKUP_MANIFEST.json')) throw new RuntimeException('Falta el manifiesto del backup de archivos.');
    }
    echo json_encode(['status'=>'restored','database'=>$target,'tables'=>$tables,'files'=>$filesRestored], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'RESTAURACIÓN DETENIDA: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
