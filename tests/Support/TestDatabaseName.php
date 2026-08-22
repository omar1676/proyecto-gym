<?php

final class TestDatabaseName
{
    public static function generate(string $base, string $suite, string $runId): string
    {
        $suite = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', trim($suite)));
        $suite = trim($suite, '_') ?: 'suite';
        $suite = substr($suite, 0, 18);
        $runId = strtolower((string) preg_replace('/[^a-f0-9]/i', '', $runId));
        $runId = str_pad(substr($runId, 0, 12), 12, '0');
        $hash = substr(hash('sha256', strtolower($base)), 0, 8);
        $name = 'gimnera_f211_test_' . $suite . '_' . $hash . '_' . $runId;
        if (!self::isManaged($name)) {
            throw new RuntimeException('No se pudo construir un nombre temporal seguro.');
        }
        return $name;
    }

    public static function isManaged(string $name): bool
    {
        return strlen($name) <= 64
            && preg_match('/^gimnera_f211_test_[a-z0-9_]{1,18}_[a-f0-9]{8}_[a-f0-9]{12}$/', $name) === 1;
    }
}
