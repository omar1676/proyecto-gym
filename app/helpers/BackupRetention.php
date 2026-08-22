<?php

final class BackupRetention
{
    /** @param string[] $files */
    public static function verifiedSetFiles(array $files): bool
    {
        $files = array_values(array_filter(array_map('trim', $files)));
        $setJson = array_values(array_filter(
            $files,
            static fn(string $file): bool => str_starts_with($file, 'backup_set_')
                && str_ends_with($file, '.json')
                && !str_ends_with($file, '.manifest.json')
        ));
        return count($files) >= 9
            && count($setJson) === 1
            && in_array($setJson[0] . '.sha256', $files, true)
            && in_array($setJson[0] . '.manifest.json', $files, true);
    }

    /**
     * @param array<int,array{name:string,timestamp:int,verified:bool}> $items
     * @return array{keep:string[],delete:string[],ignored:string[]}
     */
    public static function plan(array $items, int $daily = 7, int $weekly = 4, int $monthly = 6): array
    {
        usort($items, static fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);
        $valid = array_values(array_filter(
            $items,
            static fn(array $item): bool => $item['verified'] && $item['timestamp'] > 0
        ));
        $ignored = array_values(array_map(
            static fn(array $item): string => $item['name'],
            array_filter($items, static fn(array $item): bool => !$item['verified'] || $item['timestamp'] <= 0)
        ));
        if (count($valid) < 2) {
            return ['keep' => array_column($valid, 'name'), 'delete' => [], 'ignored' => $ignored];
        }
        $keep = [];
        foreach ([['Y-m-d', $daily], ['o-W', $weekly], ['Y-m', $monthly]] as [$format, $limit]) {
            $buckets = [];
            foreach ($valid as $item) {
                $bucket = gmdate($format, $item['timestamp']);
                if (!isset($buckets[$bucket]) && count($buckets) < max(0, $limit)) {
                    $buckets[$bucket] = true;
                    $keep[$item['name']] = true;
                }
            }
        }
        $keep[$valid[0]['name']] = true;
        return [
            'keep' => array_values(array_keys($keep)),
            'delete' => array_values(array_map(
                static fn(array $item): string => $item['name'],
                array_filter($valid, static fn(array $item): bool => !isset($keep[$item['name']]))
            )),
            'ignored' => $ignored,
        ];
    }

    public static function timestampFromSetName(string $name): ?int
    {
        if (!preg_match(
            '/^backup_set_(\d{4}-\d{2}-\d{2})_(\d{6})(?:_\d{6})?Z(?:_[a-f0-9]{8,64})?$/i',
            $name,
            $match
        )) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d_His', $match[1] . '_' . $match[2], new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        return $date->getTimestamp();
    }
}
