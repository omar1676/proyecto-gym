<?php
require_once dirname(__DIR__) . '/app/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/MigrationManager.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$version = trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')) ?: 'unknown';
$commit = defined('APP_RELEASE') && APP_RELEASE !== '' ? APP_RELEASE : 'unknown';
if ($commit === 'unknown' && is_dir(dirname(__DIR__) . '/.git') && function_exists('exec')) {
    @exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' rev-parse --short HEAD 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), $out, $code);
    if ($code === 0 && !empty($out[0])) $commit = trim($out[0]);
}
$schema = (new MigrationManager())->status();
echo json_encode(['version' => $version, 'commit' => $commit, 'environment' => APP_ENV, 'database' => DB_NAME, 'schema' => $schema], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($schema['initialized'] && !$schema['pending'] && !$schema['checksum_mismatch'] ? 0 : 1);
