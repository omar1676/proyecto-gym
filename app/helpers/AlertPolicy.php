<?php

final class AlertPolicy
{
    /** @return array{alert_required:bool,recovered:bool,suppressed_by_cooldown:bool} */
    public static function decide(array $previous, string $status, string $fingerprint, int $now, int $cooldown): array
    {
        $lastAttempted = strtotime((string) ($previous['last_attempted_at_utc'] ?? ''))
            ?: (strtotime((string) ($previous['last_notified_at_utc'] ?? '')) ?: 0);
        $changed = ($previous['fingerprint'] ?? '') !== $fingerprint;
        $recovered = $status === 'OK' && $previous !== [] && ($previous['status'] ?? 'OK') !== 'OK';
        $alertRequired = $status !== 'OK' && ($changed || $now - $lastAttempted >= $cooldown);
        return [
            'alert_required' => $alertRequired,
            'recovered' => $recovered,
            'suppressed_by_cooldown' => $status !== 'OK' && !$alertRequired,
        ];
    }
}
