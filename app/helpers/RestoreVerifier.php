<?php

require_once __DIR__ . '/BackupStorage.php';
require_once __DIR__ . '/MigrationManager.php';

final class RestoreVerifier
{
    public static function verify(PDO $restored, string $migrationDir, string $artifact = '', string $filesTarget = ''): array
    {
        $tables = $restored->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);
        $manager = new MigrationManager($restored, $migrationDir);
        $status = $manager->status();
        $appliedVersions = array_map([self::class, 'migrationNumber'], $status['applied'] ?? []);
        $schemaAtBackup = $appliedVersions === [] ? 0 : max($appliedVersions);
        $availableVersions = array_map(
            static fn(string $file): int => self::migrationNumber(basename($file)),
            $manager->files()
        );
        $currentSchema = max($availableVersions ?: [0]);

        $checks = [
            'database_has_tables' => $tables !== [],
            'schema_tracking_present' => in_array('schema_migrations', $tables, true),
            'tracked_checksums_valid' => ($status['checksum_mismatch'] ?? []) === [],
            'tracked_structure_consistent' => ($status['structural_mismatch'] ?? []) === [],
        ];
        $artifactState = 'NOT_VERIFIED';
        if ($artifact !== '') {
            $manifest = BackupStorage::verifyArtifact($artifact);
            $checks['artifact_manifest_hash'] = ($manifest['kind'] ?? null) === 'database';
            $artifactState = $checks['artifact_manifest_hash'] ? 'OK' : 'FAILED';
        }

        $files = null;
        if ($filesTarget !== '') {
            [$files, $checks['files_manifest_and_hashes']] = self::verifyFiles($filesTarget);
        }
        $structureOk = !in_array(false, $checks, true);
        $backupIntegrity = !$structureOk ? 'FAILED' : ($artifact === '' ? 'NOT_VERIFIED' : $artifactState);
        $schemaCurrency = $schemaAtBackup === $currentSchema
            ? 'CURRENT'
            : ($schemaAtBackup < $currentSchema ? 'OLD' : 'FUTURE');

        return [
            'status' => $backupIntegrity === 'OK' && $schemaCurrency !== 'FUTURE'
                ? 'verified'
                : strtolower($backupIntegrity),
            'BACKUP_INTEGRITY' => $backupIntegrity,
            'SCHEMA_CURRENCY' => $schemaCurrency,
            'SCHEMA_AT_BACKUP' => $schemaAtBackup,
            'CURRENT_SCHEMA' => $currentSchema,
            'MIGRATION_REQUIRED' => $schemaAtBackup < $currentSchema ? 'YES' : 'NO',
            'checks' => $checks,
            'pending_migrations' => $status['pending'] ?? [],
            'restored_table_count' => count($tables),
            'files' => $files,
        ];
    }

    private static function migrationNumber(string $name): int
    {
        return preg_match('/^migracion_v(\d+)\.sql$/', $name, $match) ? (int) $match[1] : 0;
    }

    /** @return array{0:array{manifest_entries:int,verified:int},1:bool} */
    private static function verifyFiles(string $filesTarget): array
    {
        $root = realpath($filesTarget);
        $manifestPath = $root ? $root . DIRECTORY_SEPARATOR . 'BACKUP_MANIFEST.json' : '';
        $manifest = $manifestPath !== '' && is_file($manifestPath)
            ? json_decode((string) file_get_contents($manifestPath), true)
            : null;
        $summary = ['manifest_entries' => 0, 'verified' => 0];
        $ok = is_array($manifest) && isset($manifest['files']) && is_array($manifest['files']);
        if (!$ok) return [$summary, false];
        $summary['manifest_entries'] = count($manifest['files']);
        foreach ($manifest['files'] as $entry) {
            $relative = str_replace('\\', '/', (string) ($entry['path'] ?? ''));
            if ($relative === '' || str_contains($relative, '../') || str_starts_with($relative, '/')) return [$summary, false];
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path)
                || filesize($path) !== (int) ($entry['bytes'] ?? -1)
                || !hash_equals(
                    strtolower((string) ($entry['sha256'] ?? '')),
                    strtolower((string) hash_file('sha256', $path))
                )) return [$summary, false];
            $summary['verified']++;
        }
        return [$summary, true];
    }
}
