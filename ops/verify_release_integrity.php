<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/helpers/ReleaseIntegrityVerifier.php';

$root = dirname(__DIR__);
$manifest = $root . '/.gimnera-release-manifest.json';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--root=')) {
        $root = substr($argument, strlen('--root='));
    } elseif (str_starts_with($argument, '--manifest=')) {
        $manifest = substr($argument, strlen('--manifest='));
    }
}

$result = ReleaseIntegrityVerifier::verify($root, $manifest);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
exit($result['ok'] ? 0 : 1);
