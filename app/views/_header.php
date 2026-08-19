<?php
$nombreCabecera = defined('APP_NOMBRE') ? APP_NOMBRE : 'Gimnasio';

// Mismo criterio que en _header_admin.php: el logo de la sede si se ha entrado
// en una, si no el de la instalación, y siempre sobre placa blanca porque la
// barra es negra y los logos de cliente suelen serlo también.
$logoCabeceraH = null;
$logoSedeH     = basename(trim((string) ($_SESSION['gimnasio_logo'] ?? '')));
$rutaPublicaH  = __DIR__ . '/../../public/assets/';

if ($logoSedeH !== '' && is_file($rutaPublicaH . 'gimnasios/' . $logoSedeH)) {
    $logoCabeceraH = 'assets/gimnasios/' . rawurlencode($logoSedeH);
} elseif (defined('APP_LOGO') && APP_LOGO !== '' && is_file($rutaPublicaH . 'marca/' . APP_LOGO)) {
    $logoCabeceraH = 'assets/marca/' . rawurlencode(APP_LOGO);
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Acceso', ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($nombreCabecera, ENT_QUOTES, 'UTF-8') ?>
    </title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="flex flex-col min-h-screen">

    <header style="background: #111111;"
        class="relative flex items-center justify-between px-6 md:px-10 h-[70px] z-50">

        <!-- Logo / nombre del gimnasio (el archivo se elige en APP_LOGO o en
             Sedes → Marca; ver el cálculo de $logoCabeceraH arriba) -->
        <div class="flex-shrink-0">
            <a href="index.php?action=login" class="flex items-center gap-2 px-2 text-white no-underline">
                <?php if ($logoCabeceraH): ?>
                <span class="flex items-center rounded-lg bg-white px-3 py-1.5">
                    <img src="<?= htmlspecialchars($logoCabeceraH, ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($nombreCabecera, ENT_QUOTES, 'UTF-8') ?>"
                         class="h-10 w-auto max-w-[170px] object-contain">
                </span>
                <?php else: ?>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 6.5v11M17.5 6.5v11M3 9v6M21 9v6M6.5 12h11"/>
                </svg>
                <span class="font-['Outfit'] text-xl font-bold tracking-tight">
                    <?= htmlspecialchars($nombreCabecera, ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php endif; ?>
            </a>
        </div>

        <div class="flex items-center gap-3 shrink-0">
            <?php
            $rolSesionH    = $_SESSION['usuario_rol'] ?? '';
            $nombreSesionH = htmlspecialchars($_SESSION['usuario_nombre_real'] ?? $_SESSION['usuario_nombre'] ?? '', ENT_QUOTES, 'UTF-8');
            // Solo el personal tiene perfil: los socios ya no acceden al sistema.
            if (in_array($rolSesionH, ['superadmin', 'direccion', 'admin', 'recepcion'], true)) {
                $perfilUrlH = APP_URL . '/index.php?action=perfil';
            } else {
                $perfilUrlH = null;
            }
            ?>
            <?php if (!empty($perfilUrlH) && !empty($nombreSesionH)): ?>
                <a href="<?= $perfilUrlH ?>"
                    class="flex items-center gap-2 px-[14px] py-[9px] rounded-[10px] bg-white text-[#000000] text-[13.5px] font-bold no-underline whitespace-nowrap hover:opacity-90 transition-all">
                    <?php if (!empty($_SESSION['usuario_foto'])): ?>
                    <img src="assets/fotos/<?= htmlspecialchars($_SESSION['usuario_foto'], ENT_QUOTES) ?>"
                         class="w-6 h-6 rounded-full object-cover" alt="Foto perfil">
                    <?php else: ?>
                    <div class="w-6 h-6 rounded-full bg-[#111318] flex items-center justify-center text-white font-bold text-xs">
                        <?= strtoupper(substr($nombreSesionH, 0, 1)) ?>
                    </div>
                    <?php endif; ?>
                    <span class="hidden sm:inline">Ver perfil</span>
                </a>
                <form method="POST" action="<?= APP_URL ?>/index.php?action=logout" class="inline-flex">
                    <?= Csrf::field() ?>
                <button type="submit"
                    class="flex items-center gap-2 px-[14px] py-[9px] rounded-[10px] bg-white/20 border border-white/40 text-white text-[13.5px] font-bold no-underline whitespace-nowrap hover:bg-white/30 transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 17 15 12 10 7M15 12H3m8-9h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>
                    </svg>
                    <span class="hidden sm:inline">Cerrar sesión</span>
                </button>
                </form>
            <?php else: ?>
                <a href="<?= APP_URL ?>/index.php?action=login"
                    class="flex items-center gap-2 px-[14px] py-[9px] rounded-[10px] bg-white text-[#000000] text-[13.5px] font-bold no-underline whitespace-nowrap hover:opacity-90 transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M20 21a8 8 0 1 0-16 0"/>
                    </svg>
                    <span class="hidden sm:inline">Iniciar sesión</span>
                </a>
            <?php endif; ?>

        </div>

    </header>
