<?php

/** Matriz central de permisos del panel. No confía en roles enviados por el cliente. */
final class Authorization
{
    private const PERMISOS = [
        'superadmin' => ['*'],
        'direccion' => [
            'dashboard.view', 'socios.view', 'socios.create', 'socios.edit',
            'membresias.renew', 'membresias.catalog.manage', 'ventas.view',
            'ventas.create', 'ventas.cancel', 'productos.manage', 'stock.manage',
            'informes.view', 'informes.export', 'empleados.manage', 'sedes.manage',
            'auditoria.view', 'remesas.manage', 'mandatos.create', 'config.manage',
            'migrations.manage', 'caja.view', 'caja.operate', 'caja.adjust',
        ],
        'admin' => [
            'dashboard.view', 'socios.view', 'socios.create', 'socios.edit',
            'membresias.renew', 'membresias.catalog.manage', 'ventas.view',
            'ventas.create', 'ventas.cancel', 'productos.manage', 'stock.manage',
            'informes.view', 'informes.export', 'empleados.manage',
            'auditoria.view', 'remesas.manage', 'mandatos.create',
            'caja.view', 'caja.operate', 'caja.adjust',
        ],
        'recepcion' => [
            'dashboard.view', 'socios.view', 'socios.create', 'socios.edit',
            'membresias.renew', 'ventas.view', 'ventas.create', 'mandatos.create',
            'caja.view', 'caja.operate',
        ],
        // No hay portal de socio todavía. Esta capacidad deja explícita la
        // regla que deberá usarlo: solo recursos cuyo propietario sea él mismo.
        'socio' => ['propio.view'],
    ];

    public static function can(string $rol, string $permiso): bool
    {
        $asignados = self::PERMISOS[$rol] ?? [];
        return in_array('*', $asignados, true) || in_array($permiso, $asignados, true);
    }

    public static function canOwn(string $rol, string $permiso, int $usuarioActual, int $propietario): bool
    {
        return self::can($rol, $permiso) && $usuarioActual > 0 && $usuarioActual === $propietario;
    }
}
