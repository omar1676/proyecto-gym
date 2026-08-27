<?php

final class ReleaseIntegrityVerifier
{
    /** @return array{ok:bool,checked:int,commit:string,version:string,errors:list<string>} */
    public static function verify(string $releaseRoot, string $manifestPath): array
    {
        $errors = [];
        $root = realpath($releaseRoot);
        if ($root === false || !is_dir($root)) {
            return self::result(false, 0, '', '', ['release_root_invalid']);
        }
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            return self::result(false, 0, '', '', ['manifest_missing_or_unsafe']);
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return self::result(false, 0, '', '', ['manifest_invalid_json']);
        }
        if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 1 || !is_array($manifest['files'] ?? null)) {
            return self::result(false, 0, '', '', ['manifest_contract_invalid']);
        }

        $commit = (string) ($manifest['commit'] ?? '');
        $version = (string) ($manifest['version'] ?? '');
        if (preg_match('/^[a-f0-9]{40}$/', $commit) !== 1) {
            $errors[] = 'manifest_commit_invalid';
        }
        if ($version === '' || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+-[a-z0-9.-]+$/i', $version) !== 1) {
            $errors[] = 'manifest_version_invalid';
        }

        $expected = [];
        foreach ($manifest['files'] as $entry) {
            if (!is_array($entry)) {
                $errors[] = 'manifest_file_entry_invalid';
                continue;
            }
            $path = str_replace('\\', '/', (string) ($entry['path'] ?? ''));
            if (!self::safeRelativePath($path) || isset($expected[$path])) {
                $errors[] = 'manifest_path_invalid_or_duplicate';
                continue;
            }
            $bytes = $entry['bytes'] ?? null;
            $sha256 = strtolower((string) ($entry['sha256'] ?? ''));
            if (!is_int($bytes) || $bytes < 0 || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                $errors[] = 'manifest_file_metadata_invalid:' . $path;
                continue;
            }
            $expected[$path] = ['bytes' => $bytes, 'sha256' => $sha256];
        }
        if ($expected === []) {
            $errors[] = 'manifest_empty';
        }

        $checked = 0;
        foreach ($expected as $path => $metadata) {
            $fullPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (!is_file($fullPath) || is_link($fullPath)) {
                $errors[] = 'file_missing_or_unsafe:' . $path;
                continue;
            }
            $checked++;
            $actualBytes = filesize($fullPath);
            $actualHash = hash_file('sha256', $fullPath);
            if ($actualBytes !== $metadata['bytes'] || !hash_equals($metadata['sha256'], (string) $actualHash)) {
                $errors[] = 'file_integrity_mismatch:' . $path;
            }
        }

        $allowedRuntimeLinks = ['.env', 'public/assets/gimnasios', 'public/assets/productos'];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            if ($item->isLink()) {
                if (!in_array($relative, $allowedRuntimeLinks, true) || !self::safeRuntimeLink($item->getPathname())) {
                    $errors[] = 'unexpected_or_unsafe_symlink:' . $relative;
                }
                continue;
            }
            if ($item->isDir()) {
                continue;
            }
            if ($relative === '.gimnera-release-manifest.json') {
                continue;
            }
            if (!isset($expected[$relative])) {
                $errors[] = 'unexpected_file:' . $relative;
            }
        }

        $versionFile = $root . DIRECTORY_SEPARATOR . 'VERSION';
        if (is_file($versionFile) && trim((string) file_get_contents($versionFile)) !== $version) {
            $errors[] = 'version_manifest_mismatch';
        }

        $errors = array_values(array_unique($errors));
        return self::result($errors === [], $checked, $commit, $version, $errors);
    }

    private static function safeRelativePath(string $path): bool
    {
        return $path !== ''
            && !str_starts_with($path, '/')
            && preg_match('#(^|/)\.\.?(?:/|$)#', $path) !== 1
            && !str_contains($path, "\0");
    }

    private static function safeRuntimeLink(string $path): bool
    {
        $target = readlink($path);
        if (!is_string($target)) {
            return false;
        }
        $target = str_replace('\\', '/', $target);
        return preg_match('#^/var/www/[A-Za-z0-9._-]+/shared(?:/|$)#', $target) === 1;
    }

    /** @param list<string> $errors
     *  @return array{ok:bool,checked:int,commit:string,version:string,errors:list<string>}
     */
    private static function result(bool $ok, int $checked, string $commit, string $version, array $errors): array
    {
        return compact('ok', 'checked', 'commit', 'version', 'errors');
    }
}
