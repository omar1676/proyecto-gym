<?php

final class AuditUnavailableException extends RuntimeException
{
}

final class AuditPolicy
{
    public const BEST_EFFORT = 'BEST_EFFORT';
    public const REQUIRED = 'REQUIRED';

    /**
     * Clasificación operativa. REQUIRED solo debe usarse dentro de la misma
     * transacción que el efecto de negocio; lanzarlo después del commit
     * produciría un falso fallo y una repetición peligrosa.
     */
    public static function modeFor(string $action): string
    {
        $action = mb_strtolower(trim($action));
        // Exportar/consultar no altera el negocio; aunque el nombre contenga
        // "venta", su traza es telemetría y no debe bloquear la descarga.
        if (str_starts_with($action, 'exportar') || str_starts_with($action, 'consultar')) {
            return self::BEST_EFFORT;
        }
        foreach ([
            'venta', 'caja', 'stock', 'membres', 'cuota', 'cobro', 'recibo',
            'remesa', 'mandato', 'datos bancarios', 'cambio de rol',
            'empleado', 'password_changed', 'password_reset_completed',
        ] as $critical) {
            if (str_contains($action, $critical)) return self::REQUIRED;
        }
        return self::BEST_EFFORT;
    }

    public static function normalize(string $mode): string
    {
        return strtoupper($mode) === self::REQUIRED ? self::REQUIRED : self::BEST_EFFORT;
    }
}
