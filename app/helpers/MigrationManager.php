<?php
require_once __DIR__ . '/../config/database.php';

final class MigrationManager
{
    private PDO $db;
    private string $dir;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?: Database::getInstance()->getConnection();
        $this->dir = dirname(__DIR__) . '/config';
    }

    public function files(): array
    {
        $files = [$this->dir . '/schema.sql', $this->dir . '/migracion.sql'];
        for ($i = 2; $i <= 999; $i++) {
            $file = $this->dir . '/migracion_v' . $i . '.sql';
            if (!is_file($file)) break;
            $files[] = $file;
        }
        return $files;
    }

    public function trackingExists(): bool
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='schema_migrations'");
        return (int) $stmt->fetchColumn() === 1;
    }

    public function isEmptyDatabase(): bool
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() === 0;
    }

    public function baselineExisting(): void
    {
        if ($this->trackingExists()) return;
        $markers = (int) $this->db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND column_name='idempotency_key' AND table_name IN ('venta','socio_membresia','remesa')")->fetchColumn();
        if ($markers !== 3) throw new RuntimeException('No puede inferirse que el esquema existente llegue a v21. Baseline detenido.');
        $v22 = $this->dir . '/migracion_v22.sql';
        $this->db->exec(file_get_contents($v22));
        $this->recordFiles($this->files());
    }

    public function migrateFresh(): array
    {
        if (!$this->isEmptyDatabase()) throw new RuntimeException('La instalación --fresh exige una base completamente vacía.');
        $applied = [];
        foreach ($this->files() as $file) {
            $sql = str_replace(['`portal_de_cursos`', 'portal_de_cursos'], ['`' . DB_NAME . '`', DB_NAME], file_get_contents($file));
            $this->db->exec($sql);
            $applied[] = basename($file);
        }
        if (!$this->trackingExists()) throw new RuntimeException('La migración de tracking no se creó.');
        $this->recordFiles($this->files());
        return $applied;
    }

    public function migratePending(): array
    {
        if (!$this->trackingExists()) throw new RuntimeException('Falta schema_migrations; ejecuta --baseline-current o --fresh.');
        $status = $this->status();
        if ($status['checksum_mismatch']) throw new RuntimeException('Una migración aplicada fue modificada: ' . implode(', ', $status['checksum_mismatch']));
        $applied = [];
        foreach ($status['pending'] as $name) {
            $file = $this->dir . '/' . $name;
            $sql = str_replace(['`portal_de_cursos`', 'portal_de_cursos'], ['`' . DB_NAME . '`', DB_NAME], file_get_contents($file));
            $this->db->exec($sql);
            $this->recordFiles([$file]);
            $applied[] = $name;
        }
        return $applied;
    }

    public function status(): array
    {
        $files = $this->files();
        if (!$this->trackingExists()) return ['initialized' => false, 'latest' => basename(end($files)), 'applied' => [], 'pending' => array_map('basename', $files), 'checksum_mismatch' => []];
        $rows = $this->db->query('SELECT migration, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
        $pending = $mismatch = [];
        foreach ($files as $file) {
            $name = basename($file); $hash = hash_file('sha256', $file);
            if (!isset($rows[$name])) $pending[] = $name;
            elseif (!hash_equals((string) $rows[$name], $hash)) $mismatch[] = $name;
        }
        return ['initialized' => true, 'latest' => basename(end($files)), 'applied' => array_keys($rows), 'pending' => $pending, 'checksum_mismatch' => $mismatch];
    }

    private function recordFiles(array $files): void
    {
        $version = trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION')) ?: null;
        $stmt = $this->db->prepare('INSERT INTO schema_migrations (migration, checksum, release_version) VALUES (:name,:hash,:version) ON DUPLICATE KEY UPDATE checksum=VALUES(checksum), release_version=VALUES(release_version)');
        foreach ($files as $file) $stmt->execute([':name' => basename($file), ':hash' => hash_file('sha256', $file), ':version' => $version]);
    }
}
