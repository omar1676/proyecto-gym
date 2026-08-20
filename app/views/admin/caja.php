<?php
require_once __DIR__ . '/../../helpers/Csrf.php';
require __DIR__ . '/../_header_admin.php';

$formato = static fn($valor): string => number_format((float) ($valor ?? 0), 2, ',', '.');
$etiquetaMetodo = [
    'efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'domiciliacion' => 'Domiciliación',
    'transferencia' => 'Transferencia', 'otro' => 'Otro',
];
?>
<main class="flex-1 bg-[#f7f7f8] px-5 py-8 lg:px-8">
    <section class="mx-auto max-w-6xl">
        <div class="pt-4 mb-7">
            <h1 class="text-2xl font-extrabold tracking-tight text-[#111318] sm:text-3xl">Caja</h1>
            <p class="mt-1.5 text-sm font-medium text-neutral-500">Apertura, movimientos y arqueo de efectivo por sede.</p>
        </div>

        <?php if ($mensajeExito !== ''): ?>
            <div class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800"><?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($errorCaja !== ''): ?>
            <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800"><?= htmlspecialchars($errorCaja, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if (!$sedeFijada): ?>
            <div class="rounded-[22px] border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
                Elige una sede concreta en la cabecera. Cada sede mantiene su propia caja y nunca se mezclan turnos entre locales.
            </div>
        <?php elseif (!$sesionCaja): ?>
            <div class="max-w-xl rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-sm">
                <h2 class="text-lg font-bold text-neutral-800">Abrir caja</h2>
                <p class="mt-1 text-sm text-neutral-500">Indica el efectivo que hay físicamente al comenzar el turno.</p>
                <form method="POST" action="index.php?action=admin_caja_operar" class="mt-5 space-y-4">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="operacion" value="abrir">
                    <div>
                        <label for="saldo-inicial" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Saldo inicial (€)</label>
                        <input id="saldo-inicial" name="saldo_inicial" inputmode="decimal" required value="0,00"
                               class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-[#4f46e5]">
                    </div>
                    <button class="rounded-full bg-[#111318] px-5 py-2.5 text-sm font-bold text-white">Confirmar apertura</button>
                </form>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white p-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-widest text-neutral-500">Saldo inicial</p>
                    <p class="mt-3 text-3xl font-extrabold text-[#111318]"><?= $formato($sesionCaja['saldo_inicial']) ?> €</p>
                </article>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white p-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-widest text-neutral-500">Movimientos de efectivo</p>
                    <p class="mt-3 text-3xl font-extrabold text-[#111318]"><?= $formato($sesionCaja['movimientos_efectivo']) ?> €</p>
                </article>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white p-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-widest text-neutral-500">Efectivo esperado</p>
                    <p class="mt-3 text-3xl font-extrabold text-[#4f46e5]"><?= $formato($sesionCaja['saldo_esperado_actual']) ?> €</p>
                </article>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <?php if ($puedeAjustarCaja): ?>
                <div class="rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-bold text-neutral-800">Entrada o salida manual</h2>
                    <p class="mt-1 text-xs text-neutral-500">Solo dirección/administración. El motivo es obligatorio y el movimiento no se puede borrar.</p>
                    <form method="POST" action="index.php?action=admin_caja_operar" class="mt-4 space-y-3">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="_operation_id" value="<?= bin2hex(random_bytes(16)) ?>">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-neutral-500 mb-1">Tipo</label>
                                <select name="operacion" class="w-full rounded-xl border border-[#e4e4e7] px-3 py-2.5 text-sm">
                                    <option value="ajuste_entrada">Entrada</option>
                                    <option value="ajuste_salida">Salida</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-neutral-500 mb-1">Importe (€)</label>
                                <input name="importe" inputmode="decimal" required class="w-full rounded-xl border border-[#e4e4e7] px-3 py-2.5 text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-neutral-500 mb-1">Motivo</label>
                            <input name="motivo" maxlength="255" required class="w-full rounded-xl border border-[#e4e4e7] px-3 py-2.5 text-sm">
                        </div>
                        <button class="rounded-full border-2 border-[#111318] px-5 py-2 text-sm font-bold text-[#111318]">Registrar movimiento</button>
                    </form>
                </div>
                <?php endif; ?>

                <div class="rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-bold text-neutral-800">Cerrar y arquear</h2>
                    <p class="mt-1 text-xs text-neutral-500">Cuenta el efectivo real. Toda diferencia quedará visible en el histórico.</p>
                    <form method="POST" action="index.php?action=admin_caja_operar" class="mt-4 space-y-3">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="operacion" value="cerrar">
                        <div>
                            <label class="block text-xs font-bold text-neutral-500 mb-1">Efectivo declarado (€)</label>
                            <input name="saldo_declarado" inputmode="decimal" required class="w-full rounded-xl border border-[#e4e4e7] px-3 py-2.5 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-neutral-500 mb-1">Observación</label>
                            <textarea name="observacion" maxlength="500" rows="2" class="w-full rounded-xl border border-[#e4e4e7] px-3 py-2.5 text-sm"></textarea>
                        </div>
                        <button class="rounded-full bg-[#111318] px-5 py-2.5 text-sm font-bold text-white" onclick="return confirm('¿Confirmar el cierre de caja?')">Cerrar caja</button>
                    </form>
                </div>
            </div>

            <div class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white shadow-sm overflow-hidden">
                <div class="px-6 py-5"><h2 class="text-lg font-bold text-neutral-800">Movimientos del turno</h2></div>
                <?php if (!$movimientosCaja): ?>
                    <p class="px-6 pb-6 text-sm italic text-neutral-500">Aún no hay movimientos en esta sesión.</p>
                <?php else: ?>
                    <div class="overflow-x-auto"><table class="w-full min-w-[760px] text-left text-sm">
                        <thead class="border-y border-[#e4e4e7] bg-[#f4f4f5]"><tr>
                            <th class="px-5 py-3">Hora</th><th class="px-5 py-3">Concepto</th><th class="px-5 py-3">Método</th><th class="px-5 py-3">Efectivo</th><th class="px-5 py-3 text-right">Importe</th>
                        </tr></thead><tbody>
                        <?php foreach ($movimientosCaja as $mov): ?>
                            <tr class="border-t border-[#e4e4e7]"><td class="px-5 py-3"><?= date('H:i:s', strtotime($mov['fecha'])) ?></td>
                                <td class="px-5 py-3 font-medium"><?= htmlspecialchars($mov['concepto'], ENT_QUOTES, 'UTF-8') ?><?php if ($mov['motivo']): ?><span class="block text-xs text-neutral-500"><?= htmlspecialchars($mov['motivo'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></td>
                                <td class="px-5 py-3"><?= $etiquetaMetodo[$mov['metodo']] ?? htmlspecialchars($mov['metodo'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-5 py-3"><?= (int) $mov['afecta_efectivo'] === 1 ? 'Sí' : 'No' ?></td>
                                <td class="px-5 py-3 text-right font-bold <?= (float) $mov['importe'] < 0 ? 'text-red-600' : 'text-green-700' ?>"><?= $formato($mov['importe']) ?> €</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($sedeFijada): ?>
        <div class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white shadow-sm overflow-hidden">
            <div class="px-6 py-5"><h2 class="text-lg font-bold text-neutral-800">Cierres anteriores</h2></div>
            <?php if (!$historialCaja): ?><p class="px-6 pb-6 text-sm italic text-neutral-500">Todavía no hay cajas cerradas.</p>
            <?php else: ?><div class="overflow-x-auto"><table class="w-full min-w-[800px] text-left text-sm">
                <thead class="border-y border-[#e4e4e7] bg-[#f4f4f5]"><tr><th class="px-5 py-3">Cierre</th><th class="px-5 py-3">Responsable</th><th class="px-5 py-3 text-right">Esperado</th><th class="px-5 py-3 text-right">Declarado</th><th class="px-5 py-3 text-right">Diferencia</th></tr></thead>
                <tbody><?php foreach ($historialCaja as $cierre): ?><tr class="border-t border-[#e4e4e7]"><td class="px-5 py-3"><?= date('d/m/Y H:i', strtotime($cierre['fecha_cierre'])) ?></td><td class="px-5 py-3"><?= htmlspecialchars(trim(($cierre['cierre_nombre'] ?? '') . ' ' . ($cierre['cierre_apellidos'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td><td class="px-5 py-3 text-right"><?= $formato($cierre['saldo_esperado']) ?> €</td><td class="px-5 py-3 text-right"><?= $formato($cierre['saldo_declarado']) ?> €</td><td class="px-5 py-3 text-right font-bold <?= (float) $cierre['diferencia'] === 0.0 ? 'text-green-700' : 'text-red-600' ?>"><?= $formato($cierre['diferencia']) ?> €</td></tr><?php endforeach; ?></tbody>
            </table></div><?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
</main>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
