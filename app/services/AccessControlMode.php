<?php

/** Modos seguros de integración. Disabled es siempre el fallback. */
final class AccessControlMode
{
    public const DISABLED = 'disabled';
    public const SHADOW = 'shadow';
    public const ACTIVE = 'active';

    public static function resolve(string $configured, bool $activeConfirmed): string
    {
        $configured = strtolower(trim($configured));
        if (!in_array($configured, [self::DISABLED, self::SHADOW, self::ACTIVE], true)) {
            return self::DISABLED;
        }
        if ($configured === self::ACTIVE && !$activeConfirmed) {
            return self::DISABLED;
        }
        return $configured;
    }
}
