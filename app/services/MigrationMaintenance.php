<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/MigrationStorage.php';

/** Limpieza técnica global; solo debe invocarse desde cron/CLI. */
final class MigrationMaintenance
{
    public static function purgeExpired(?PDO $db = null, ?MigrationStorage $storage = null): int
    {
        $db ??= Database::getInstance()->getConnection();
        $storage ??= new MigrationStorage();
        $stmt = $db->query(
            "SELECT b.id_batch,b.storage_key,b.status
             FROM migration_batch b
             WHERE b.expires_at<NOW()
               AND (b.storage_key IS NOT NULL
                    OR EXISTS(SELECT 1 FROM migration_batch_row r WHERE r.id_batch=b.id_batch)
                    OR EXISTS(SELECT 1 FROM migration_batch_issue i WHERE i.id_batch=b.id_batch))
             ORDER BY b.id_batch LIMIT 500"
        );
        $purged = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $batch) {
            if ($batch['storage_key'] && !$storage->delete($batch['storage_key'])) continue;
            $db->beginTransaction();
            try {
                $db->prepare('DELETE FROM migration_batch_issue WHERE id_batch=:b')->execute([':b'=>$batch['id_batch']]);
                $db->prepare('DELETE FROM migration_batch_row WHERE id_batch=:b')->execute([':b'=>$batch['id_batch']]);
                $status = in_array($batch['status'], ['completed','completed_with_warnings'], true)
                    ? $batch['status'] : 'expired';
                $db->prepare('UPDATE migration_batch SET storage_key=NULL,status=:s WHERE id_batch=:b')
                    ->execute([':s'=>$status,':b'=>$batch['id_batch']]);
                $db->commit();
                $purged++;
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }
        return $purged;
    }
}
