<?php
require_once __DIR__ . '/Csrf.php';
/**
 * Menu — los enlaces del panel según el rol.
 *
 * Vivía dentro de `_header_admin.php`, y como era una función a nivel de
 * archivo, incluir la cabecera dos veces en el mismo proceso reventaba con
 * "Cannot redeclare renderMenuItems()". Eso hacía imposible renderizar varias
 * pantallas seguidas, que es justo lo que hace la prueba de humo.
 *
 * Aquí, como método de una clase con require_once, el problema desaparece.
 */

class Menu
{
    /* Iconos compartidos entre los menús (trazados SVG). */
    private const ICONOS = [
        'inicio'     => 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5.5v-6h-5v6H4a1 1 0 0 1-1-1v-9.5Z',
        'ventas'     => 'M3 3h2l2.4 12h9.8l2.3-8H6.2M9 20a1 1 0 1 0 2 0 1 1 0 0 0-2 0Zm7 0a1 1 0 1 0 2 0 1 1 0 0 0-2 0Z',
        'caja'       => 'M4 6h16v13H4zM4 9h16M15 14h2',
        'socios'     => 'M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4.418 0-8 2.239-8 5v1h16v-1c0-2.761-3.582-5-8-5Z',
        'productos'  => 'M20 7 12 3 4 7m16 0-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
        'membresias' => 'M3 6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6Zm4 4h4M7 14h10',
        'remesas'    => 'M3 10h18M3 6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6Zm4 10h4',
        'personal'   => 'M16 11a3 3 0 1 0-3-3 3 3 0 0 0 3 3Zm-8 1a3.5 3.5 0 1 0-3.5-3.5A3.5 3.5 0 0 0 8 12Zm8 2c-2.8 0-5.2 1.2-6 2.9M8 14c-3.3 0-6 1.6-6 3.5V19h10',
        'sedes'      => 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6M9 11h.01M15 11h.01',
        'reportes'   => 'M4 19h16M7 16V9m5 7V5m5 11v-4',
        'log'        => 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 0 2-2h2a2 2 0 0 0 2 2m-6 9l2 2 4-4',
        'migraciones'=> 'M12 3v12m0 0-4-4m4 4 4-4M5 19h14',
        'empresas'   => 'M3 21h18M5 21V7l7-4 7 4v14M9 10h.01M15 10h.01M9 14h.01M15 14h.01',
        'salir'      => 'M10 17 15 12 10 7M15 12H3m8-9h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6',
    ];

    /**
     * Enlaces de cada rol, en el orden en que se ven.
     * Formato: [acción del router, clave para marcar el activo, etiqueta, icono].
     */
    private static function enlaces(string $rol): array
    {
        $ventas     = ['admin_ventas',     'ventas',     'Ventas',         'ventas'];
        $caja       = ['admin_caja',       'caja',       'Caja',           'caja'];
        $socios     = ['admin_socios',     'socios',     'Socios',         'socios'];
        $inicio     = ['admin',            'inicio',     'Inicio',         'inicio'];
        $productos  = ['admin_productos',  'productos',  'Productos',      'productos'];
        $membresias = ['admin_membresias', 'membresias', 'Membresías',     'membresias'];
        $remesas    = ['admin_remesas',    'remesas',    'Domiciliaciones', 'remesas'];
        $personal   = ['admin_empleados',  'empleados',  'Personal',       'personal'];
        $reportes   = ['admin_reportes',   'reportes',   'Reportes',       'reportes'];
        $historial  = ['admin_log',        'log',        'Historial',      'log'];
        $migraciones= ['admin_importaciones','migraciones','Importaciones','migraciones'];
        $empresas    = ['admin_empresas','empresas','Empresas','empresas'];

        switch ($rol) {
            case 'superadmin':
                return [$empresas, $inicio, $ventas, $caja, $productos, $socios, $membresias, $remesas,
                        ['admin_sedes', 'sedes', 'Sedes', 'sedes'], $personal, $reportes, $historial, $migraciones];
            case 'direccion':
                return [$inicio, $ventas, $caja, $productos, $socios, $membresias, $remesas,
                        ['admin_sedes', 'sedes', 'Sedes', 'sedes'], $personal, $reportes, $historial, $migraciones];
            case 'admin':
                return [$inicio, $ventas, $caja, $productos, $socios, $membresias, $remesas,
                        $personal, $reportes, $historial];
            case 'recepcion':
                // Mostrador: cobrar y atender socios.
                return [$inicio, $ventas, $caja, $socios];
            default:
                // Ningún otro rol entra al panel: solo queda cerrar sesión.
                return [];
        }
    }

    /** Título de la sección del menú según el rol. */
    public static function titulo(string $rol): string
    {
        return in_array($rol, ['superadmin', 'direccion', 'admin', 'recepcion'], true) ? 'Gestión' : 'Mi cuenta';
    }

    public static function render(string $rol, string $paginaActiva): string
    {
        $base  = APP_URL . '/index.php?action=';
        $items = '';

        foreach (self::enlaces($rol) as [$accion, $clave, $etiqueta, $icono]) {
            $items .= self::enlace($base . $accion, $etiqueta, self::ICONOS[$icono], $clave === $paginaActiva);
        }

        // Logout es siempre POST + CSRF, también en el menú lateral. Antes
        // este elemento era un enlace GET que el backend rechazaba y parecía
        // que "Salir" no funcionaba.
        $items .= '<div class="mt-4 border-t border-[#e4e4e7] pt-3">'
                . '<form method="POST" action="' . htmlspecialchars($base . 'logout', ENT_QUOTES, 'UTF-8') . '">'
                . Csrf::field()
                . '<button type="submit" class="flex w-full items-center gap-3 rounded-full px-3 py-2.5 text-left text-sm text-neutral-700 transition hover:bg-neutral-100">'
                . '<svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">'
                . '<path stroke-linecap="round" stroke-linejoin="round" d="' . self::ICONOS['salir'] . '"/>'
                . '</svg><span>Salir</span></button></form></div>';

        return $items;
    }

    private static function enlace(string $url, string $etiqueta, string $icono, bool $activo): string
    {
        $clase = $activo
            ? 'flex items-center gap-3 rounded-full px-3 py-2.5 text-sm font-bold text-[#4f46e5] bg-[#eef2ff]'
            : 'flex items-center gap-3 rounded-full px-3 py-2.5 text-sm text-neutral-700 transition hover:bg-neutral-100';

        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="' . $clase . '">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="' . $icono . '"/>
            </svg>
            <span>' . htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') . '</span>
        </a>' . "\n";
    }
}
