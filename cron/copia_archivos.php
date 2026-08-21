<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/helpers/BackupStorage.php';
require_once __DIR__ . '/../app/helpers/BackupManifest.php';
require_once __DIR__ . '/../app/helpers/AppLogger.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); fwrite(STDERR, "Solo CLI.\n"); exit(1); }

$root = dirname(__DIR__);
$dest = rtrim(COPIAS_DIR, '/\\');
try {
    BackupStorage::ensureDirectory($dest);
    $base = $dest . DIRECTORY_SEPARATOR . 'backup_files_' . gmdate('Y-m-d_His\Z');
    $tarPath = $base . '.tar';
    $archive = new PharData($tarPath);
    $roots = ['public/assets/fotos', 'public/assets/productos', 'public/assets/gimnasios', 'public/assets/marca'];
    $files = [];
    foreach ($roots as $relativeRoot) {
        $absoluteRoot = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot);
        if (!is_dir($absoluteRoot)) continue;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) continue;
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            $archive->addFile($item->getPathname(), $relative);
            $files[] = ['path' => $relative, 'bytes' => $item->getSize(), 'sha256' => hash_file('sha256', $item->getPathname())];
        }
    }
    foreach (['.env.example', 'VERSION'] as $relative) {
        if (is_file($root . DIRECTORY_SEPARATOR . $relative)) {
            $archive->addFile($root . DIRECTORY_SEPARATOR . $relative, $relative);
            $files[] = ['path' => $relative, 'bytes' => filesize($root . DIRECTORY_SEPARATOR . $relative), 'sha256' => hash_file('sha256', $root . DIRECTORY_SEPARATOR . $relative)];
        }
    }
    $archive->addFromString('BACKUP_MANIFEST.json', json_encode(array_merge(
        BackupManifest::identity(),
        ['kind' => 'files', 'file_count' => count($files), 'files' => $files]
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $archive->compress(Phar::GZ);
    unset($archive);
    @unlink($tarPath);
    $file = $tarPath . '.gz';
    $check = new PharData($file);
    if (!isset($check['BACKUP_MANIFEST.json']) || count($files) === 0) throw new RuntimeException('El archivo no contiene manifiesto o está vacío.');
    unset($check);
    $hash = BackupStorage::checksum($file);
    BackupManifest::writeForArtifact($file, 'files', ['file_count' => count($files)]);
    $external = BackupStorage::externalCopy($file);
    $deleted = BackupStorage::rotate($dest, 'backup_files_');
    if ($external !== null) BackupStorage::rotate(COPIAS_EXTERNAS_DIR, 'backup_files_');
    AppLogger::info('backup_files_ok', ['file' => basename($file), 'files' => count($files), 'bytes' => filesize($file), 'external' => $external !== null]);
    printf("Copia de archivos verificada: %s, %d archivos, SHA-256 %s, externa %s. Rotadas: %d\n", basename($file), count($files), $hash, $external ? 'sí' : 'NO CONFIGURADA', $deleted);
    exit(0);
} catch (Throwable $e) {
    if (isset($tarPath)) { @unlink($tarPath); @unlink($tarPath . '.gz'); }
    AppLogger::error('backup_files_failed', ['reason' => $e->getMessage()]);
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
