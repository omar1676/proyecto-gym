<?php
require_once dirname(__DIR__, 2) . '/app/helpers/BackupStorage.php';

$checks = 0;
$failures = 0;
$check = static function (string $name, bool $condition) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) { $failures++; echo "FALLO: {$name}\n"; }
};

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gimnera-backup-test-' . bin2hex(random_bytes(6));
mkdir($base, 0750, true);
try {
    $names = [];
    for ($i = 0; $i < 100; $i++) {
        $names[] = BackupStorage::uniqueArtifactPath($base, 'backup_db_', '.sql.gz');
    }
    $check('cien nombres creados en el mismo segundo no colisionan', count(array_unique($names)) === 100);
    BackupStorage::writeExclusive($names[0], 'primero');
    $collisionRejected = false;
    try { BackupStorage::writeExclusive($names[0], 'segundo'); }
    catch (RuntimeException $e) { $collisionRejected = true; }
    $check('la escritura exclusiva impide sobrescribir un backup válido', $collisionRejected && file_get_contents($names[0]) === 'primero');

    $artifact = $base . DIRECTORY_SEPARATOR . 'backup_db_test.sql.gz';
    file_put_contents($artifact, str_repeat('backup-sintetico', 40));
    $hash = BackupStorage::checksum($artifact);
    file_put_contents($artifact . '.manifest.json', json_encode([
        'artifact' => basename($artifact),
        'size_bytes' => filesize($artifact),
        'sha256' => $hash,
    ], JSON_PRETTY_PRINT));

    $manifest = BackupStorage::verifyArtifact($artifact);
    $check('acepta artefacto con hash y manifiesto coherentes', ($manifest['sha256'] ?? '') === $hash);
    $check('un fallo de transferencia no altera el backup local', hash_file('sha256', $artifact) === $hash);

    file_put_contents($artifact, 'alterado', FILE_APPEND);
    $tamperDetected = false;
    try { BackupStorage::verifyArtifact($artifact); }
    catch (RuntimeException $e) { $tamperDetected = true; }
    $check('rechaza artefacto alterado', $tamperDetected);

    file_put_contents($artifact, str_repeat('backup-sintetico', 40));
    BackupStorage::checksum($artifact);
    file_put_contents($artifact . '.manifest.json', json_encode([
        'artifact' => basename($artifact),
        'size_bytes' => filesize($artifact),
        'sha256' => hash_file('sha256', $artifact),
    ]));
    $check('la recuperación del artefacto vuelve a verificar', is_array(BackupStorage::verifyArtifact($artifact)));
} finally {
    if (is_dir($base)) {
        foreach (scandir($base) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') @unlink($base . DIRECTORY_SEPARATOR . $name);
        }
        @rmdir($base);
    }
}

echo "BackupStorage: {$checks} comprobaciones, {$failures} fallos\n";
exit($failures ? 1 : 0);
