<?php

require_once __DIR__ . '/../../helpers/Csrf.php';
require_once __DIR__ . '/../../helpers/Iban.php';
require __DIR__ . '/../_header_admin.php';

$etiquetasPago = [
    'efectivo'      => 'Efectivo',
    'datafono'      => 'Datáfono',
    'transferencia' => 'Domiciliación (SEPA)',
];

$etiquetasEstado = [
    'activa'          => ['Activa',            'bg-[#dcfce7] text-[#15803d]'],
    'prueba'          => ['Prueba · sin pagar', 'bg-[#fef3c7] text-[#b45309]'],
    'prueba_caducada' => ['Prueba caducada',   'bg-[#fee2e2] text-[#dc2626]'],
    'vencida'         => ['Vencida',           'bg-[#fee2e2] text-[#dc2626]'],
    'sin_membresia'   => ['Sin membresía',     'bg-neutral-100 text-neutral-500'],
];

$paginaActual = (int) ($paginaActual ?? 1);
$totalPaginas = (int) ($totalPaginas ?? 1);
$totalResultados = (int) ($totalResultados ?? count($socios ?? []));
$porPagina = (int) ($porPagina ?? 50);
$urlPagina = static function (int $pagina) use ($busqueda): string {
    $parametros = ['action' => 'admin_socios', 'pagina' => max(1, $pagina)];
    if ($busqueda !== '') $parametros['buscar'] = $busqueda;
    return 'index.php?' . http_build_query($parametros);
};
?>
    <main class="flex-1 bg-[#f7f7f8] px-5 py-8 lg:px-8">
        <section class="mx-auto max-w-6xl">

            <div class="flex flex-col gap-3 pt-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-[#111318] sm:text-3xl">Socios</h1>
                    <p class="mt-1.5 text-sm font-medium text-neutral-500">
                        Altas, membresías y fechas de vencimiento.
                    </p>
                </div>
                <button type="button" onclick="abrirModal('modal-nuevo-socio', this)"
                    class="mt-2 sm:mt-0 inline-flex items-center gap-2 rounded-full bg-[#111318] px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:brightness-110 transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nuevo socio
                </button>
            </div>

            <?php if ($fichaFinanciera): $miembroFicha = $fichaFinanciera['membresia']; ?>
            <section class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-sm" aria-label="Detalle económico del socio">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-extrabold text-neutral-800"><?= htmlspecialchars(trim($fichaFinanciera['socio']['nombre'] . ' ' . $fichaFinanciera['socio']['apellidos']), ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="mt-1 text-sm text-neutral-500">Ficha económica e histórico interno</p>
                    </div>
                    <a href="<?= htmlspecialchars($urlPagina($paginaActual), ENT_QUOTES, 'UTF-8') ?>" class="rounded-full border border-[#e4e4e7] px-4 py-2 text-xs font-bold text-neutral-600">Cerrar detalle</a>
                </div>
                <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl bg-[#f4f4f5] p-4"><p class="text-xs font-bold uppercase text-neutral-500">Membresía</p><p class="mt-1 font-bold text-neutral-800"><?= $miembroFicha ? htmlspecialchars($miembroFicha['nombre_tipo'], ENT_QUOTES, 'UTF-8') : 'Sin membresía vigente' ?></p><?php if ($miembroFicha): ?><p class="text-xs text-neutral-500">Hasta <?= date('d/m/Y', strtotime($miembroFicha['fecha_fin'])) ?> · <?= number_format((float) $fichaFinanciera['precio_contratado'], 2, ',', '.') ?> €</p><?php endif; ?></div>
                    <div class="rounded-xl bg-[#f4f4f5] p-4"><p class="text-xs font-bold uppercase text-neutral-500">Estado económico</p><p class="mt-1 font-bold text-neutral-800"><?= htmlspecialchars($fichaFinanciera['estado_economico'], ENT_QUOTES, 'UTF-8') ?></p><p class="text-xs text-neutral-500">Deuda: <?= number_format((float) $fichaFinanciera['deuda'], 2, ',', '.') ?> €</p></div>
                    <div class="rounded-xl bg-[#f4f4f5] p-4"><p class="text-xs font-bold uppercase text-neutral-500">Último cobro</p><?php if ($fichaFinanciera['ultimo_cobro']): ?><p class="mt-1 font-bold text-neutral-800"><?= number_format((float) $fichaFinanciera['ultimo_cobro']['importe'], 2, ',', '.') ?> €</p><p class="text-xs text-neutral-500"><?= date('d/m/Y', strtotime($fichaFinanciera['ultimo_cobro']['fecha_estado'])) ?> · <?= htmlspecialchars($fichaFinanciera['ultimo_cobro']['estado'], ENT_QUOTES, 'UTF-8') ?></p><?php else: ?><p class="mt-1 text-sm text-neutral-500">No consta</p><?php endif; ?></div>
                    <div class="rounded-xl bg-[#f4f4f5] p-4"><p class="text-xs font-bold uppercase text-neutral-500">Acceso lógico</p><p class="mt-1 font-bold text-neutral-800"><?= htmlspecialchars($accesoFicha['estado'], ENT_QUOTES, 'UTF-8') ?></p><p class="text-xs text-neutral-500"><?= htmlspecialchars($accesoFicha['motivo'], ENT_QUOTES, 'UTF-8') ?></p></div>
                </div>
                <details class="mt-5">
                    <summary class="cursor-pointer text-sm font-bold text-[#4f46e5]">Ver cronología económica (<?= count($historialFinanciero) ?>)</summary>
                    <?php if (!$historialFinanciero): ?><p class="mt-3 text-sm italic text-neutral-500">No hay eventos económicos.</p><?php else: ?><ol class="mt-3 divide-y divide-[#e4e4e7] border-y border-[#e4e4e7]">
                        <?php foreach ($historialFinanciero as $evento): ?><li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"><div><span class="font-bold text-neutral-700"><?= htmlspecialchars(ucfirst($evento['tipo']), ENT_QUOTES, 'UTF-8') ?></span><span class="ml-2 text-neutral-500"><?= htmlspecialchars($evento['concepto'], ENT_QUOTES, 'UTF-8') ?></span><span class="block text-xs text-neutral-500"><?= date('d/m/Y H:i', strtotime($evento['fecha'])) ?> · <?= htmlspecialchars($evento['estado'], ENT_QUOTES, 'UTF-8') ?></span></div><span class="font-bold text-neutral-700"><?= number_format((float) $evento['importe'], 2, ',', '.') ?> €</span></li><?php endforeach; ?>
                    </ol><?php endif; ?>
                </details>
            </section>
            <?php endif; ?>

            <?php if ($mensajeExito !== ''): ?>
            <div class="mt-5 rounded-[14px] border border-[#bbf7d0] bg-[#f0fdf4] px-5 py-3 text-sm font-bold text-[#111318]">
                <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <?php if ($errorSocio !== ''): ?>
            <div class="mt-5 rounded-[14px] border border-red-200 bg-red-50 px-5 py-3 text-sm font-bold text-red-600">
                <?= htmlspecialchars($errorSocio, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <!-- Tarjetas de estadísticas -->
            <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Total socios</p>
                    <div class="mt-5"><span class="text-4xl font-extrabold leading-none text-[#52525b]"><?= (int) $totalSocios ?></span></div>
                    <div class="mt-6 h-1.5 rounded-full bg-[#e4e4e7]"><div class="h-full w-[80%] rounded-full bg-[#4f46e5]"></div></div>
                </article>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Membresías activas</p>
                    <div class="mt-5"><span class="text-4xl font-extrabold leading-none text-[#111318]"><?= (int) $activas ?></span></div>
                    <div class="mt-6 h-1.5 rounded-full bg-[#e4e4e7]"><div class="h-full w-[85%] rounded-full bg-[#4f46e5]"></div></div>
                </article>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Vencidas</p>
                    <div class="mt-5"><span class="text-4xl font-extrabold leading-none <?= $vencidas > 0 ? 'text-[#dc2626]' : 'text-neutral-300' ?>"><?= (int) $vencidas ?></span></div>
                    <div class="mt-6 h-1.5 rounded-full bg-red-50"><div class="h-full w-[30%] rounded-full bg-red-200"></div></div>
                </article>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Pruebas sin pagar</p>
                    <div class="mt-5"><span class="text-4xl font-extrabold leading-none <?= count($pruebas) > 0 ? 'text-[#111318]' : 'text-neutral-300' ?>"><?= count($pruebas) ?></span></div>
                    <div class="mt-6 h-1.5 rounded-full bg-[#e4e4e7]"><div class="h-full w-[35%] rounded-full bg-[#4f46e5]"></div></div>
                </article>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Vencen en 15 días</p>
                    <div class="mt-5"><span class="text-4xl font-extrabold leading-none <?= count($porVencer) > 0 ? 'text-[#111318]' : 'text-neutral-300' ?>"><?= count($porVencer) ?></span></div>
                    <div class="mt-6 h-1.5 rounded-full bg-[#e4e4e7]"><div class="h-full w-[40%] rounded-full bg-[#4f46e5]"></div></div>
                </article>
            </div>

            <!-- Accesos de prueba pendientes de cobrar -->
            <?php if (!empty($pruebas)): ?>
            <div class="mt-6 rounded-[22px] border border-[#fcd34d] bg-[#fffbeb] p-5 shadow-sm">
                <div class="mb-3 flex items-center gap-2">
                    <span class="inline-flex rounded-full bg-[#fef3c7] px-3 py-1 text-[11px] font-extrabold uppercase tracking-wide text-[#b45309]">
                        Pruebas sin pagar
                    </span>
                    <span class="text-sm font-medium text-neutral-500">
                        Se cierran solas si nadie confirma el pago.
                    </span>
                </div>
                <ul class="divide-y divide-[#e4e4e7]">
                    <?php foreach ($pruebas as $p): $dias = (int) $p['dias_restantes']; ?>
                    <li class="flex flex-wrap items-center gap-3 py-2.5 text-sm">
                        <span class="font-bold text-neutral-800">
                            <?= htmlspecialchars($p['nombre'] . ' ' . $p['apellidos'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <span class="text-xs text-neutral-500">
                            hasta el <?= date('d/m/Y', strtotime($p['fecha_fin'])) ?>
                        </span>
                        <span class="rounded-full px-3 py-1 text-xs font-bold
                            <?= $dias <= 1 ? 'bg-[#fee2e2] text-[#dc2626]' : 'bg-[#dcfce7] text-[#15803d]' ?>">
                            <?= $dias === 0 ? 'Último día' : 'Quedan ' . $dias . ' día' . ($dias === 1 ? '' : 's') ?>
                        </span>
                        <button type="button"
                            onclick="abrirModalMembresia(<?= (int) $p['id_socio'] ?>, '<?= htmlspecialchars(addslashes($p['nombre'] . ' ' . $p['apellidos']), ENT_QUOTES, 'UTF-8') ?>', '')"
                            class="ml-auto rounded-full bg-[#111318] px-4 py-1.5 text-xs font-bold text-white hover:brightness-125 transition">
                            Confirmar pago
                        </button>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Buscador -->
            <form method="GET" class="mt-6 flex flex-wrap gap-3 items-center">
                <input type="hidden" name="action" value="admin_socios">
                <label for="socios-buscar" class="sr-only">Buscar socio</label>
                <input type="search" id="socios-buscar" name="buscar" maxlength="100"
                    value="<?= htmlspecialchars($busqueda ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Buscar por nombre, apellidos, email, teléfono o DNI..."
                    class="flex-1 min-w-[220px] rounded-xl border border-[#e4e4e7] bg-white px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                <button type="submit"
                    class="rounded-full bg-[#111318] px-5 py-2.5 text-sm font-bold text-white hover:brightness-110 transition">
                    Buscar
                </button>
                <?php if (!empty($busqueda)): ?>
                <a href="index.php?action=admin_socios"
                    class="rounded-full border border-[#e4e4e7] px-5 py-2.5 text-sm font-bold text-neutral-500 hover:bg-neutral-50 transition">
                    Limpiar
                </a>
                <?php endif; ?>
            </form>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-neutral-500" aria-live="polite">
                <span>
                    <?php if ($totalResultados > 0): ?>
                    Mostrando <?= (($paginaActual - 1) * $porPagina) + 1 ?>–<?= min($paginaActual * $porPagina, $totalResultados) ?>
                    de <?= $totalResultados ?> resultado<?= $totalResultados === 1 ? '' : 's' ?>
                    <?php else: ?>
                    0 resultados
                    <?php endif; ?>
                </span>
                <span>Página <?= $paginaActual ?> de <?= $totalPaginas ?></span>
            </div>

            <!-- Tabla de socios -->
            <div class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white shadow-sm overflow-hidden">
                <?php if (empty($socios)): ?>
                    <p class="p-6 text-sm text-neutral-500 italic">
                        <?= !empty($busqueda) ? 'No hay socios que coincidan con la búsqueda.' : 'No hay socios registrados todavía.' ?>
                    </p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm min-w-[900px]">
                            <thead class="border-b border-[#e4e4e7] bg-[#f4f4f5]">
                                <tr>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Socio</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Contacto</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Membresía</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Vence</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Estado</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Economía / acceso</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($socios as $socio):
                                    $estado  = $socio['estado_membresia'] ?? 'sin_membresia';
                                    $etiqueta = $etiquetasEstado[$estado] ?? $etiquetasEstado['sin_membresia'];
                                    $dias    = $socio['dias_restantes'];
                                    $economico = $estadoFinancieroSocios[(int) $socio['id_usuario']] ?? ['estado_economico'=>'AL_CORRIENTE','deuda'=>'0.00','deuda_cents'=>0,'ultimo_cobro'=>null];
                                    $acceso = $estadoAccesoSocios[(int) $socio['id_usuario']] ?? ['estado'=>'REVISAR','motivo'=>'Estado no calculado.'];
                                ?>
                                <tr class="border-t border-[#e4e4e7] hover:bg-neutral-50 transition-colors">
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-3">
                                            <?php if (!empty($socio['foto'])): ?>
                                            <img src="assets/fotos/<?= htmlspecialchars($socio['foto'], ENT_QUOTES, 'UTF-8') ?>"
                                                class="w-9 h-9 rounded-full object-cover" alt="">
                                            <?php else: ?>
                                            <div class="w-9 h-9 rounded-full bg-[#e4e4e7] flex items-center justify-center text-[#111318] font-bold text-sm">
                                                <?= strtoupper(mb_substr($socio['nombre'], 0, 1, 'UTF-8')) ?>
                                            </div>
                                            <?php endif; ?>
                                            <div>
                                                <span class="block font-bold text-neutral-700">
                                                    <?= htmlspecialchars($socio['nombre'] . ' ' . $socio['apellidos'], ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                                <span class="block text-xs text-neutral-500"><?= htmlspecialchars($socio['dni'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3 text-neutral-500">
                                        <span class="block text-xs"><?= htmlspecialchars($socio['email'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!empty($socio['telefono'])): ?>
                                        <span class="block text-xs text-neutral-500"><?= htmlspecialchars($socio['telefono'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($socio['iban'])): ?>
                                        <span class="mt-0.5 block font-mono text-[10px] tracking-tight text-neutral-500" title="IBAN guardado para transferencias">
                                            <?= htmlspecialchars(Iban::enmascarar($socio['iban']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 text-neutral-500">
                                        <?php if (!empty($socio['nombre_tipo'])): ?>
                                            <?= htmlspecialchars($socio['nombre_tipo'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (!empty($socio['nombre_suplemento'])): ?>
                                            <span class="mt-0.5 block">
                                                <span class="inline-flex rounded-full bg-[#111318] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">
                                                    + <?= htmlspecialchars($socio['nombre_suplemento'], ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </span>
                                            <?php endif; ?>
                                            <span class="block text-[11px] text-neutral-500">
                                                <?= number_format((float) $socio['precio_pagado'] + (float) $socio['precio_suplemento'], 2, ',', '.') ?> €
                                            </span>
                                        <?php else: ?>
                                            <span class="text-neutral-300">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 text-neutral-500">
                                        <?php if (!empty($socio['fecha_fin'])): ?>
                                            <?= date('d/m/Y', strtotime($socio['fecha_fin'])) ?>
                                            <?php if ($estado === 'activa' && $dias !== null && (int) $dias <= 15): ?>
                                            <span class="block text-[11px] font-bold text-[#111318]">
                                                <?= (int) $dias === 0 ? 'Vence hoy' : 'En ' . (int) $dias . ' días' ?>
                                            </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-neutral-300">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold <?= $etiqueta[1] ?>">
                                            <?= $etiqueta[0] ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="block text-xs font-extrabold <?= $economico['estado_economico'] === 'AL_CORRIENTE' ? 'text-green-700' : ($economico['estado_economico'] === 'PENDIENTE' ? 'text-amber-700' : 'text-red-700') ?>">
                                            <?= htmlspecialchars($economico['estado_economico'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php if ((int) $economico['deuda_cents'] > 0): ?> · <?= number_format((float) $economico['deuda'], 2, ',', '.') ?> €<?php endif; ?>
                                        </span>
                                        <span class="mt-1 block text-xs font-bold <?= $acceso['estado'] === 'PERMITIDO' ? 'text-green-700' : ($acceso['estado'] === 'BLOQUEADO' ? 'text-red-700' : 'text-amber-700') ?>" title="<?= htmlspecialchars($acceso['motivo'], ENT_QUOTES, 'UTF-8') ?>">
                                            Acceso: <?= htmlspecialchars($acceso['estado'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <?php if ($puedeVerDetalleEconomico && !empty($economico['ultimo_cobro'])): ?>
                                            <span class="block text-[11px] text-neutral-500">Último: <?= date('d/m/Y', strtotime($economico['ultimo_cobro']['fecha_estado'])) ?> · <?= number_format((float) $economico['ultimo_cobro']['importe'], 2, ',', '.') ?> €</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap">
                                        <?php
                                        // El acceso de prueba solo se ofrece a quien no tiene
                                        // acceso abierto y no lo ha disfrutado ya.
                                        $puedeProbar = $estado === 'sin_membresia';
                                        if ($puedeProbar):
                                        ?>
                                        <form method="POST" action="index.php?action=admin_socio_prueba" style="display:inline"
                                            onsubmit="return confirm('¿Abrir el acceso de prueba <?= (int) $diasPrueba ?> días para <?= htmlspecialchars(addslashes($socio['nombre']), ENT_QUOTES, 'UTF-8') ?>? Se cerrará solo si no se confirma el pago.');">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="volver_buscar" value="<?= htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="volver_pagina" value="<?= $paginaActual ?>">
                                            <input type="hidden" name="id_socio" value="<?= (int) $socio['id_usuario'] ?>">
                                            <button type="submit"
                                                class="rounded-full border-2 border-[#4f46e5] px-3 py-1 text-xs font-bold text-[#4f46e5] hover:bg-[#eef2ff] transition">
                                                Prueba <?= (int) $diasPrueba ?> días
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <button type="button"
                                            onclick='abrirModalEditarSocio(<?= json_encode([
                                                "id"        => (int) $socio["id_usuario"],
                                                "nombre"    => $socio["nombre"],
                                                "apellidos" => $socio["apellidos"],
                                                "email"     => $socio["email"],
                                                "telefono"  => $socio["telefono"] ?? "",
                                                "iban"      => $socio["iban"] ?? "",
                                            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'
                                            class="rounded-full border border-[#e4e4e7] px-3 py-1 text-xs font-bold text-neutral-500 hover:bg-neutral-50 transition">
                                            Editar
                                        </button>
                                        <button type="button"
                                            onclick="abrirModalMembresia(<?= (int) $socio['id_usuario'] ?>, '<?= htmlspecialchars(addslashes($socio['nombre'] . ' ' . $socio['apellidos']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($socio['iban'] ?? '', ENT_QUOTES, 'UTF-8') ?>')"
                                            class="rounded-full bg-[#4f46e5] px-3 py-1 text-xs font-bold text-white hover:brightness-125 transition">
                                            <?= $estado === 'prueba' ? 'Confirmar pago' : ($estado === 'activa' ? 'Renovar' : 'Dar membresía') ?>
                                        </button>
                                        <?php if ($puedeVerDetalleEconomico): ?>
                                        <a href="index.php?<?= htmlspecialchars(http_build_query(array_filter(['action'=>'admin_socios','buscar'=>$busqueda,'pagina'=>$paginaActual,'detalle'=>(int)$socio['id_usuario']], static fn($v)=>$v!=='')), ENT_QUOTES, 'UTF-8') ?>" class="ml-1 inline-flex rounded-full border border-[#e4e4e7] px-3 py-1 text-xs font-bold text-neutral-600 hover:bg-neutral-50">Economía</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($totalPaginas > 1):
                $primeraVisible = max(1, $paginaActual - 2);
                $ultimaVisible = min($totalPaginas, $paginaActual + 2);
            ?>
            <nav class="mt-5 flex flex-wrap items-center justify-center gap-2" aria-label="Paginación de socios">
                <?php if ($paginaActual > 1): ?>
                <a href="<?= htmlspecialchars($urlPagina($paginaActual - 1), ENT_QUOTES, 'UTF-8') ?>"
                   class="rounded-full border border-[#e4e4e7] px-4 py-2 text-xs font-bold text-neutral-600 hover:bg-white">
                    Anterior
                </a>
                <?php endif; ?>

                <?php for ($numero = $primeraVisible; $numero <= $ultimaVisible; $numero++): ?>
                <a href="<?= htmlspecialchars($urlPagina($numero), ENT_QUOTES, 'UTF-8') ?>"
                   <?= $numero === $paginaActual ? 'aria-current="page"' : '' ?>
                   class="rounded-full px-3.5 py-2 text-xs font-bold <?= $numero === $paginaActual ? 'bg-[#111318] text-white' : 'border border-[#e4e4e7] text-neutral-600 hover:bg-white' ?>">
                    <?= $numero ?>
                </a>
                <?php endfor; ?>

                <?php if ($paginaActual < $totalPaginas): ?>
                <a href="<?= htmlspecialchars($urlPagina($paginaActual + 1), ENT_QUOTES, 'UTF-8') ?>"
                   class="rounded-full border border-[#e4e4e7] px-4 py-2 text-xs font-bold text-neutral-600 hover:bg-white">
                    Siguiente
                </a>
                <?php endif; ?>
            </nav>
            <?php endif; ?>

        </section>
    </main>

<!-- Modal: nuevo socio -->
<div id="modal-nuevo-socio"
     role="dialog" aria-modal="true" aria-labelledby="modal-nuevo-socio-titulo" tabindex="-1"
     class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6 overflow-y-auto"
     onclick="if(event.target===this) cerrarModal('modal-nuevo-socio')">

    <div class="w-full max-w-lg rounded-[24px] bg-white p-8 shadow-2xl my-auto">

        <div class="flex items-center justify-between mb-6">
            <h2 id="modal-nuevo-socio-titulo" class="text-xl font-extrabold text-neutral-800">Nuevo socio</h2>
            <button type="button" onclick="cerrarModal('modal-nuevo-socio')"
                class="text-neutral-500 hover:text-neutral-600 transition-colors text-2xl leading-none"
                aria-label="Cerrar">&times;</button>
        </div>

        <form method="POST" action="index.php?action=admin_socio_registrar" class="space-y-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="volver_buscar" value="<?= htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="volver_pagina" value="<?= $paginaActual ?>">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="alta-nombre" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Nombre <span class="text-red-400">*</span>
                    </label>
                    <input type="text" id="alta-nombre" name="nombre" required maxlength="100"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <div>
                    <label for="alta-apellidos" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Apellidos <span class="text-red-400">*</span>
                    </label>
                    <input type="text" id="alta-apellidos" name="apellidos" required maxlength="150"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="alta-dni" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        DNI <span class="text-red-400">*</span>
                    </label>
                    <input type="text" id="alta-dni" name="dni" required maxlength="20"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <div>
                    <label for="alta-telefono" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Teléfono
                    </label>
                    <input type="text" id="alta-telefono" name="telefono" maxlength="20"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
            </div>

            <div>
                <label for="alta-email" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                    Email <span class="text-red-400">*</span>
                </label>
                <input type="email" id="alta-email" name="email" required maxlength="255"
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="alta-usuario" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Usuario <span class="text-red-400">*</span>
                    </label>
                    <input type="text" id="alta-usuario" name="usuario" required maxlength="60"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <div>
                    <label for="alta-contrasena" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Contraseña <span class="text-red-400">*</span>
                    </label>
                    <input type="password" id="alta-contrasena" name="contrasena" required minlength="8"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 border-t border-[#e4e4e7] pt-4">
                <div>
                    <label for="alta-tipo-membresia" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Membresía inicial
                    </label>
                    <select name="id_tipo_membresia" id="alta-tipo-membresia"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                        <option value="0">— Sin membresía —</option>
                        <?php foreach ($tipos as $tipo): ?>
                        <option value="<?= (int) $tipo['id_tipo_membresia'] ?>">
                            <?= htmlspecialchars($tipo['nombre'], ENT_QUOTES, 'UTF-8') ?>
                            (<?= number_format((float) $tipo['precio'], 2, ',', '.') ?> € · <?= (int) $tipo['duracion_meses'] ?> mes<?= (int) $tipo['duracion_meses'] === 1 ? '' : 'es' ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="alta-metodo-pago" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Método de pago
                    </label>
                    <select name="metodo_pago" id="alta-metodo-pago" onchange="alternarIban('alta')"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                        <?php foreach ($etiquetasPago as $valor => $etiqueta): ?>
                        <option value="<?= $valor ?>"><?= $etiqueta ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- El valor técnico heredado "transferencia" representa domiciliación SEPA. -->
            <div id="alta-iban-wrap" style="display:none">
                <label for="alta-iban" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                    IBAN para la domiciliación <span class="text-[#dc2626]">*</span>
                </label>
                <input type="text" name="iban" id="alta-iban" maxlength="34"
                    placeholder="ES91 2100 0418 4502 0005 1332"
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium uppercase tracking-wide text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                <p class="mt-1 text-[11px] text-neutral-500">
                    Se comprueba el dígito de control antes de guardarlo.
                </p>
            </div>

            <?php if (!empty($suplementos)): ?>
            <div>
                <label for="alta-suplemento" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                    Suplemento
                </label>
                <select name="id_suplemento" id="alta-suplemento"
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                    <option value="0">— Solo cuota base —</option>
                    <?php foreach ($suplementos as $sup): ?>
                    <option value="<?= (int) $sup['id_suplemento'] ?>">
                        <?= htmlspecialchars($sup['nombre'], ENT_QUOTES, 'UTF-8') ?>
                        (+<?= number_format((float) $sup['precio_mensual'], 2, ',', '.') ?> €/mes)
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-[11px] text-neutral-500">
                    El plus se cobra por cada mes que dure la cuota elegida.
                </p>
            </div>
            <?php endif; ?>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                    class="flex-1 rounded-full bg-[#4f46e5] py-2.5 text-sm font-bold text-white hover:brightness-110 transition-all">
                    Dar de alta
                </button>
                <button type="button"
                    onclick="cerrarModal('modal-nuevo-socio')"
                    class="flex-1 rounded-full border border-[#e4e4e7] py-2.5 text-sm font-bold text-neutral-500 hover:bg-neutral-50 transition-all">
                    Cancelar
                </button>
            </div>
        </form>

    </div>
</div>

<!-- Modal: contratar / renovar membresía -->
<div id="modal-membresia"
     role="dialog" aria-modal="true" aria-labelledby="modal-membresia-titulo" tabindex="-1"
     class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6 overflow-y-auto"
     onclick="if(event.target===this) cerrarModal('modal-membresia')">

    <div class="w-full max-w-md rounded-[24px] bg-white p-8 shadow-2xl my-auto">

        <div class="flex items-center justify-between mb-2">
            <h2 id="modal-membresia-titulo" class="text-xl font-extrabold text-neutral-800">Membresía</h2>
            <button type="button" onclick="cerrarModal('modal-membresia')"
                class="text-neutral-500 hover:text-neutral-600 transition-colors text-2xl leading-none"
                aria-label="Cerrar">&times;</button>
        </div>
        <p id="membresia-socio-nombre" class="mb-6 text-sm font-medium text-neutral-500"></p>

        <form method="POST" action="index.php?action=admin_membresia_contratar" class="space-y-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="volver_buscar" value="<?= htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="volver_pagina" value="<?= $paginaActual ?>">
            <input type="hidden" name="_operation_id" value="<?= bin2hex(random_bytes(16)) ?>">
            <input type="hidden" name="id_socio" id="membresia-id-socio" value="0">

            <div>
                <label for="membresia-tipo" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                    Tipo de membresía <span class="text-red-400">*</span>
                </label>
                <select name="id_tipo_membresia" id="membresia-tipo" required onchange="calcularTotalMembresia()"
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                    <?php foreach ($tipos as $tipo): ?>
                    <option value="<?= (int) $tipo['id_tipo_membresia'] ?>"
                            data-precio="<?= (float) $tipo['precio'] ?>"
                            data-meses="<?= (int) $tipo['duracion_meses'] ?>">
                        <?= htmlspecialchars($tipo['nombre'], ENT_QUOTES, 'UTF-8') ?>
                        (<?= number_format((float) $tipo['precio'], 2, ',', '.') ?> € · <?= (int) $tipo['duracion_meses'] ?> mes<?= (int) $tipo['duracion_meses'] === 1 ? '' : 'es' ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (!empty($suplementos)): ?>
            <div>
                <label for="membresia-suplemento" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                    Suplemento
                </label>
                <select name="id_suplemento" id="membresia-suplemento" onchange="calcularTotalMembresia()"
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                    <option value="0" data-precio="0">— Solo cuota base —</option>
                    <?php foreach ($suplementos as $sup): ?>
                    <option value="<?= (int) $sup['id_suplemento'] ?>" data-precio="<?= (float) $sup['precio_mensual'] ?>">
                        <?= htmlspecialchars($sup['nombre'], ENT_QUOTES, 'UTF-8') ?>
                        (+<?= number_format((float) $sup['precio_mensual'], 2, ',', '.') ?> €/mes)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div>
                <label for="membresia-metodo-pago" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                    Método de pago <span class="text-red-400">*</span>
                </label>
                <select name="metodo_pago" id="membresia-metodo-pago" required onchange="alternarIban('membresia')"
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                    <?php foreach ($etiquetasPago as $valor => $etiqueta): ?>
                    <option value="<?= $valor ?>"><?= $etiqueta ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="membresia-iban-wrap" style="display:none">
                <label for="membresia-iban" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                    IBAN para la domiciliación <span class="text-[#dc2626]">*</span>
                </label>
                <input type="text" name="iban" id="membresia-iban" maxlength="34"
                    placeholder="ES91 2100 0418 4502 0005 1332"
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium uppercase tracking-wide text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                <p id="membresia-iban-aviso" class="mt-1 text-[11px] text-neutral-500"></p>
            </div>

            <div class="flex items-baseline justify-between rounded-xl bg-[#111318] px-4 py-3 text-white">
                <span class="text-xs font-extrabold uppercase tracking-widest">Total a cobrar</span>
                <span id="membresia-total" class="text-2xl font-extrabold">0,00 €</span>
            </div>

            <p class="rounded-xl bg-[#f4f4f5] px-4 py-3 text-xs text-neutral-500">
                Si el socio ya tiene una membresía en vigor, la nueva empieza el día siguiente
                a su vencimiento: no pierde los días que le quedaban.
            </p>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                    class="flex-1 rounded-full bg-[#4f46e5] py-2.5 text-sm font-bold text-white hover:brightness-110 transition-all">
                    Confirmar
                </button>
                <button type="button"
                    onclick="cerrarModal('modal-membresia')"
                    class="flex-1 rounded-full border border-[#e4e4e7] py-2.5 text-sm font-bold text-neutral-500 hover:bg-neutral-50 transition-all">
                    Cancelar
                </button>
            </div>
        </form>

    </div>
</div>

<!-- Modal: editar datos del socio -->
<div id="modal-editar-socio"
     role="dialog" aria-modal="true" aria-labelledby="modal-editar-socio-titulo" tabindex="-1"
     class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6 overflow-y-auto"
     onclick="if(event.target===this) cerrarModal('modal-editar-socio')">

    <div class="w-full max-w-lg rounded-[24px] bg-white p-8 shadow-2xl my-auto">

        <div class="flex items-center justify-between mb-6">
            <h2 id="modal-editar-socio-titulo" class="text-xl font-extrabold text-neutral-800">Editar socio</h2>
            <button type="button" onclick="cerrarModal('modal-editar-socio')"
                class="text-neutral-500 hover:text-neutral-700 transition-colors text-2xl leading-none"
                aria-label="Cerrar">&times;</button>
        </div>

        <form method="POST" action="index.php?action=admin_socio_editar" class="space-y-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="volver_buscar" value="<?= htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="volver_pagina" value="<?= $paginaActual ?>">
            <input type="hidden" name="id_socio" id="editar-id-socio" value="0">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="editar-nombre" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Nombre <span class="text-[#dc2626]">*</span>
                    </label>
                    <input type="text" name="nombre" id="editar-nombre" required maxlength="100"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <div>
                    <label for="editar-apellidos" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Apellidos <span class="text-[#dc2626]">*</span>
                    </label>
                    <input type="text" name="apellidos" id="editar-apellidos" required maxlength="150"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="editar-email" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Email <span class="text-[#dc2626]">*</span>
                    </label>
                    <input type="email" name="email" id="editar-email" required maxlength="255"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <div>
                    <label for="editar-telefono" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Teléfono
                    </label>
                    <input type="text" name="telefono" id="editar-telefono" maxlength="20"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
            </div>

            <div class="border-t border-[#e4e4e7] pt-4">
                <label for="editar-iban" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                    IBAN para domiciliaciones
                </label>
                <input type="text" name="iban" id="editar-iban" maxlength="34"
                    placeholder="ES91 2100 0418 4502 0005 1332"
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium uppercase tracking-wide text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                <p class="mt-1 text-[11px] text-neutral-500">
                    Déjalo vacío si este socio no paga por domiciliación. Se comprueba el dígito de control.
                </p>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                    class="flex-1 rounded-full bg-[#4f46e5] py-2.5 text-sm font-bold text-white hover:brightness-110 transition-all">
                    Guardar cambios
                </button>
                <button type="button"
                    onclick="cerrarModal('modal-editar-socio')"
                    class="flex-1 rounded-full border border-[#e4e4e7] py-2.5 text-sm font-bold text-neutral-500 hover:bg-neutral-50 transition-all">
                    Cancelar
                </button>
            </div>
        </form>

        <!-- Mandato SEPA: va en formulario aparte porque es otra acción -->
        <div class="mt-5 rounded-[16px] border border-[#e4e4e7] bg-[#f7f7f8] p-4">
            <p class="text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1">Mandato de domiciliación</p>
            <p class="mb-3 text-[11px] text-neutral-500">
                Necesario para cobrar por adeudo SEPA. Guarda antes el IBAN si lo has cambiado;
                firmar un mandato nuevo revoca el anterior.
            </p>
            <form method="POST" action="index.php?action=admin_mandato_crear" class="flex flex-wrap items-end gap-3"
                onsubmit="return confirm('¿Registrar el mandato firmado por el socio? Esto revoca cualquier mandato anterior.');">
                <?= Csrf::field() ?>
                <input type="hidden" name="volver_buscar" value="<?= htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="volver_pagina" value="<?= $paginaActual ?>">
                <input type="hidden" name="id_socio" id="mandato-id-socio" value="0">
                <input type="hidden" name="iban"     id="mandato-iban"     value="">
                <div>
                    <label for="mandato-fecha-firma" class="block text-[11px] font-bold uppercase tracking-widest text-neutral-500 mb-1">Fecha de firma</label>
                    <input type="date" id="mandato-fecha-firma" name="fecha_firma" value="<?= date('Y-m-d') ?>"
                        class="rounded-lg border border-[#e4e4e7] bg-white px-3 py-1.5 text-sm text-neutral-700 focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                </div>
                <button type="submit"
                    class="rounded-full bg-[#111318] px-4 py-1.5 text-xs font-bold text-white hover:brightness-125 transition">
                    Registrar mandato firmado
                </button>
            </form>
        </div>

    </div>
</div>

<script>
var ultimoDisparadorModal = null;

function abrirModal(id, disparador) {
    var modal = document.getElementById(id);
    if (!modal) return;
    ultimoDisparadorModal = disparador || document.activeElement;
    modal.classList.remove('hidden');
    window.requestAnimationFrame(function () {
        var foco = modal.querySelector('input:not([type="hidden"]), select, button');
        (foco || modal).focus();
    });
}

function cerrarModal(id) {
    var modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add('hidden');
    if (ultimoDisparadorModal && typeof ultimoDisparadorModal.focus === 'function') {
        ultimoDisparadorModal.focus();
    }
}

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    ['modal-editar-socio', 'modal-membresia', 'modal-nuevo-socio'].some(function (id) {
        var modal = document.getElementById(id);
        if (modal && !modal.classList.contains('hidden')) {
            cerrarModal(id);
            return true;
        }
        return false;
    });
});

// El valor técnico heredado "transferencia" representa la domiciliación SEPA.
function alternarIban(prefijo) {
    var metodo = document.getElementById(prefijo + '-metodo-pago');
    var caja   = document.getElementById(prefijo + '-iban-wrap');
    var campo  = document.getElementById(prefijo + '-iban');
    if (!metodo || !caja) return;

    var esTransferencia = metodo.value === 'transferencia';
    caja.style.display = esTransferencia ? '' : 'none';
    if (campo) campo.required = esTransferencia;
}

function abrirModalEditarSocio(datos) {
    document.getElementById('editar-id-socio').value  = datos.id;
    document.getElementById('editar-nombre').value    = datos.nombre;
    document.getElementById('editar-apellidos').value = datos.apellidos;
    document.getElementById('editar-email').value     = datos.email;
    document.getElementById('editar-telefono').value  = datos.telefono || '';
    document.getElementById('editar-iban').value      = datos.iban || '';

    // El mandato es otro formulario: hay que pasarle el socio y su cuenta.
    document.getElementById('mandato-id-socio').value = datos.id;
    document.getElementById('mandato-iban').value     = datos.iban || '';

    abrirModal('modal-editar-socio', document.activeElement);
}

function abrirModalMembresia(idSocio, nombre, ibanGuardado) {
    document.getElementById('membresia-id-socio').value = idSocio;
    document.getElementById('membresia-socio-nombre').textContent = nombre;

    // Si el socio ya tiene cuenta guardada se precarga, para no volver a
    // teclearla en cada renovación.
    var campo = document.getElementById('membresia-iban');
    var aviso = document.getElementById('membresia-iban-aviso');
    if (campo) {
        campo.value = ibanGuardado || '';
        if (aviso) {
            aviso.textContent = ibanGuardado
                ? 'Cuenta guardada del socio. Modifícala si ha cambiado.'
                : 'Este socio no tiene cuenta guardada todavía.';
        }
    }

    alternarIban('membresia');
    calcularTotalMembresia();
    abrirModal('modal-membresia', document.activeElement);
}

// Cuota base + (plus mensual x meses de la cuota). Mismo cálculo que hace
// MembresiaModel::contratar() en el servidor.
function calcularTotalMembresia() {
    var selTipo = document.getElementById('membresia-tipo');
    var selSup  = document.getElementById('membresia-suplemento');
    var destino = document.getElementById('membresia-total');
    if (!selTipo || !destino) return;

    var optTipo = selTipo.options[selTipo.selectedIndex];
    var base    = parseFloat(optTipo.dataset.precio) || 0;
    var meses   = parseInt(optTipo.dataset.meses, 10) || 1;

    var plus = 0;
    if (selSup) {
        var optSup = selSup.options[selSup.selectedIndex];
        plus = (parseFloat(optSup.dataset.precio) || 0) * meses;
    }

    destino.textContent = (base + plus).toFixed(2).replace('.', ',') + ' €';
}
</script>

</div>
<?php require __DIR__ . '/../_footer.php'; ?>
