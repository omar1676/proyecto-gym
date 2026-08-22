<?php

require __DIR__ . '/../_header_admin.php';

$etiquetasPago = [
    'efectivo'      => 'Efectivo',
    'datafono'      => 'Datáfono',
    'transferencia' => 'Transferencia',
];

// Máximos para dimensionar las barras de los rankings.
$maxUnidades = 0;
foreach ($topProductos as $p) {
    $maxUnidades = max($maxUnidades, (int) $p['unidades']);
}
?>
    <main class="flex-1 bg-[#f7f7f8] px-5 py-8 lg:px-8">
        <section class="mx-auto max-w-6xl">

            <div class="pt-4 mb-7">
                <h1 class="text-2xl font-extrabold tracking-tight text-[#111318] sm:text-3xl">Reportes</h1>
                <p class="mt-1.5 text-sm font-medium text-neutral-500">Ventas, cobros reales, deuda, caja, stock y membresías.</p>
            </div>

            <!-- Resumen rápido -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Ventas de hoy</p>
                    <div class="mt-5"><span class="text-3xl font-extrabold leading-none text-[#111318]"><?= number_format($ventasHoy, 2, ',', '.') ?> €</span></div>
                    <p class="mt-2 text-xs text-neutral-500"><?= (int) $numVentasHoy ?> ticket<?= (int) $numVentasHoy === 1 ? '' : 's' ?></p>
                </article>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Ventas del mes</p>
                    <div class="mt-5"><span class="text-3xl font-extrabold leading-none text-[#52525b]"><?= number_format($ventasMes, 2, ',', '.') ?> €</span></div>
                </article>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Cuotas del mes</p>
                    <div class="mt-5"><span class="text-3xl font-extrabold leading-none text-[#111318]"><?= number_format($ingresosMembresias, 2, ',', '.') ?> €</span></div>
                    <p class="mt-2 text-xs text-neutral-500"><?= (int) $membresiasActivas ?> activas · <?= (int) $membresiasVencidas ?> vencidas</p>
                </article>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Valor inventario</p>
                    <div class="mt-5"><span class="text-3xl font-extrabold leading-none text-[#111318]"><?= number_format($valorInventario, 2, ',', '.') ?> €</span></div>
                    <p class="mt-2 text-xs text-neutral-500"><?= count($bajoStock) ?> producto<?= count($bajoStock) === 1 ? '' : 's' ?> bajo mínimo</p>
                </article>
            </div>

            <!-- Filtro de periodo -->
            <div class="mt-8 flex flex-wrap items-end gap-3">
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="action" value="admin_reportes">
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Desde</label>
                    <input type="date" name="desde" value="<?= htmlspecialchars($desde, ENT_QUOTES, 'UTF-8') ?>"
                        class="rounded-xl border border-[#e4e4e7] bg-white px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Hasta</label>
                    <input type="date" name="hasta" value="<?= htmlspecialchars($hasta, ENT_QUOTES, 'UTF-8') ?>"
                        class="rounded-xl border border-[#e4e4e7] bg-white px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <button type="submit"
                    class="rounded-full bg-[#111318] px-5 py-2.5 text-sm font-bold text-white hover:brightness-110 transition">
                    Aplicar
                </button>
            </form>
                <form method="POST" action="index.php?action=admin_exportar_ventas_csv" class="inline">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="desde" value="<?= htmlspecialchars($desde, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="hasta" value="<?= htmlspecialchars($hasta, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit"
                        class="rounded-full border border-[#e4e4e7] px-5 py-2.5 text-sm font-bold text-neutral-500 hover:bg-neutral-50 transition">
                        Exportar ventas CSV
                    </button>
                </form>
            </div>

            <!-- Circuito económico real: no confunde contratos con cobros. -->
            <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-5 py-4 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-widest text-neutral-500">Cobros confirmados</p>
                    <p class="mt-3 text-2xl font-extrabold text-green-700"><?= number_format((float) ($resumenEconomico['cobros_confirmados'] ?? 0), 2, ',', '.') ?> €</p>
                </article>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-5 py-4 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-widest text-neutral-500">Devoluciones</p>
                    <p class="mt-3 text-2xl font-extrabold text-red-700"><?= number_format((float) ($resumenEconomico['devoluciones'] ?? 0), 2, ',', '.') ?> €</p>
                    <p class="mt-1 text-xs text-neutral-500"><?= (int) ($resumenEconomico['num_devoluciones'] ?? 0) ?> recibo(s)</p>
                </article>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-5 py-4 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-widest text-neutral-500">Deuda pendiente</p>
                    <p class="mt-3 text-2xl font-extrabold text-amber-700"><?= number_format((float) ($resumenEconomico['deuda_pendiente'] ?? 0), 2, ',', '.') ?> €</p>
                </article>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-5 py-4 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-widest text-neutral-500">Diferencias de caja</p>
                    <p class="mt-3 text-2xl font-extrabold <?= (float) ($resumenCaja['diferencias'] ?? 0) === 0.0 ? 'text-green-700' : 'text-red-700' ?>"><?= number_format((float) ($resumenCaja['diferencias'] ?? 0), 2, ',', '.') ?> €</p>
                    <p class="mt-1 text-xs text-neutral-500"><?= (int) ($resumenCaja['sesiones_cerradas'] ?? 0) ?> cierre(s)</p>
                </article>
            </div>

            <!-- Totales del periodo -->
            <div class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h3 class="text-lg font-bold text-neutral-700">
                        Periodo <?= date('d/m/Y', strtotime($desde)) ?> — <?= date('d/m/Y', strtotime($hasta)) ?>
                    </h3>
                    <p class="text-2xl font-extrabold text-[#111318]"><?= number_format($totalRango, 2, ',', '.') ?> €</p>
                </div>
                <p class="mt-1 text-sm text-neutral-500"><?= count($ventasRango) ?> venta<?= count($ventasRango) === 1 ? '' : 's' ?> registrada<?= count($ventasRango) === 1 ? '' : 's' ?></p>

                <?php if (!empty($porMetodo)): ?>
                <div class="mt-5 space-y-3">
                    <?php foreach ($porMetodo as $metodo):
                        $pct = $totalRango > 0 ? round(((float) $metodo['importe'] / $totalRango) * 100) : 0;
                    ?>
                    <div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-bold text-neutral-600">
                                <?= $etiquetasPago[$metodo['metodo_pago']] ?? htmlspecialchars($metodo['metodo_pago'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="text-neutral-500">
                                <?= number_format((float) $metodo['importe'], 2, ',', '.') ?> €
                                <span class="text-neutral-500">(<?= $pct ?>%)</span>
                            </span>
                        </div>
                        <div class="mt-1.5 h-2 rounded-full bg-[#f4f4f5]">
                            <div class="h-full rounded-full bg-[#4f46e5]" style="width: <?= max(2, $pct) ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">

                <!-- Productos más vendidos -->
                <div class="rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-bold text-neutral-700 mb-4">Productos más vendidos</h3>
                    <?php if (empty($topProductos)): ?>
                        <p class="text-sm text-neutral-500 italic">No hay ventas en este periodo.</p>
                    <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($topProductos as $prod):
                            $pct = $maxUnidades > 0 ? round(((int) $prod['unidades'] / $maxUnidades) * 100) : 0;
                        ?>
                        <div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="font-medium text-neutral-600 truncate max-w-[60%]">
                                    <?= htmlspecialchars($prod['nombre_producto'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <span class="text-neutral-500">
                                    <?= (int) $prod['unidades'] ?> uds.
                                    <span class="font-bold text-neutral-700"><?= number_format((float) $prod['importe'], 2, ',', '.') ?> €</span>
                                </span>
                            </div>
                            <div class="mt-1.5 h-2 rounded-full bg-[#f4f4f5]">
                                <div class="h-full rounded-full bg-[#404040]" style="width: <?= max(2, $pct) ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Productos bajo stock -->
                <div class="rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-bold text-neutral-700 mb-4">Productos bajo stock</h3>
                    <?php if (empty($bajoStock)): ?>
                        <p class="text-sm text-neutral-500 italic">Todos los productos están por encima de su mínimo.</p>
                    <?php else: ?>
                    <ul class="divide-y divide-[#e4e4e7]">
                        <?php foreach ($bajoStock as $prod): ?>
                        <li class="flex items-center justify-between py-2.5 text-sm">
                            <div class="min-w-0">
                                <span class="block font-medium text-neutral-600 truncate">
                                    <?= htmlspecialchars($prod['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php if (!empty($prod['nombre_categoria'])): ?>
                                <span class="block text-xs text-neutral-500">
                                    <?= htmlspecialchars($prod['nombre_categoria'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <span class="shrink-0 rounded-full px-3 py-1 text-xs font-bold
                                <?= (int) $prod['stock'] === 0 ? 'bg-[#fee2e2] text-[#dc2626]' : 'bg-[#e4e4e7] text-[#52525b]' ?>">
                                <?= (int) $prod['stock'] ?> / mín. <?= (int) $prod['stock_minimo'] ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="mt-4 text-xs text-neutral-500">
                        <a href="index.php?action=admin_productos" class="text-[#111318] font-bold hover:underline">Ir a productos</a>
                        para reponer stock.
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Membresías próximas a vencer -->
            <div class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white shadow-sm overflow-hidden">
                <div class="px-6 pt-6 pb-4">
                    <h3 class="text-lg font-bold text-neutral-700">Membresías que vencen en 30 días</h3>
                </div>
                <?php if (empty($porVencer)): ?>
                    <p class="px-6 pb-6 text-sm text-neutral-500 italic">Ninguna membresía vence en los próximos 30 días.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm min-w-[640px]">
                            <thead class="border-b border-t border-[#e4e4e7] bg-[#f4f4f5]">
                                <tr>
                                    <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Socio</th>
                                    <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Contacto</th>
                                    <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Modalidad</th>
                                    <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Vence</th>
                                    <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500 text-right">Quedan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($porVencer as $socio): $dias = (int) $socio['dias_restantes']; ?>
                                <tr class="border-t border-[#e4e4e7] hover:bg-neutral-50 transition-colors">
                                    <td class="px-6 py-3 font-bold text-neutral-700">
                                        <?= htmlspecialchars($socio['nombre'] . ' ' . $socio['apellidos'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-6 py-3 text-neutral-500">
                                        <span class="block text-xs"><?= htmlspecialchars($socio['email'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!empty($socio['telefono'])): ?>
                                        <span class="block text-xs text-neutral-500"><?= htmlspecialchars($socio['telefono'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-3 text-neutral-500"><?= htmlspecialchars($socio['nombre_tipo'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-6 py-3 text-neutral-500"><?= date('d/m/Y', strtotime($socio['fecha_fin'])) ?></td>
                                    <td class="px-6 py-3 text-right">
                                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold
                                            <?= $dias <= 7 ? 'bg-[#fee2e2] text-[#dc2626]' : 'bg-[#e4e4e7] text-[#52525b]' ?>">
                                            <?= $dias === 0 ? 'Hoy' : $dias . ' día' . ($dias === 1 ? '' : 's') ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </section>
    </main>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
