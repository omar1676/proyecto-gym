<?php
/** Construye un ZIP determinista desde un commit Git limpio. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
chdir($root);

/** @return array{stdout:string,stderr:string,exit:int} */
function releaseCommand(array $command, string $cwd): array
{
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $spec, $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['stdout' => '', 'stderr' => 'No se pudo iniciar el proceso.', 'exit' => 127];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['stdout' => (string) $stdout, 'stderr' => (string) $stderr, 'exit' => proc_close($process)];
}

function releaseGit(array $args, string $root): string
{
    $result = releaseCommand(array_merge(['git', '-c', 'safe.directory=' . str_replace('\\', '/', $root)], $args), $root);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('Git falló: ' . trim($result['stderr']));
    }
    return $result['stdout'];
}

try {
    $status = trim(releaseGit(['status', '--porcelain=v1', '--untracked-files=all'], $root));
    if ($status !== '') {
        throw new RuntimeException('El árbol Git no está limpio; no se construye la release.');
    }
    $head = trim(releaseGit(['rev-parse', 'HEAD'], $root));
    $short = trim(releaseGit(['rev-parse', '--short=12', 'HEAD'], $root));
    $commitTime = trim(releaseGit(['show', '-s', '--format=%cI', 'HEAD'], $root));
    $version = trim((string) file_get_contents($root . '/VERSION'));
    if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+-[a-z0-9.-]+$/i', $version)) {
        throw new RuntimeException('VERSION no cumple el formato esperado.');
    }

    $outputDir = null;
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--output-dir=')) {
            $outputDir = substr($arg, strlen('--output-dir='));
        }
    }
    if ($outputDir === null || trim($outputDir) === '') {
        throw new RuntimeException('Indica --output-dir=<directorio>.');
    }
    if (!is_dir($outputDir) && !mkdir($outputDir, 0750, true)) {
        throw new RuntimeException('No se pudo crear el directorio de salida.');
    }
    $outputDir = realpath($outputDir);
    if ($outputDir === false) {
        throw new RuntimeException('No se pudo resolver el directorio de salida.');
    }

    $pathspecs = [
        'app', 'public', 'cron', 'ops', '.env.example', '.env.staging.example', 'SCHEMA_COMPATIBILITY.json',
        'VERSION', 'README.md', 'DESPLIEGUE.md', 'INCIDENTES.md',
        'RUNBOOK_STAGING.md', 'RUNBOOK_ALPHA.md', 'MONITORIZACION_STAGING.md',
        'BACKUP_STAGING_REAL.md', 'DISASTER_RECOVERY_GIMNERA.md',
        'RESTORE_STAGING_REAL.md', 'SMTP_STAGING.md', 'RELEASE_MANIFEST.md',
        'WATCHDOG_EXTERNO.md', 'SEGUNDO_OPERADOR.md', 'CUSTODIA_SECRETOS.md',
        'POLITICA_DATOS_AUDITORIA.md', 'AUDITORIA_OPERATIVA.md',
        'RETENTION_ENGINE.md', 'TRAINING_FOUNDATION.md',
        'RESTAURANTS_ARCHITECTURE_AUDIT.md', 'RESTAURANTS_FOUNDATION.md',
    ];
    $list = releaseGit(array_merge(['ls-tree', '-r', '--name-only', 'HEAD', '--'], $pathspecs), $root);
    $files = array_values(array_filter(preg_split('/\r?\n/', trim($list)) ?: []));
    sort($files, SORT_STRING);
    if ($files === []) {
        throw new RuntimeException('La selección de release está vacía.');
    }
    foreach ($files as $file) {
        if (
            preg_match('#(^|/)(?:\.git|tests?|pruebas|backups?|copias|logs?|sessions?|storage)(/|$)#i', $file)
            || preg_match('/(^|\/)\.env(?!\.(?:example|staging\.example)$)/i', $file)
            || preg_match('/\.(?:zip|bak|pem|key|pfx|p12|sql\.gz|log)$/i', $file)
            || $file === 'instalar.php'
        ) {
            throw new RuntimeException('La selección contiene un archivo prohibido: ' . $file);
        }
    }

    $zipName = 'gimnera_' . $version . '_' . $short . '.zip';
    $zipPath = $outputDir . DIRECTORY_SEPARATOR . $zipName;
    if (file_exists($zipPath)) {
        throw new RuntimeException('El artefacto ya existe; usa un directorio de salida vacío.');
    }
    $archive = releaseCommand(
        array_merge(['git', '-c', 'safe.directory=' . str_replace('\\', '/', $root),
            'archive', '--format=zip', '--output=' . $zipPath, 'HEAD', '--'], $pathspecs),
        $root
    );
    if ($archive['exit'] !== 0 || !is_file($zipPath)) {
        throw new RuntimeException('No se pudo generar el ZIP: ' . trim($archive['stderr']));
    }

    $tarPath = $outputDir . DIRECTORY_SEPARATOR
        . '.gimnera_manifest_' . bin2hex(random_bytes(8)) . '.tar';
    $entries = [];
    try {
        $tarArchive = releaseCommand(
            array_merge(['git', '-c', 'safe.directory=' . str_replace('\\', '/', $root),
                'archive', '--format=tar', '--output=' . $tarPath, 'HEAD', '--'], $pathspecs),
            $root
        );
        if ($tarArchive['exit'] !== 0 || !is_file($tarPath)) {
            throw new RuntimeException(
                'No se pudo generar el TAR temporal de verificación: ' . trim($tarArchive['stderr'])
            );
        }
        $tar = new PharData($tarPath);
        foreach ($files as $file) {
            if (!isset($tar[$file])) {
                throw new RuntimeException('El archivo temporal no contiene: ' . $file);
            }
            $blob = $tar[$file]->getContent();
            $entries[] = [
                'path' => $file,
                'bytes' => strlen($blob),
                'sha256' => hash('sha256', $blob),
            ];
        }
    } finally {
        unset($tar);
        if (is_file($tarPath) && !unlink($tarPath)) {
            throw new RuntimeException('No se pudo eliminar el TAR temporal de verificación.');
        }
    }
    $zipHash = hash_file('sha256', $zipPath);
    $manifest = [
        'schema' => 1,
        'version' => $version,
        'commit' => $head,
        'commit_time' => $commitTime,
        'public_root' => 'public',
        'file_count' => count($entries),
        'zip' => ['name' => $zipName, 'bytes' => filesize($zipPath), 'sha256' => $zipHash],
        'files' => $entries,
    ];
    $manifestPath = $zipPath . '.manifest.json';
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        LOCK_EX
    );
    file_put_contents($zipPath . '.sha256', $zipHash . '  ' . $zipName . "\n", LOCK_EX);
    echo json_encode([
        'status' => 'OK', 'version' => $version, 'commit' => $head,
        'zip' => $zipPath, 'sha256' => $zipHash,
        'bytes' => filesize($zipPath), 'files' => count($entries),
        'manifest' => $manifestPath,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'RELEASE DETENIDA: ' . $e->getMessage() . "\n");
    exit(1);
}
