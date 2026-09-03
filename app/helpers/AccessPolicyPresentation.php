<?php

/** Etiquetas operativas para que la UI no exponga enums ni fechas UTC. */
final class AccessPolicyPresentation
{
    public static function state(?string $value): string
    {
        return match (strtoupper(trim((string) $value))) {
            'ALLOWED', 'PERMITIDO' => 'Acceso permitido',
            'TEMPORARY' => 'Acceso temporal',
            'SUSPENDED' => 'Acceso suspendido',
            'DENIED', 'DENEGADO' => 'Acceso denegado',
            'PERMANENT_BLOCK' => 'Bloqueo permanente',
            'BASE_MEMBRESIA' => 'Según membresía',
            default => 'Sin excepción manual',
        };
    }

    public static function reason(?string $value): string
    {
        return match (strtoupper(trim((string) $value))) {
            'TEMPORARY_VISIT' => 'Visita temporal',
            'MANUAL_EXCEPTION' => 'Excepción manual',
            'POLICY_REVIEW' => 'Revisión de acceso',
            'POLICY_DENIED' => 'Acceso denegado manualmente',
            'SAFETY_BLOCK' => 'Bloqueo de seguridad',
            'MANUAL_RESTORE' => 'Acceso restaurado manualmente',
            'MEMBERSHIP_ACTIVE' => 'Membresía activa',
            'MEMBERSHIP_INACTIVE' => 'Sin membresía activa',
            'DEBT_BLOCK' => 'Bloqueo por situación económica',
            default => 'Regla de acceso vigente',
        };
    }

    public static function action(?string $value): string
    {
        return match (strtoupper(trim((string) $value))) {
            'ACCESS_TEMPORARY_GRANTED' => 'Acceso temporal concedido',
            'ACCESS_TEMPORARY_EXTENDED' => 'Acceso temporal ampliado',
            'ACCESS_SUSPENDED' => 'Acceso suspendido',
            'ACCESS_DENIED' => 'Acceso denegado',
            'ACCESS_PERMANENTLY_BLOCKED' => 'Bloqueo permanente aplicado',
            'ACCESS_RESTORED' => 'Acceso restaurado',
            'ACCESS_EXPIRED' => 'Acceso temporal caducado',
            'ACCESS_TEMPORARY_CONVERTED' => 'Acceso temporal convertido en membresía',
            default => 'Política de acceso modificada',
        };
    }

    public static function syncState(?string $value): string
    {
        return match (strtoupper(trim((string) $value))) {
            'DISABLED' => 'Integración física desactivada',
            'PENDING' => 'Pendiente de sincronización',
            'SYNCED' => 'Sincronización lógica completada',
            'FAILED' => 'Error de sincronización',
            default => 'Sin sincronización física',
        };
    }

    public static function dateTime(?string $utc, string $timezone = 'Europe/Madrid'): string
    {
        $utc = trim((string) $utc);
        if ($utc === '') return 'No aplica';
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($timezone))
                ->format('d/m/Y H:i');
        } catch (Throwable) {
            return 'Fecha no disponible';
        }
    }
}
