<?php

require_once __DIR__ . '/../../helpers/Csrf.php';
require __DIR__ . '/../_header_admin.php';

$esAdmin = ($_SESSION['usuario_rol'] ?? '') === 'admin';

// Precios para calcular el total en el navegador antes de enviar el formulario.
$preciosJs = [];
foreach ($productosVenta as $p) {
    $preciosJs[(int) $p['id_producto']] = [
        'precio' => (float) $p['precio'],
        'stock'  => (int) $p['stock'],
        'nombre' => $p['nombre'],
    ];
}

$etiquetasPago = [
    'efectivo'      => 'Efectivo',
    'datafono'      => 'Datáfono',
    'transferencia' => 'Transferencia',
];
?>
    <main class="flex-1 bg-[#f7f7f8] px-5 py-8 lg:px-8">
        <section class="mx-auto max-w-6xl">

            <div class="flex flex-col gap-3 pt-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-[#111318] sm:text-3xl">Ventas</h1>
                    <p class="mt-1.5 text-sm font-medium text-neutral-500">
                        Registro de ventas de mostrador con descuento automático de stock.
                    </p>
                </div>
                <div class="pt-1 text-right">
                    <p class="text-sm font-medium text-neutral-500">Caja de hoy</p>
                    <p class="text-2xl font-extrabold text-[#111318]"><?= number_format($ventasHoy, 2, ',', '.') ?> €</p>
                    <p class="text-xs text-neutral-500"><?= (int) $numVentasHoy ?> ticket<?= (int) $numVentasHoy === 1 ? '' : 's' ?></p>
                </div>
            </div>

            <?php if ($mensajeExito !== ''): ?>
            <div class="mt-5 rounded-[14px] border border-[#bbf7d0] bg-[#f0fdf4] px-5 py-3 text-sm font-bold text-[#111318]">
                <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <?php if ($errorVenta !== ''): ?>
            <div class="mt-5 rounded-[14px] border border-red-200 bg-red-50 px-5 py-3 text-sm font-bold text-red-600">
                <?= htmlspecialchars($errorVenta, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <!-- Registro rápido de venta -->
            <div class="mt-8 rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-sm">
                <h2 class="text-lg font-bold text-neutral-700 mb-5">Registrar venta</h2>

                <?php if (empty($sedeFijada)): ?>
                    <div class="rounded-[14px] border border-[#fcd34d] bg-[#fffbeb] px-5 py-4 text-sm text-[#92400e]" role="status">
                        <p class="font-extrabold">Selecciona una sede antes de iniciar una venta.</p>
                        <p class="mt-1">El stock, la caja y la numeración del ticket pertenecen a una sede concreta. Usa el selector de la cabecera.</p>
                    </div>
                <?php elseif (empty($productosVenta)): ?>
                    <p class="text-sm text-neutral-500 italic">
                        No hay productos activos con stock disponible.
                        <?php if ($esAdmin): ?>
                        <a href="index.php?action=admin_productos" class="text-[#111318] font-bold hover:underline">Añade productos</a>
                        para poder vender.
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                <form method="POST" action="index.php?action=admin_venta_registrar" id="form-venta">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="_operation_id" value="<?= bin2hex(random_bytes(16)) ?>">

                    <div id="lineas-venta" class="space-y-3">
                        <!-- Las líneas se clonan desde la plantilla de abajo -->
                    </div>

                    <button type="button" onclick="anadirLinea()"
                        class="mt-3 inline-flex items-center gap-2 rounded-full border border-[#e4e4e7] px-4 py-2 text-xs font-bold text-neutral-500 hover:bg-neutral-50 transition">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        Añadir otro producto
                    </button>

                    <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3 border-t border-[#e4e4e7] pt-5">
                        <div>
                            <label for="venta-socio" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                                Socio (opcional)
                            </label>
                            <select id="venta-socio" name="id_socio"
                                class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                                <option value="0">Cliente de paso</option>
                                <?php foreach ($socios as $socio): ?>
                                <option value="<?= (int) $socio['id_usuario'] ?>">
                                    <?= htmlspecialchars($socio['apellidos'] . ', ' . $socio['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="venta-metodo-pago" class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                                Método de pago <span class="text-red-400">*</span>
                            </label>
                            <select id="venta-metodo-pago" name="metodo_pago" required
                                class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                                <?php foreach ($etiquetasPago as $valor => $etiqueta): ?>
                                <option value="<?= $valor ?>"><?= $etiqueta ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex flex-col justify-end">
                            <p class="text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Total</p>
                            <p id="total-venta" class="text-3xl font-extrabold leading-none text-[#111318]">0,00 €</p>
                        </div>
                    </div>

                    <div class="mt-6 flex gap-3">
                        <button type="submit"
                            class="rounded-full bg-[#111318] px-8 py-2.5 text-sm font-bold text-white hover:brightness-110 transition-all">
                            Cobrar
                        </button>
                        <button type="button" onclick="reiniciarVenta()"
                            class="rounded-full border border-[#e4e4e7] px-6 py-2.5 text-sm font-bold text-neutral-500 hover:bg-neutral-50 transition-all">
                            Limpiar
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>

            <!-- Filtro por fechas -->
            <div class="mt-8 flex flex-wrap items-end gap-3">
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="action" value="admin_ventas">
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
                    Filtrar
                </button>
            </form>
                <?php if ($esAdmin): ?>
                <form method="POST" action="index.php?action=admin_exportar_ventas_csv" class="inline">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="desde" value="<?= htmlspecialchars($desde, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="hasta" value="<?= htmlspecialchars($hasta, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit"
                        class="rounded-full border border-[#e4e4e7] px-5 py-2.5 text-sm font-bold text-neutral-500 hover:bg-neutral-50 transition">
                        Exportar CSV
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Desglose por método de pago -->
            <?php if (!empty($porMetodo)): ?>
            <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-4">
                <?php foreach ($porMetodo as $metodo): ?>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">
                        <?= $etiquetasPago[$metodo['metodo_pago']] ?? htmlspecialchars($metodo['metodo_pago'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <div class="mt-4"><span class="text-2xl font-extrabold leading-none text-[#52525b]"><?= number_format((float) $metodo['importe'], 2, ',', '.') ?> €</span></div>
                    <p class="mt-1 text-xs text-neutral-500"><?= (int) $metodo['num_ventas'] ?> venta<?= (int) $metodo['num_ventas'] === 1 ? '' : 's' ?></p>
                </article>
                <?php endforeach; ?>
                <article class="rounded-[20px] border border-[#bbf7d0] bg-[#f0fdf4] px-6 py-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#111318]">Total del periodo</p>
                    <div class="mt-4"><span class="text-2xl font-extrabold leading-none text-[#111318]"><?= number_format($totalRango, 2, ',', '.') ?> €</span></div>
                    <p class="mt-1 text-xs text-[#111318]/70"><?= count($ventas) ?> venta<?= count($ventas) === 1 ? '' : 's' ?></p>
                </article>
            </div>
            <?php endif; ?>

            <!-- Listado de ventas -->
            <div class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white shadow-sm overflow-hidden">
                <?php if (empty($ventas)): ?>
                    <p class="p-6 text-sm text-neutral-500 italic">No hay ventas registradas en este periodo.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm min-w-[820px]">
                            <thead class="border-b border-[#e4e4e7] bg-[#f4f4f5]">
                                <tr>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Nº</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Fecha</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Detalle</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Socio</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Pago</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500 text-right">Total</th>
                                    <?php if ($esAdmin): ?>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500"></th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ventas as $venta):
                                    // Una venta anulada no se borra: se queda en
                                    // el listado, atenuada y marcada, para que el
                                    // hueco en la numeración tenga explicación.
                                    $anulada = ($venta['estado'] ?? 'activa') === 'anulada';
                                ?>
                                <tr class="border-t border-[#e4e4e7] transition-colors <?= $anulada ? 'bg-[#fafafa] text-neutral-400' : 'hover:bg-neutral-50' ?>">
                                    <td class="px-5 py-3 font-bold <?= $anulada ? 'text-neutral-400 line-through' : 'text-neutral-500' ?>">
                                        <?= htmlspecialchars(VentaModel::referencia($venta), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-5 py-3 text-neutral-500"><?= date('d/m/Y H:i', strtotime($venta['fecha'])) ?></td>
                                    <td class="px-5 py-3 <?= $anulada ? 'text-neutral-400' : 'text-neutral-700' ?>">
                                        <?= htmlspecialchars($venta['detalle'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                        <?php if ($anulada): ?>
                                        <span class="ml-2 inline-flex rounded-full bg-[#fee2e2] px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide text-[#dc2626]">Anulada</span>
                                        <?php if (!empty($venta['motivo_anulacion'])): ?>
                                        <span class="block text-[11px] text-neutral-400"><?= htmlspecialchars($venta['motivo_anulacion'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 text-neutral-500">
                                        <?php $nombreSocio = trim(($venta['nombre_socio'] ?? '') . ' ' . ($venta['apellidos_socio'] ?? '')); ?>
                                        <?= $nombreSocio !== ''
                                            ? htmlspecialchars($nombreSocio, ENT_QUOTES, 'UTF-8')
                                            : '<span class="text-neutral-300">Cliente de paso</span>' ?>
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex rounded-full bg-neutral-100 px-3 py-1 text-xs font-bold text-neutral-500">
                                            <?= $etiquetasPago[$venta['metodo_pago']] ?? htmlspecialchars($venta['metodo_pago'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-right font-extrabold <?= $anulada ? 'text-neutral-400 line-through' : 'text-neutral-700' ?>">
                                        <?= number_format((float) $venta['total'], 2, ',', '.') ?> €
                                        <?php if (!$anulada && (float) ($venta['total_iva'] ?? 0) > 0): ?>
                                        <span class="block text-[11px] font-medium text-neutral-400">
                                            base <?= number_format((float) $venta['base_imponible'], 2, ',', '.') ?>
                                            + IVA <?= number_format((float) $venta['total_iva'], 2, ',', '.') ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($esAdmin): ?>
                                    <td class="px-5 py-3 text-right">
                                        <?php if ($anulada): ?>
                                        <span class="text-xs text-neutral-400">—</span>
                                        <?php else: ?>
                                        <!-- Se pide el motivo: es lo que después explica el hueco en la caja -->
                                        <form method="POST" action="index.php?action=admin_venta_anular" style="display:inline"
                                            onsubmit="var m = prompt('¿Por qué se anula el ticket <?= htmlspecialchars(VentaModel::referencia($venta), ENT_QUOTES, 'UTF-8') ?>?\nSe devolverá el stock y la venta quedará marcada como anulada.'); if (m === null) return false; this.motivo.value = m; return true;">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="id_venta" value="<?= (int) $venta['id_venta'] ?>">
                                            <input type="hidden" name="motivo" value="">
                                            <button type="submit"
                                                class="rounded-full border border-[#e4e4e7] px-3 py-1 text-xs font-bold text-neutral-500 hover:border-red-200 hover:text-red-500 transition">
                                                Anular
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
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
var PRODUCTOS = <?= json_encode($preciosJs, JSON_UNESCAPED_UNICODE) ?>;

function opcionesProductos() {
    var html = '<option value="0">— Selecciona producto —</option>';
    for (var id in PRODUCTOS) {
        html += '<option value="' + id + '">'
              + PRODUCTOS[id].nombre.replace(/</g, '&lt;')
              + ' (' + PRODUCTOS[id].precio.toFixed(2).replace('.', ',') + ' € · '
              + PRODUCTOS[id].stock + ' uds.)</option>';
    }
    return html;
}

function anadirLinea() {
    var cont = document.getElementById('lineas-venta');
    var fila = document.createElement('div');
    fila.className = 'flex flex-wrap items-end gap-3 linea-venta';
    fila.innerHTML =
        '<div class="flex-1 min-w-[220px]">' +
            '<label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Producto</label>' +
            '<select name="productos[]" onchange="calcularTotal()" required ' +
                'class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">' +
                opcionesProductos() +
            '</select>' +
        '</div>' +
        '<div class="w-28">' +
            '<label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Cantidad</label>' +
            '<input type="number" name="cantidades[]" min="1" value="1" onchange="calcularTotal()" oninput="calcularTotal()" required ' +
                'class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">' +
        '</div>' +
        '<button type="button" onclick="quitarLinea(this)" title="Quitar línea" aria-label="Quitar línea de producto" ' +
            'class="mb-1 rounded-full border border-[#e4e4e7] px-3 py-2 text-xs font-bold text-neutral-500 hover:border-red-200 hover:text-red-500 transition">✕</button>';
    cont.appendChild(fila);
    calcularTotal();
}

function quitarLinea(boton) {
    var filas = document.querySelectorAll('.linea-venta');
    if (filas.length <= 1) return;   // siempre queda al menos una línea
    boton.parentNode.remove();
    calcularTotal();
}

function calcularTotal() {
    var total = 0;
    var filas = document.querySelectorAll('.linea-venta');
    filas.forEach(function (fila) {
        var id  = fila.querySelector('select').value;
        var cant = parseInt(fila.querySelector('input').value, 10) || 0;
        if (PRODUCTOS[id]) {
            total += PRODUCTOS[id].precio * cant;
        }
    });
    document.getElementById('total-venta').textContent =
        total.toFixed(2).replace('.', ',') + ' €';
}

function reiniciarVenta() {
    document.getElementById('lineas-venta').innerHTML = '';
    anadirLinea();
}

// Primera línea al cargar la página.
if (document.getElementById('lineas-venta')) {
    anadirLinea();
}
</script>

</div>
<?php require __DIR__ . '/../_footer.php'; ?>
