<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/MigrationException.php';

/** Precondición de seguridad antes de escribir datos de negocio. */
final class ImportBackupGuard
{
    public static function verify(): array
    {
        if (APP_ENV === 'test') {
            if (getenv('IMPORT_TEST_BACKUP_VERIFIED') !== '1') {
                throw new MigrationException('La prueba no ha declarado un backup simulado válido.', 'backup_required');
            }
            return ['reference' => 'test-fixture://backup-verified', 'verified_at' => date('Y-m-d H:i:s')];
        }

        $dir = APP_ENV === 'production' ? COPIAS_EXTERNAS_DIR : COPIAS_DIR;
        if ($dir === '' || !is_dir($dir)) {
            throw new MigrationException('No existe un repositorio de backup válido para confirmar la importación.', 'backup_required');
        }
        $files = array_values(array_filter(
            glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'backup_db_*.sql*') ?: [],
            static fn($file) => is_file($file) && !str_ends_with($file, '.sha256')
        ));
        usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        $file = $files[0] ?? null;
        if (!$file || filemtime($file) < time() - 86400) {
            throw new MigrationException('Hace falta un backup verificado de las últimas 24 horas.', 'backup_stale');
        }
        $sidecar = $file . '.sha256';
        if (!is_file($sidecar)) {
            throw new MigrationException('El backup reciente no tiene checksum.', 'backup_checksum_missing');
        }
        $expected = strtolower(strtok(trim((string) file_get_contents($sidecar)), " \t"));
        $actual = strtolower((string) hash_file('sha256', $file));
        if (!preg_match('/^[a-f0-9]{64}$/', $expected) || !hash_equals($expected, $actual)) {
            throw new MigrationException('El backup reciente no supera SHA-256.', 'backup_checksum_failed');
        }
        return [
            'reference' => basename($file) . '#sha256=' . $actual,
            'verified_at' => date('Y-m-d H:i:s'),
        ];
    }
}
