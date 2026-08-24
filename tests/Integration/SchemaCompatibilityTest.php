<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/helpers/SchemaCompatibility.php';

$db = Database::getInstance()->getConnection();
$root = dirname(__DIR__, 2);
$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gimnera-schema-compat-' . bin2hex(random_bytes(5));
mkdir($temp, 0700, true);
try {
    $current = SchemaCompatibility::assertRuntime($db, $root);
    check('release actual acepta esquema actual', $current['current'] === 31);

    file_put_contents($temp . '/SCHEMA_COMPATIBILITY.json', json_encode([
        'release' => 'previous-compatible',
        'minimum_runtime_version' => 27,
        'maximum_runtime_version' => 31,
        'maximum_migrator_version' => 31,
    ], JSON_THROW_ON_ERROR));
    check('release declarada compatible acepta esquema actual', SchemaCompatibility::assertRuntime($db, $temp)['current'] === 31);

    file_put_contents($temp . '/SCHEMA_COMPATIBILITY.json', json_encode([
        'release' => 'f23-schema30',
        'minimum_runtime_version' => 27,
        'maximum_runtime_version' => 30,
        'maximum_migrator_version' => 30,
    ], JSON_THROW_ON_ERROR));
    $f23Rejected = false;
    try { SchemaCompatibility::assertRuntime($db, $temp); } catch (RuntimeException) { $f23Rejected = true; }
    check('F23 rechaza explícitamente schema v31', $f23Rejected);

    file_put_contents($temp . '/SCHEMA_COMPATIBILITY.json', json_encode([
        'release' => 'previous-incompatible',
        'minimum_runtime_version' => 27,
        'maximum_runtime_version' => 28,
        'maximum_migrator_version' => 28,
    ], JSON_THROW_ON_ERROR));
    $runtimeRejected = false; $migratorRejected = false;
    try { SchemaCompatibility::assertRuntime($db, $temp); } catch (RuntimeException $e) { $runtimeRejected = true; }
    try { SchemaCompatibility::assertMigrator($db, $temp); } catch (RuntimeException $e) { $migratorRejected = true; }
    check('release anterior incompatible rechaza esquema futuro', $runtimeRejected);
    check('migrador antiguo rechaza migración futura', $migratorRejected);

    file_put_contents($temp . '/SCHEMA_COMPATIBILITY.json', '{"release":"broken"}');
    $invalidRejected = false;
    try { SchemaCompatibility::assertRuntime($db, $temp); } catch (RuntimeException $e) { $invalidRejected = true; }
    check('metadata incompleta se rechaza explícitamente', $invalidRejected);
} finally {
    @unlink($temp . '/SCHEMA_COMPATIBILITY.json');
    @rmdir($temp);
}

finishTests();
