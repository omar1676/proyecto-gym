<?php

require_once dirname(__DIR__, 2) . '/app/helpers/ReleaseIntegrityVerifier.php';

$ok = 0;
$fail = 0;
$check = static function (string $description, bool $condition) use (&$ok, &$fail): void {
    if ($condition) {
        $ok++;
    } else {
        $fail++;
        echo "FALLO: {$description}\n";
    }
};

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gimnera-release-integrity-' . bin2hex(random_bytes(6));
mkdir($root . DIRECTORY_SEPARATOR . 'public', 0750, true);
$files = [
    'VERSION' => "0.15.2-fase24.1\n",
    'public/index.php' => "<?php\necho 'ok';\n",
];
foreach ($files as $path => $contents) {
    file_put_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path), $contents);
}
$manifest = [
    'schema' => 1,
    'version' => '0.15.2-fase24.1',
    'commit' => str_repeat('a', 40),
    'files' => [],
];
foreach ($files as $path => $contents) {
    $manifest['files'][] = ['path' => $path, 'bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
}
$manifestPath = $root . DIRECTORY_SEPARATOR . '.gimnera-release-manifest.json';
file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

try {
    $valid = ReleaseIntegrityVerifier::verify($root, $manifestPath);
    $check('release intacta acepta hashes exactos', $valid['ok'] && $valid['checked'] === 2);

    file_put_contents($root . DIRECTORY_SEPARATOR . 'public/index.php', "<?php\r\necho 'ok';\r\n");
    $crlf = ReleaseIntegrityVerifier::verify($root, $manifestPath);
    $check('normalización CRLF posterior al build se detecta', !$crlf['ok']
        && in_array('file_integrity_mismatch:public/index.php', $crlf['errors'], true));
    file_put_contents($root . DIRECTORY_SEPARATOR . 'public/index.php', $files['public/index.php']);

    file_put_contents($root . DIRECTORY_SEPARATOR . 'public/rogue.php', '<?php echo 1;');
    $rogue = ReleaseIntegrityVerifier::verify($root, $manifestPath);
    $check('archivo funcional añadido tras deploy se detecta', !$rogue['ok']
        && in_array('unexpected_file:public/rogue.php', $rogue['errors'], true));
    unlink($root . DIRECTORY_SEPARATOR . 'public/rogue.php');

    $hostile = $manifest;
    $hostile['files'][] = ['path' => '../escape', 'bytes' => 0, 'sha256' => hash('sha256', '')];
    file_put_contents($manifestPath, json_encode($hostile, JSON_THROW_ON_ERROR));
    $traversal = ReleaseIntegrityVerifier::verify($root, $manifestPath);
    $check('path traversal dentro del manifiesto se rechaza', !$traversal['ok']
        && in_array('manifest_path_invalid_or_duplicate', $traversal['errors'], true));
} finally {
    @unlink($root . DIRECTORY_SEPARATOR . 'public/index.php');
    @unlink($root . DIRECTORY_SEPARATOR . 'VERSION');
    @unlink($manifestPath);
    @rmdir($root . DIRECTORY_SEPARATOR . 'public');
    @rmdir($root);
}

echo "RESUMEN: {$ok} correctas, {$fail} fallidas\n";
exit($fail ? 1 : 0);
