<?php

final class ClientIp
{
    public static function address(): ?string
    {
        $remote = self::valid($_SERVER['REMOTE_ADDR'] ?? '') ?: null;
        if ($remote === null || !self::isTrustedProxy($remote)) return $remote;

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
            $raw = (string) ($_SERVER[$header] ?? '');
            foreach (explode(',', $raw) as $candidate) {
                $valid = self::valid(trim($candidate));
                if ($valid !== null) return $valid;
            }
        }
        return $remote;
    }

    public static function isTrustedProxy(?string $ip = null): bool
    {
        $ip = $ip ?? self::valid($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === null) return false;
        $trusted = defined('TRUSTED_PROXY_IPS') ? TRUSTED_PROXY_IPS : [];
        return in_array($ip, $trusted, true);
    }

    private static function valid(string $ip): ?string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }
}
