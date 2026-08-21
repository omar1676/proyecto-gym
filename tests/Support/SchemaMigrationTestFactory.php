<?php

require_once dirname(__DIR__, 2) . '/app/helpers/MigrationManager.php';

final class SchemaMigrationTestFactory
{
    public static function create(string $purpose): array
    {
        if (APP_ENV !== 'test') {
            throw new RuntimeException('Las pruebas de migración exigen APP_ENV=test.');
        }
        $purpose = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $purpose) ?: 'schema');
        $name = substr('gimnera_f19_test_' . $purpose . '_' . bin2hex(random_bytes(5)), 0, 60);
        if (!preg_match('/test/i', $name) || $name === DB_NAME) {
            throw new RuntimeException('Nombre inseguro para base temporal.');
        }

        $admin = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $quoted = self::quoted($name);
        $admin->exec("CREATE DATABASE {$quoted} DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $name . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        return ['name' => $name, 'db' => $db, 'admin' => $admin];
    }

    public static function applyThrough(PDO $db, string $database, int $version): void
    {
        $dir = dirname(__DIR__, 2) . '/app/config';
        $files = [$dir . '/schema.sql', $dir . '/migracion.sql'];
        for ($v = 2; $v <= $version; $v++) {
            $file = $dir . '/migracion_v' . $v . '.sql';
            if (!is_file($file)) {
                throw new RuntimeException('Falta ' . basename($file) . '.');
            }
            $files[] = $file;
        }
        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);
            $sql = str_replace(['`portal_de_cursos`', 'portal_de_cursos'], ['`' . $database . '`', $database], $sql);
            $db->exec($sql);
        }
    }

    public static function tableExists(PDO $db, string $table): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table'
        );
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public static function indexExists(PDO $db, string $table, string $index): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics '
            . 'WHERE table_schema=DATABASE() AND table_name=:table AND index_name=:index'
        );
        $stmt->execute([':table' => $table, ':index' => $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function copyMigrationsThrough(string $target, int $version): void
    {
        if (!is_dir($target) && !mkdir($target, 0700, true)) {
            throw new RuntimeException('No se pudo crear el directorio temporal de migraciones.');
        }
        $source = dirname(__DIR__, 2) . '/app/config';
        foreach (['schema.sql', 'migracion.sql'] as $name) {
            if (!copy($source . '/' . $name, $target . '/' . $name)) {
                throw new RuntimeException('No se pudo copiar ' . $name . '.');
            }
        }
        for ($v = 2; $v <= $version; $v++) {
            $name = 'migracion_v' . $v . '.sql';
            if (!copy($source . '/' . $name, $target . '/' . $name)) {
                throw new RuntimeException('No se pudo copiar ' . $name . '.');
            }
        }
    }

    public static function removeDirectory(string $directory): void
    {
        $real = realpath($directory);
        $tmp = realpath(sys_get_temp_dir());
        if ($real === false || $tmp === false || !str_starts_with($real, $tmp . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Se rechazó limpiar un directorio fuera del temporal.');
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($real);
    }

    public static function drop(array $fixture): void
    {
        $name = (string) ($fixture['name'] ?? '');
        if (!preg_match('/^gimnera_f19_test_[a-z0-9_]+$/', $name)) {
            throw new RuntimeException('Se rechazó borrar una base fuera del patrón F19.');
        }
        $fixture['db'] = null;
        $fixture['admin']->exec('DROP DATABASE IF EXISTS ' . self::quoted($name));
    }

    private static function quoted(string $database): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new RuntimeException('Nombre de base no válido.');
        }
        return '`' . $database . '`';
    }
}
