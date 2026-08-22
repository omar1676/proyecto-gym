<?php

final class AlertPolicy
{
    /** @return array{alert_required:bool,recovered:bool,suppressed_by_cooldown:bool} */
    public static function decide(array $previous, string $status, string $fingerprint, int $now, int $cooldown): array
    {
        $lastAttempted = strtotime((string) ($previous['last_attempted_at_utc'] ?? ''))
            ?: (strtotime((string) ($previous['last_notified_at_utc'] ?? '')) ?: 0);
        $effectiveCooldown = ($previous['last_delivery_result'] ?? '') === 'failed'
            ? min($cooldown, 300)
            : $cooldown;
        $changed = ($previous['fingerprint'] ?? '') !== $fingerprint;
        $recovered = $status === 'OK' && $previous !== [] && ($previous['status'] ?? 'OK') !== 'OK';
        $alertRequired = $status !== 'OK' && ($changed || $now - $lastAttempted >= $effectiveCooldown);
        return [
            'alert_required' => $alertRequired,
            'recovered' => $recovered,
            'suppressed_by_cooldown' => $status !== 'OK' && !$alertRequired,
        ];
    }

    public static function nextState(
        array $previous,
        string $status,
        string $fingerprint,
        int $now,
        bool $deliveryAttempted,
        bool $delivered
    ): array {
        $stamp = gmdate('Y-m-d\TH:i:s\Z', $now);
        $problemChanged = ($previous['fingerprint'] ?? '') !== $fingerprint;
        return [
            'status' => $status,
            'fingerprint' => $fingerprint,
            'last_seen_at_utc' => $stamp,
            'last_detected_at_utc' => $problemChanged
                ? $stamp
                : ($previous['last_detected_at_utc'] ?? null),
            // Solo una llamada real al canal consume la ventana de reintento.
            'last_attempted_at_utc' => $deliveryAttempted
                ? $stamp
                : ($previous['last_attempted_at_utc'] ?? null),
            'last_notified_at_utc' => $delivered
                ? $stamp
                : ($previous['last_notified_at_utc'] ?? null),
            'last_delivery_result' => $deliveryAttempted
                ? ($delivered ? 'delivered' : 'failed')
                : ($previous['last_delivery_result'] ?? null),
        ];
    }
}
