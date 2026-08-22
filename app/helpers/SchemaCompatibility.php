<?php

final class SchemaCompatibility
{
    public static function metadata(string $releaseRoot): array
    {
        $path = rtrim($releaseRoot, '/\\') . DIRECTORY_SEPARATOR . 'SCHEMA_COMPATIBILITY.json';
        if (!is_file($path)) {
            throw new RuntimeException('La release no declara compatibilidad de esquema.');
        }
        $data = json_decode((string) file_get_contents($path), true);
        foreach (['minimum_runtime_version', 'maximum_runtime_version', 'maximum_migrator_version'] as $key) {
            if (!is_array($data) || !isset($data[$key]) || !is_int($data[$key]) || $data[$key] < 1) {
                throw new RuntimeException('La declaración de compatibilidad no es válida.');
            }
        }
        if ($data['minimum_runtime_version'] > $data['maximum_runtime_version']
            || $data['maximum_runtime_version'] > $data['maximum_migrator_version']) {
            throw new RuntimeException('El rango de compatibilidad es incoherente.');
        }
        return $data;
    }

    public static function currentVersion(PDO $db): int
    {
        $exists = $db->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='schema_migrations'"
        )->fetchColumn();
        if ((int) $exists !== 1) return 0;
        $names = $db->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $latest = 0;
        foreach ($names as $name) {
            $version = self::migrationVersion((string) $name);
            if ($version === null) throw new RuntimeException('La base contiene una migración con nombre desconocido.');
            $latest = max($latest, $version);
        }
        return $latest;
    }

    public static function assertRuntime(PDO $db, string $releaseRoot): array
    {
        $metadata = self::metadata($releaseRoot);
        $current = self::currentVersion($db);
        if ($current < $metadata['minimum_runtime_version'] || $current > $metadata['maximum_runtime_version']) {
            throw new RuntimeException('El esquema no es compatible con el runtime de esta release.');
        }
        return ['mode' => 'runtime', 'current' => $current, 'metadata' => $metadata];
    }

    public static function assertMigrator(PDO $db, string $releaseRoot): array
    {
        $metadata = self::metadata($releaseRoot);
        $current = self::currentVersion($db);
        if ($current > $metadata['maximum_migrator_version']) {
            throw new RuntimeException('El migrador de esta release no entiende el esquema actual.');
        }
        return ['mode' => 'migrate', 'current' => $current, 'metadata' => $metadata];
    }

    private static function migrationVersion(string $name): ?int
    {
        if ($name === 'schema.sql') return 0;
        if ($name === 'migracion.sql') return 1;
        return preg_match('/^migracion_v(\d+)\.sql$/', $name, $match) ? (int) $match[1] : null;
    }
}
