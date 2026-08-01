<?php

require_once __DIR__ . '/../../helpers/Csrf.php';
require_once __DIR__ . '/../../helpers/Iban.php';
require __DIR__ . '/../_header_admin.php';

$estadosRemesa = [
    'borrador' => ['Sin enviar', 'bg-[#fef3c7] text-[#b45309]'],
    'enviada'  => ['Enviada',    'bg-[#eef2ff] text-[#4f46e5]'],
    'cobrada'  => ['Cobrada',    'bg-[#dcfce7] text-[#15803d]'],
];
?>
    <main class="flex-1 bg-[#f7f7f8] px-5 py-8 lg:px-8">
        <section class="mx-auto max-w-6xl">

            <div class="pt-4 mb-7">
                <h1 class="text-2xl font-extrabold tracking-tight text-[#111318] sm:text-3xl">Domiciliaciones</h1>
                <p class="mt-1.5 text-sm font-medium text-neutral-500">
                    Cobro de cuotas por adeudo SEPA: se agrupan los recibos, se descarga el fichero
                    y se sube a la banca electrónica del gimnasio.
                </p>
            </div>

            <?php if ($mensajeExito !== ''): ?>
            <div class="mb-5 rounded-[14px] border border-[#bbf7d0] bg-[#f0fdf4] px-5 py-3 text-sm font-bold text-[#15803d]">
                <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <?php if ($errorRemesa !== ''): ?>
            <div class="mb-5 rounded-[14px] border border-[#fecaca] bg-[#fee2e2] px-5 py-3 text-sm font-bold text-[#dc2626]">
                <?= htmlspecialchars($errorRemesa, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <!-- Datos del acreedor -->
            <div class="rounded-[22px] border <?= $datosListos ? 'border-[#e4e4e7]' : 'border-[#fcd34d] bg-[#fffbeb]' ?> bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-bold text-neutral-800">Datos bancarios del gimnasio</h2>
                    <?php if ($datosListos): ?>
                    <span class="rounded-full bg-[#dcfce7] px-3 py-1 text-xs font-bold text-[#15803d]">Listos para remesar</span>
                    <?php else: ?>
                    <span class="rounded-full bg-[#fef3c7] px-3 py-1 text-xs font-bold text-[#b45309]">Faltan datos</span>
                    <?php endif; ?>
                </div>
                <p class="mt-1 text-sm text-neutral-500">
                    El identificador de acreedor te lo da tu banco al contratar los adeudos SEPA.
                    Sin él, el fichero será rechazado.
                </p>

                <form method="POST" action="index.php?action=admin_remesas" class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="accion" value="guardar_acreedor">

                    <div>
                        <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Razón social</label>
                        <input type="text" name="razon_social" maxlength="150"
                            value="<?= htmlspecialchars($acreedor['razon_social'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                    </div>
                    <div>
                        <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">CIF</label>
                        <input type="text" name="cif" maxlength="20"
                            value="<?= htmlspecialchars($acreedor['cif'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                    </div>
                    <div>
                        <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">IBAN del gimnasio</label>
                        <input type="text" name="iban" maxlength="34" placeholder="ES91 2100 0418 4502 0005 1332"
                            value="<?= htmlspecialchars(Iban::formatear($acreedor['iban'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium uppercase text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                    </div>
                    <div>
                        <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">BIC (opcional)</label>
                        <input type="text" name="bic" maxlength="11"
                            value="<?= htmlspecialchars($acreedor['bic'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium uppercase text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                            Identificador de acreedor SEPA
                        </label>
                        <input type="text" name="identificador_acreedor" maxlength="35" placeholder="ES00ZZZB12345678"
                            value="<?= htmlspecialchars($acreedor['identificador_acreedor'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium uppercase text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                    </div>
                    <div class="md:col-span-2">
                        <button type="submit"
                            class="rounded-full bg-[#4f46e5] px-6 py-2.5 text-sm font-bold text-white hover:brightness-110 transition">
                            Guardar datos bancarios
                        </button>
                    </div>
                </form>
            </div>

            <!-- Cobros pendientes de domiciliar -->
            <div class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 class="text-lg font-bold text-neutral-800">Cobros pendientes de domiciliar</h2>
                    <p class="text-2xl font-extrabold text-[#4f46e5]"><?= number_format($totalPendiente, 2, ',', '.') ?> €</p>
                </div>
                <p class="mt-1 text-sm text-neutral-500">
                    Cuotas contratadas por transferencia, de socios con mandato firmado, que aún no se han remesado.
                </p>

                <?php if (empty($pendientes)): ?>
                    <p class="mt-5 text-sm text-neutral-500 italic">
                        No hay cobros pendientes. Para que aparezcan aquí, el socio necesita
                        <strong>mandato firmado</strong> y una cuota contratada con método <strong>transferencia</strong>.
                    </p>
                <?php else: ?>
                <form method="POST" action="index.php?action=admin_remesas" class="mt-5">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="accion" value="crear_remesa">

                    <div class="overflow-x-auto rounded-[16px] border border-[#e4e4e7]">
                        <table class="w-full text-left text-sm min-w-[760px]">
                            <thead class="border-b border-[#e4e4e7] bg-[#f4f4f5]">
                                <tr>
                                    <th class="px-4 py-3 w-10">
                                        <input type="checkbox" checked onclick="marcarTodos(this)" title="Marcar todos">
                                    </th>
                                    <th class="px-4 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Socio</th>
                                    <th class="px-4 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Concepto</th>
                                    <th class="px-4 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Cuenta</th>
                                    <th class="px-4 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Tipo</th>
                                    <th class="px-4 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500 text-right">Importe</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendientes as $p): ?>
                                <tr class="border-t border-[#e4e4e7] hover:bg-neutral-50 transition-colors">
                                    <td class="px-4 py-3">
                                        <input type="checkbox" name="membresias[]" class="chk-remesa"
                                               value="<?= (int) $p['id_socio_membresia'] ?>" checked>
                                    </td>
                                    <td class="px-4 py-3 font-bold text-neutral-800">
                                        <?= htmlspecialchars($p['nombre'] . ' ' . $p['apellidos'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-4 py-3 text-neutral-500">
                                        <?= htmlspecialchars($p['nombre_tipo'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($p['nombre_suplemento'])): ?>
                                            + <?= htmlspecialchars($p['nombre_suplemento'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-[11px] text-neutral-500">
                                        <?= htmlspecialchars(Iban::enmascarar($p['iban']), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase
                                            <?= empty($p['primer_cobro_hecho']) ? 'bg-[#fef3c7] text-[#b45309]' : 'bg-[#eef2ff] text-[#4f46e5]' ?>">
                                            <?= empty($p['primer_cobro_hecho']) ? 'Primero' : 'Recurrente' ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-extrabold text-neutral-800">
                                        <?= number_format((float) $p['importe'], 2, ',', '.') ?> €
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div>
                            <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Concepto</label>
                            <input type="text" name="concepto" maxlength="120" value="Cuota <?= date('m/Y') ?>"
                                class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                        </div>
                        <div>
                            <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Fecha de cobro</label>
                            <input type="date" name="fecha_cobro" value="<?= date('Y-m-d', strtotime('+3 days')) ?>"
                                class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                            <p class="mt-1 text-[11px] text-neutral-500">Deja margen: el banco necesita días de preaviso.</p>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" <?= $datosListos ? '' : 'disabled' ?>
                                class="w-full rounded-full px-6 py-2.5 text-sm font-bold text-white transition
                                    <?= $datosListos ? 'bg-[#4f46e5] hover:brightness-110' : 'bg-neutral-300 cursor-not-allowed' ?>">
                                Crear remesa
                            </button>
                        </div>
                    </div>
                </form>
                <?php endif; ?>
            </div>

            <!-- Remesas generadas -->
            <div class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white shadow-sm overflow-hidden">
                <div class="px-6 pt-6 pb-4">
                    <h2 class="text-lg font-bold text-neutral-800">Remesas</h2>
                </div>
                <?php if (empty($remesas)): ?>
                    <p class="px-6 pb-6 text-sm text-neutral-500 italic">Todavía no se ha creado ninguna remesa.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm min-w-[860px]">
                        <thead class="border-y border-[#e4e4e7] bg-[#f4f4f5]">
                            <tr>
                                <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Nº</th>
                                <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Concepto</th>
                                <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Cobro</th>
                                <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Recibos</th>
                                <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500 text-right">Importe</th>
                                <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Estado</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($remesas as $r):
                                $et = $estadosRemesa[$r['estado']] ?? $estadosRemesa['borrador'];
                            ?>
                            <tr class="border-t border-[#e4e4e7] hover:bg-neutral-50 transition-colors">
                                <td class="px-6 py-3 font-bold text-neutral-500">#<?= (int) $r['id_remesa'] ?></td>
                                <td class="px-6 py-3 text-neutral-700"><?= htmlspecialchars($r['concepto'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-6 py-3 text-neutral-500"><?= date('d/m/Y', strtotime($r['fecha_cobro'])) ?></td>
                                <td class="px-6 py-3 text-neutral-500"><?= (int) $r['num_recibos'] ?></td>
                                <td class="px-6 py-3 text-right font-extrabold text-neutral-800">
                                    <?= number_format((float) $r['importe_total'], 2, ',', '.') ?> €
                                </td>
                                <td class="px-6 py-3">
                                    <span class="rounded-full px-3 py-1 text-xs font-bold <?= $et[1] ?>"><?= $et[0] ?></span>
                                </td>
                                <td class="px-6 py-3 text-right whitespace-nowrap">
                                    <a href="index.php?action=admin_remesa_descargar&id=<?= (int) $r['id_remesa'] ?>"
                                        class="rounded-full bg-[#4f46e5] px-3 py-1 text-xs font-bold text-white hover:brightness-110 transition">
                                        Descargar XML
                                    </a>
                                    <a href="index.php?action=admin_remesas&remesa=<?= (int) $r['id_remesa'] ?>"
                                        class="rounded-full border border-[#e4e4e7] px-3 py-1 text-xs font-bold text-neutral-500 hover:bg-neutral-50 transition">
                                        Ver recibos
                                    </a>
                                    <?php if ($r['estado'] === 'borrador'): ?>
                                    <form method="POST" action="index.php?action=admin_remesas" style="display:inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="accion" value="marcar_enviada">
                                        <input type="hidden" name="id_remesa" value="<?= (int) $r['id_remesa'] ?>">
                                        <button type="submit" class="rounded-full border border-[#e4e4e7] px-3 py-1 text-xs font-bold text-neutral-500 hover:bg-neutral-50 transition">
                                            Marcar enviada
                                        </button>
                                    </form>
                                    <?php elseif ($r['estado'] === 'enviada'): ?>
                                    <form method="POST" action="index.php?action=admin_remesas" style="display:inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="accion" value="marcar_cobrada">
                                        <input type="hidden" name="id_remesa" value="<?= (int) $r['id_remesa'] ?>">
                                        <button type="submit" class="rounded-full border border-[#e4e4e7] px-3 py-1 text-xs font-bold text-neutral-500 hover:bg-neutral-50 transition">
                                            Marcar cobrada
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <?php if ($idRemesaAbierta === (int) $r['id_remesa'] && !empty($recibosAbiertos)): ?>
                            <tr class="bg-[#f7f7f8]">
                                <td colspan="7" class="px-6 py-4">
                                    <p class="mb-3 text-xs font-extrabold uppercase tracking-widest text-neutral-500">
                                        Recibos de la remesa #<?= (int) $r['id_remesa'] ?>
                                    </p>
                                    <ul class="divide-y divide-[#e4e4e7] rounded-[14px] border border-[#e4e4e7] bg-white">
                                        <?php foreach ($recibosAbiertos as $rec): ?>
                                        <li class="flex flex-wrap items-center gap-3 px-4 py-2.5 text-sm">
                                            <span class="font-bold text-neutral-800"><?= htmlspecialchars($rec['nombre_socio'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="font-mono text-[11px] text-neutral-500"><?= htmlspecialchars(Iban::enmascarar($rec['iban']), ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="text-xs text-neutral-500">ref. <?= htmlspecialchars($rec['referencia_mandato'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="font-extrabold text-neutral-800"><?= number_format((float) $rec['importe'], 2, ',', '.') ?> €</span>
                                            <span class="rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase
                                                <?php if ($rec['estado'] === 'cobrado'): ?>bg-[#dcfce7] text-[#15803d]
                                                <?php elseif ($rec['estado'] === 'devuelto'): ?>bg-[#fee2e2] text-[#dc2626]
                                                <?php else: ?>bg-[#f4f4f5] text-neutral-500<?php endif; ?>">
                                                <?= htmlspecialchars($rec['estado'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                            <?php if (!empty($rec['motivo_devolucion'])): ?>
                                            <span class="text-xs text-[#dc2626]"><?= htmlspecialchars($rec['motivo_devolucion'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                            <?php if ($rec['estado'] !== 'devuelto'): ?>
                                            <form method="POST" action="index.php?action=admin_remesas" class="ml-auto flex items-center gap-2"
                                                onsubmit="return confirm('¿Marcar este recibo como devuelto? Volverá a quedar pendiente de cobro.');">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="accion" value="marcar_devuelto">
                                                <input type="hidden" name="id_recibo" value="<?= (int) $rec['id_recibo'] ?>">
                                                <input type="text" name="motivo" maxlength="120" placeholder="Motivo de la devolución"
                                                    class="rounded-lg border border-[#e4e4e7] bg-[#f4f4f5] px-3 py-1 text-xs text-neutral-700 focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                                                <button type="submit" class="rounded-full border border-[#fecaca] px-3 py-1 text-xs font-bold text-[#dc2626] hover:bg-[#fee2e2] transition">
                                                    Devuelto
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Recibos devueltos -->
            <?php if (!empty($devueltos)): ?>
            <div class="mt-6 rounded-[22px] border border-[#fecaca] bg-[#fef2f2] p-6 shadow-sm">
                <h2 class="text-lg font-bold text-[#dc2626]">Recibos devueltos</h2>
                <p class="mt-1 text-sm text-neutral-600">
                    Vuelven a estar pendientes de cobro y entrarán en la próxima remesa.
                </p>
                <ul class="mt-4 divide-y divide-[#fecaca]">
                    <?php foreach ($devueltos as $d): ?>
                    <li class="flex flex-wrap items-center gap-3 py-2.5 text-sm">
                        <span class="font-bold text-neutral-800"><?= htmlspecialchars($d['nombre_socio'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="text-neutral-600"><?= number_format((float) $d['importe'], 2, ',', '.') ?> €</span>
                        <span class="text-xs text-neutral-500"><?= htmlspecialchars($d['concepto_remesa'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="ml-auto text-xs text-[#dc2626]"><?= htmlspecialchars($d['motivo_devolucion'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Mandatos -->
            <div class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white shadow-sm overflow-hidden">
                <div class="px-6 pt-6 pb-4">
                    <h2 class="text-lg font-bold text-neutral-800">Mandatos firmados</h2>
                    <p class="mt-1 text-sm text-neutral-500">
                        Se firman desde la ficha del socio. Sin mandato no se puede domiciliar.
                    </p>
                </div>
                <?php if (empty($mandatos)): ?>
                    <p class="px-6 pb-6 text-sm text-neutral-500 italic">Ningún socio tiene mandato firmado todavía.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm min-w-[720px]">
                        <thead class="border-y border-[#e4e4e7] bg-[#f4f4f5]">
                            <tr>
                                <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Socio</th>
                                <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Referencia</th>
                                <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Cuenta</th>
                                <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Firmado</th>
                                <th class="px-6 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mandatos as $mn): ?>
                            <tr class="border-t border-[#e4e4e7] hover:bg-neutral-50 transition-colors">
                                <td class="px-6 py-3 font-bold text-neutral-800">
                                    <?= htmlspecialchars($mn['nombre'] . ' ' . $mn['apellidos'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="px-6 py-3 font-mono text-xs text-neutral-500"><?= htmlspecialchars($mn['referencia'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-6 py-3 font-mono text-[11px] text-neutral-500"><?= htmlspecialchars(Iban::enmascarar($mn['iban']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-6 py-3 text-neutral-500"><?= date('d/m/Y', strtotime($mn['fecha_firma'])) ?></td>
                                <td class="px-6 py-3">
                                    <span class="rounded-full px-3 py-1 text-xs font-bold
                                        <?= $mn['estado'] === 'activo' ? 'bg-[#dcfce7] text-[#15803d]' : 'bg-[#f4f4f5] text-neutral-500' ?>">
                                        <?= htmlspecialchars($mn['estado'], ENT_QUOTES, 'UTF-8') ?>
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

<script>
function marcarTodos(origen) {
    document.querySelectorAll('.chk-remesa').forEach(function (c) { c.checked = origen.checked; });
}
</script>

</div>
<?php require __DIR__ . '/../_footer.php'; ?>
