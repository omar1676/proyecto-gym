<?php

require __DIR__ . '/../_header_admin.php';

$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'admin';
?>

    <main class="flex-1 bg-[#f7f7f8] px-5 py-8 lg:px-8">
        <section class="mx-auto max-w-6xl">

            <div class="flex flex-col gap-3 pt-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-[#111318] sm:text-3xl">Panel de Control</h1>
                    <p class="mt-1.5 text-sm font-medium text-neutral-500 sm:text-[15px]">
                        Bienvenido al sistema de gestión de <?= htmlspecialchars($nombreGimnasio, ENT_QUOTES, 'UTF-8') ?>.
                    </p>
                </div>
                <p class="pt-1 text-right text-sm font-medium text-neutral-500 sm:text-[15px]">
                    <?= date('d/m/Y') ?>
                </p>
            </div>

            <!-- Tarjetas de estadísticas -->
            <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-[0_1px_3px_rgba(17,19,24,0.06)]">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Ventas de hoy</p>
                    <div class="mt-5 flex items-end gap-3">
                        <span class="text-4xl font-extrabold leading-none text-[#111318]"><?= number_format($ventasHoy, 2, ',', '.') ?> €</span>
                    </div>
                    <p class="mt-2 text-xs font-medium text-neutral-500"><?= (int) $numVentasHoy ?> ticket<?= (int) $numVentasHoy === 1 ? '' : 's' ?></p>
                    <div class="mt-4 h-1.5 rounded-full bg-[#e4e4e7]">
                        <div class="h-full w-[90%] rounded-full bg-[#4f46e5]"></div>
                    </div>
                </article>

                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-[0_1px_3px_rgba(17,19,24,0.06)]">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Ventas del mes</p>
                    <div class="mt-5 flex items-end gap-3">
                        <span class="text-4xl font-extrabold leading-none text-[#52525b]"><?= number_format($ventasMes, 2, ',', '.') ?> €</span>
                    </div>
                    <div class="mt-6 h-1.5 rounded-full bg-[#e4e4e7]">
                        <div class="h-full w-[75%] rounded-full bg-[#4f46e5]"></div>
                    </div>
                </article>

                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-[0_1px_3px_rgba(17,19,24,0.06)]">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Socios con membresía</p>
                    <div class="mt-5 flex items-end gap-3">
                        <span class="text-4xl font-extrabold leading-none text-[#111318]"><?= (int) $membresiasActivas ?></span>
                        <span class="text-sm font-medium text-neutral-500 ml-1">/ <?= (int) $totalSocios ?></span>
                    </div>
                    <?php $pctSocios = $totalSocios > 0 ? round(($membresiasActivas / $totalSocios) * 100) : 0; ?>
                    <div class="mt-6 h-1.5 rounded-full bg-[#e4e4e7]">
                        <div class="h-full rounded-full bg-[#4f46e5]" style="width: <?= max(2, $pctSocios) ?>%"></div>
                    </div>
                </article>

                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-[0_1px_3px_rgba(17,19,24,0.06)]">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Productos bajo stock</p>
                    <div class="mt-5 flex items-end gap-3">
                        <span class="text-4xl font-extrabold leading-none <?= $bajoStock > 0 ? 'text-[#111318]' : 'text-neutral-300' ?>"><?= (int) $bajoStock ?></span>
                    </div>
                    <div class="mt-6 h-1.5 rounded-full bg-[#e4e4e7]">
                        <div class="h-full w-[40%] rounded-full bg-[#4f46e5]"></div>
                    </div>
                </article>
            </div>

            <!-- Últimas ventas y membresías por vencer -->
            <div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-[0_1px_3px_rgba(17,19,24,0.06)]">
                    <h3 class="text-lg font-bold text-neutral-700 mb-4">Últimas ventas de hoy</h3>
                    <?php if (empty($ultimasVentas)): ?>
                        <p class="text-sm text-neutral-500 italic">Todavía no se ha registrado ninguna venta hoy.</p>
                    <?php else: ?>
                    <ul class="space-y-4">
                        <?php foreach ($ultimasVentas as $venta): ?>
                        <li class="flex items-center gap-4 text-sm">
                            <div class="w-2 h-2 rounded-full bg-[#111318] shrink-0"></div>
                            <p class="text-neutral-600 truncate">
                                <?= htmlspecialchars($venta['detalle'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <span class="ml-auto shrink-0 font-bold text-neutral-700">
                                <?= number_format((float) $venta['total'], 2, ',', '.') ?> €
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>

                <div class="rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-[0_1px_3px_rgba(17,19,24,0.06)]">
                    <h3 class="text-lg font-bold text-neutral-700 mb-4">Membresías próximas a vencer</h3>
                    <?php if (empty($porVencer)): ?>
                        <p class="text-sm text-neutral-500 italic">Ninguna membresía vence en los próximos 15 días.</p>
                    <?php else: ?>
                    <ul class="space-y-4">
                        <?php foreach (array_slice($porVencer, 0, 5) as $socioVence): ?>
                        <li class="flex items-center gap-4 text-sm">
                            <div class="w-2 h-2 rounded-full bg-[#111318] shrink-0"></div>
                            <p class="text-neutral-600 truncate">
                                <?= htmlspecialchars($socioVence['nombre'] . ' ' . $socioVence['apellidos'], ENT_QUOTES, 'UTF-8') ?>
                                <span class="text-neutral-500">— <?= htmlspecialchars($socioVence['nombre_tipo'], ENT_QUOTES, 'UTF-8') ?></span>
                            </p>
                            <span class="ml-auto shrink-0 text-xs font-bold <?= (int) $socioVence['dias_restantes'] <= 3 ? 'text-[#dc2626]' : 'text-neutral-500' ?>">
                                <?= (int) $socioVence['dias_restantes'] === 0 ? 'Hoy' : (int) $socioVence['dias_restantes'] . ' días' ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Accesos rápidos -->
            <div class="mt-8 rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-[0_1px_3px_rgba(17,19,24,0.06)]">
                <h3 class="text-lg font-bold text-neutral-700 mb-4">Accesos Rápidos</h3>
                <div class="grid grid-cols-2 gap-4 <?= $esAdmin ? 'md:grid-cols-4' : 'md:grid-cols-2' ?>">
                    <a href="<?= APP_URL ?>/index.php?action=admin_ventas" class="flex flex-col items-center justify-center p-4 rounded-xl border border-[#e4e4e7] hover:bg-neutral-50 transition">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-[#111318] mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l2.4 12h9.8l2.3-8H6.2M9 20a1 1 0 1 0 2 0 1 1 0 0 0-2 0Zm7 0a1 1 0 1 0 2 0 1 1 0 0 0-2 0Z"/>
                        </svg>
                        <span class="text-xs font-bold text-neutral-600 uppercase">Nueva venta</span>
                    </a>
                    <a href="<?= APP_URL ?>/index.php?action=admin_socios" class="flex flex-col items-center justify-center p-4 rounded-xl border border-[#e4e4e7] hover:bg-neutral-50 transition">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-[#52525b] mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5Z"/>
                        </svg>
                        <span class="text-xs font-bold text-neutral-600 uppercase">Socios</span>
                    </a>
                    <?php if ($esAdmin): ?>
                    <a href="<?= APP_URL ?>/index.php?action=admin_productos" class="flex flex-col items-center justify-center p-4 rounded-xl border border-[#e4e4e7] hover:bg-neutral-50 transition">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-[#111318] mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7 12 3 4 7m16 0-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        <span class="text-xs font-bold text-neutral-600 uppercase">Productos</span>
                    </a>
                    <a href="<?= APP_URL ?>/index.php?action=admin_reportes" class="flex flex-col items-center justify-center p-4 rounded-xl border border-[#e4e4e7] hover:bg-neutral-50 transition">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-[#52525b] mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 19h16M7 16V9m5 7V5m5 11v-4"/>
                        </svg>
                        <span class="text-xs font-bold text-neutral-600 uppercase">Reportes</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

        </section>
    </main>

</div>
<?php require __DIR__ . '/../_footer.php'; ?>
