<?php
require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/config/database.php';
require_once dirname(__DIR__) . '/app/helpers/SchemaCompatibility.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$mode = 'runtime';
$release = dirname(__DIR__);
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--mode=')) $mode = substr($arg, 7);
    if (str_starts_with($arg, '--release=')) $release = substr($arg, 10);
}
if (!in_array($mode, ['runtime', 'migrate'], true)) {
    fwrite(STDERR, "Modo no válido.\n");
    exit(2);
}

try {
    $db = Database::getInstance()->getConnection();
    $result = $mode === 'runtime'
        ? SchemaCompatibility::assertRuntime($db, $release)
        : SchemaCompatibility::assertMigrator($db, $release);
    echo json_encode([
        'status' => 'compatible',
        'mode' => $result['mode'],
        'schema_version' => $result['current'],
        'release' => $result['metadata']['release'] ?? basename($release),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'SCHEMA GATE: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
