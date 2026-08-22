<?php
$pageTitle    = $pageTitle    ?? 'Panel Admin';
$paginaActiva = $paginaActiva ?? '';

// Los enlaces del menú viven en un helper, no aquí: como función suelta dentro
// de esta vista, incluir la cabecera dos veces en el mismo proceso reventaba.
require_once __DIR__ . '/../helpers/Menu.php';

$rolMenu      = $_SESSION['usuario_rol'] ?? '';
$nombreSesion = htmlspecialchars($_SESSION['usuario_nombre_real'] ?? $_SESSION['usuario_nombre'] ?? '', ENT_QUOTES, 'UTF-8');
// Todo el personal usa la misma pantalla de perfil.
$perfilUrl = APP_URL . '/index.php?action=perfil';
$nombreGimnasio = defined('APP_NOMBRE') ? APP_NOMBRE : 'Gimnasio';

// Logo de la cabecera: primero el de la sede en la que se ha entrado y, si no
// tiene, el de la instalación (APP_LOGO). Los logos de cliente suelen ser negros
// sobre transparente, así que van sobre una placa blanca; sobre la barra oscura
// no se verían. Sin ninguno de los dos queda el icono de siempre.
// El archivo se comprueba en disco: el logo guardado en la sesión puede haberse
// quitado desde Sedes → Marca y no queremos una imagen rota en la barra.
$logoCabecera = null;
$logoSede     = basename(trim((string) ($_SESSION['gimnasio_logo'] ?? '')));
$rutaPublica  = __DIR__ . '/../../public/assets/';

if ($logoSede !== '' && is_file($rutaPublica . 'gimnasios/' . $logoSede)) {
    $logoCabecera = 'assets/gimnasios/' . rawurlencode($logoSede);
} elseif (defined('APP_LOGO') && APP_LOGO !== '' && is_file($rutaPublica . 'marca/' . APP_LOGO)) {
    $logoCabecera = 'assets/marca/' . rawurlencode(APP_LOGO);
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($nombreGimnasio, ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        #sidebar-mobile { transform: translateX(-100%); transition: transform 0.25s ease; }
        #sidebar-mobile.open { transform: translateX(0); }
        #sidebar-overlay { display: none; }
        #sidebar-overlay.open { display: block; }

        /* Red de seguridad responsive (no depende de Tailwind) */
        .js-hamburguesa-admin { display: flex; }
        .js-nav-central-admin { display: none; }
        .js-aside-desktop     { display: none; }
        .js-logo-admin        { flex: 1 1 auto; display: flex; justify-content: center; }

        @media (min-width: 1024px) {
            .js-hamburguesa-admin { display: none !important; }
            .js-nav-central-admin { display: flex !important; }
            .js-aside-desktop     { display: block !important; }
            .js-logo-admin        { flex: 0 0 auto !important; justify-content: flex-start !important; }
            #sidebar-mobile       { display: none !important; }
            #sidebar-overlay      { display: none !important; }
        }
        @media (max-width: 1023px) {
            #sidebar-mobile  { position: fixed; top: 0; left: 0; bottom: 0; width: 220px; z-index: 50; background: #fff; overflow-y: auto; box-shadow: 4px 0 24px rgba(0,0,0,.18); }
            #sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 45; }
        }
    </style>
</head>

<body class="flex min-h-screen flex-col bg-[#f7f7f8] text-neutral-800">

<!-- HEADER -->
<header class="flex h-[60px] items-center justify-between bg-[#111318] px-4 text-white z-40 relative">

    <!-- Hamburguesa (móvil) -->
    <button id="btn-menu" type="button" onclick="toggleSidebar()" aria-label="Abrir menú" aria-controls="sidebar-mobile" aria-expanded="false"
        class="js-hamburguesa-admin flex-col gap-1.5 p-2 lg:hidden">
        <span class="block h-0.5 w-5 bg-white rounded"></span>
        <span class="block h-0.5 w-5 bg-white rounded"></span>
        <span class="block h-0.5 w-5 bg-white rounded"></span>
    </button>

    <!-- Logo / nombre del gimnasio (el archivo se elige en APP_LOGO o en
         Sedes → Marca; ver el cálculo de $logoCabecera arriba) -->
    <div class="js-logo-admin flex flex-1 justify-center lg:justify-start">
        <a href="<?= APP_URL ?>/index.php?action=admin"
           class="flex items-center gap-2 text-white">
            <?php if ($logoCabecera): ?>
            <span class="flex items-center rounded-lg bg-white px-2.5 py-1">
                <img src="<?= htmlspecialchars($logoCabecera, ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars($nombreGimnasio, ENT_QUOTES, 'UTF-8') ?>"
                     class="h-9 w-auto max-w-[150px] object-contain">
            </span>
            <?php else: ?>
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 6.5v11M17.5 6.5v11M3 9v6M21 9v6M6.5 12h11"/>
            </svg>
            <span class="text-lg font-extrabold tracking-tight"><?= htmlspecialchars($nombreGimnasio, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </a>
        <?php if (in_array($rolMenu, ['superadmin', 'direccion'], true)):
            // Dirección elige una sede de su empresa o trabaja con todas.
            require_once __DIR__ . '/../helpers/Csrf.php';
            require_once __DIR__ . '/../models/GimnasioModel.php';
            require_once __DIR__ . '/../helpers/TenantContext.php';
            $ctxMenu = TenantContext::desdeSesion();
            $sedesMenu  = (new GimnasioModel($ctxMenu->empresaId()))->listarActivas();
            $sedeActiva = (int) ($_SESSION['gimnasio_activo'] ?? 0);
        ?>
        <form method="POST" action="<?= APP_URL ?>/index.php?action=admin_sede_activa"
              class="ml-2 block max-w-[145px] sm:ml-3 sm:max-w-none">
            <?= Csrf::field() ?>
            <input type="hidden" name="volver_a" value="<?= htmlspecialchars(preg_replace('/[^a-z_]/', '', $_GET['action'] ?? 'admin'), ENT_QUOTES, 'UTF-8') ?>">
            <?php if (($_GET['action'] ?? '') === 'admin_socios'): ?>
            <input type="hidden" name="volver_buscar" value="<?= htmlspecialchars((string) ($_GET['buscar'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="volver_pagina" value="<?= max(1, (int) ($_GET['pagina'] ?? 1)) ?>">
            <?php endif; ?>
            <label for="selector-sede-activa" class="sr-only">Sede activa</label>
            <select id="selector-sede-activa" name="id_gimnasio" onchange="this.form.submit()"
                class="max-w-full rounded-full border border-white/30 bg-white/15 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-white focus:outline-none sm:px-3 sm:text-[11px]">
                <option value="0" class="text-neutral-800" <?= $sedeActiva === 0 ? 'selected' : '' ?>>Todas las sedes</option>
                <?php foreach ($sedesMenu as $sm): ?>
                <option value="<?= (int) $sm['id_gimnasio'] ?>" class="text-neutral-800"
                    <?= $sedeActiva === (int) $sm['id_gimnasio'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($sm['nombre'], ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php elseif (!empty($_SESSION['gimnasio_nombre'])): ?>
        <span class="ml-3 hidden sm:inline-block rounded-full bg-white/15 px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-white">
            <?= htmlspecialchars($_SESSION['gimnasio_nombre'], ENT_QUOTES, 'UTF-8') ?>
        </span>
        <?php endif; ?>
    </div>

    <!-- Nav central (solo desktop) -->
    <nav class="js-nav-central-admin hidden lg:flex flex-none items-center justify-center">
        <ul class="flex items-center gap-8">
            <li><a href="<?= APP_URL ?>/index.php?action=admin_ventas" class="group flex flex-col items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="mb-1 size-[22px] text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l2.4 12h9.8l2.3-8H6.2M9 20a1 1 0 1 0 2 0 1 1 0 0 0-2 0Zm7 0a1 1 0 1 0 2 0 1 1 0 0 0-2 0Z"/>
                </svg>
                <span class="text-[11px] font-bold text-white group-hover:text-white/70">Nueva venta</span>
            </a></li>
            <li><a href="<?= APP_URL ?>/index.php?action=admin_socios" class="group flex flex-col items-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="mb-1 size-[22px] text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5Z"/>
                </svg>
                <span class="text-[11px] font-bold text-white group-hover:text-white/70">Socios</span>
            </a></li>
        </ul>
    </nav>

    <!-- Perfil + Cerrar sesión -->
    <div class="flex items-center gap-2">
        <?php if (!empty($nombreSesion)): ?>
        <a href="<?= $perfilUrl ?>" class="flex items-center gap-2 bg-white/20 hover:bg-white/30 transition rounded-full px-2.5 py-1.5">
            <?php if (!empty($_SESSION['usuario_foto'])): ?>
            <img src="<?= APP_URL ?>/index.php?action=media_foto&amp;id=<?= (int) ($_SESSION['usuario_id'] ?? 0) ?>"
                 class="w-7 h-7 rounded-full object-cover" alt="Foto perfil">
            <?php else: ?>
            <div class="w-7 h-7 rounded-full bg-white flex items-center justify-center text-[#111318] font-bold text-sm">
                <?= strtoupper(substr($nombreSesion, 0, 1)) ?>
            </div>
            <?php endif; ?>
            <span class="text-xs font-bold text-white hidden sm:block"><?= $nombreSesion ?></span>
        </a>
        <!-- Salir devuelve al acceso del gimnasio, no al de la plataforma:
             el relevo de turno no debería obligar a reabrir el local. -->
        <form method="POST" action="<?= APP_URL ?>/index.php?action=logout" class="inline-flex">
            <?= Csrf::field() ?>
        <button type="submit" title="Cerrar sesión y dejar paso a otro usuario" aria-label="Cerrar sesión"
            class="flex items-center gap-1.5 bg-white/20 hover:bg-white/30 transition rounded-full px-2.5 py-1.5 text-xs font-bold text-white">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 17 15 12 10 7M15 12H3m8-9h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>
            </svg>
            <span class="hidden sm:block">Salir</span>
        </button>
        </form>
        <?php endif; ?>
    </div>
</header>

<!-- OVERLAY móvil -->
<div id="sidebar-overlay" onclick="toggleSidebar()"
    class="fixed inset-0 bg-black/40 z-40 lg:hidden"></div>

<!-- SIDEBAR MÓVIL (slide-in) + DESKTOP (fijo) -->
<div id="sidebar-mobile"
    class="fixed top-0 left-0 h-full w-[220px] bg-white shadow-xl z-50 lg:hidden overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-4 border-b border-[#e4e4e7]">
        <span class="text-sm font-bold text-neutral-700"><?= Menu::titulo($rolMenu) ?></span>
        <button type="button" onclick="toggleSidebar()" aria-label="Cerrar menú" class="text-neutral-500 hover:text-neutral-700">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>
    <nav class="px-3 py-4 space-y-1">
        <?= Menu::render($rolMenu, $paginaActiva) ?>
    </nav>
</div>

<!-- LAYOUT PRINCIPAL -->
<div class="flex flex-1">

<!-- SIDEBAR DESKTOP -->
<aside class="js-aside-desktop hidden lg:block w-[200px] min-h-screen border-r border-[#e4e4e7] bg-white shrink-0">
    <div class="flex h-full flex-col px-3 py-5">
        <div>
            <p class="mb-2 px-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-neutral-500">
                <?= Menu::titulo($rolMenu) ?>
            </p>
            <nav class="space-y-1">
                <?= Menu::render($rolMenu, $paginaActiva) ?>
            </nav>
        </div>
    </div>
</aside>


<script>
function toggleSidebar() {
    var sidebar  = document.getElementById('sidebar-mobile');
    var overlay  = document.getElementById('sidebar-overlay');
    var boton    = document.getElementById('btn-menu');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('open');
    if (boton) boton.setAttribute('aria-expanded', sidebar.classList.contains('open') ? 'true' : 'false');
}
</script>
