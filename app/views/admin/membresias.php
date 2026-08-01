<?php

require_once __DIR__ . '/../../helpers/Csrf.php';
require __DIR__ . '/../_header_admin.php';
?>
    <main class="flex-1 bg-[#f7f7f8] px-5 py-8 lg:px-8">
        <section class="mx-auto max-w-6xl">

            <div class="flex flex-col gap-3 pt-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-[#111318] sm:text-3xl">Tipos de membresía</h1>
                    <p class="mt-1.5 text-sm font-medium text-neutral-500">
                        Catálogo de cuotas: precio y duración de cada modalidad.
                    </p>
                </div>
                <button onclick="abrirModalTipo()"
                    class="mt-2 sm:mt-0 inline-flex items-center gap-2 rounded-full bg-[#111318] px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:brightness-110 transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nueva modalidad
                </button>
            </div>

            <?php if ($mensajeExito !== ''): ?>
            <div class="mt-5 rounded-[14px] border border-[#bbf7d0] bg-[#f0fdf4] px-5 py-3 text-sm font-bold text-[#111318]">
                <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <?php if ($errorMembresia !== ''): ?>
            <div class="mt-5 rounded-[14px] border border-red-200 bg-red-50 px-5 py-3 text-sm font-bold text-red-600">
                <?= htmlspecialchars($errorMembresia, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <!-- Tarjetas de estadísticas -->
            <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-3">
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
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Ingresos del mes</p>
                    <div class="mt-5"><span class="text-3xl font-extrabold leading-none text-[#52525b]"><?= number_format($ingresosMes, 2, ',', '.') ?> €</span></div>
                    <div class="mt-6 h-1.5 rounded-full bg-[#e4e4e7]"><div class="h-full w-[70%] rounded-full bg-[#4f46e5]"></div></div>
                </article>
            </div>

            <!-- Tabla de modalidades -->
            <div class="mt-8 rounded-[22px] border border-[#e4e4e7] bg-white shadow-sm overflow-hidden">
                <?php if (empty($tipos)): ?>
                    <p class="p-6 text-sm text-neutral-500 italic">No hay modalidades de membresía definidas todavía.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm min-w-[720px]">
                            <thead class="border-b border-[#e4e4e7] bg-[#f4f4f5]">
                                <tr>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Modalidad</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Precio</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Duración</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Estado</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tipos as $tipo): ?>
                                <tr class="border-t border-[#e4e4e7] hover:bg-neutral-50 transition-colors">
                                    <td class="px-5 py-3 font-bold text-neutral-700">
                                        <?= htmlspecialchars($tipo['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($tipo['descripcion'])): ?>
                                        <span class="block text-xs font-medium text-neutral-500">
                                            <?= htmlspecialchars($tipo['descripcion'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 font-bold text-neutral-700"><?= number_format((float) $tipo['precio'], 2, ',', '.') ?> €</td>
                                    <td class="px-5 py-3 text-neutral-500">
                                        <?= (int) $tipo['duracion_meses'] ?> mes<?= (int) $tipo['duracion_meses'] === 1 ? '' : 'es' ?>
                                    </td>
                                    <td class="px-5 py-3">
                                        <form method="POST" action="index.php?action=admin_membresias" style="display:inline">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="accion" value="toggle_estado_tipo">
                                            <input type="hidden" name="id_tipo_membresia" value="<?= (int) $tipo['id_tipo_membresia'] ?>">
                                            <button type="submit"
                                                title="Pulsa para cambiar el estado"
                                                class="inline-flex rounded-full px-3 py-1 text-xs font-bold cursor-pointer transition hover:brightness-95
                                                    <?= $tipo['estado'] === 'activo' ? 'bg-[#dcfce7] text-[#15803d]' : 'bg-neutral-100 text-neutral-500' ?>">
                                                <?= htmlspecialchars($tipo['estado'], ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <button type="button"
                                            onclick='editarTipo(<?= json_encode([
                                                "id"          => (int) $tipo["id_tipo_membresia"],
                                                "nombre"      => $tipo["nombre"],
                                                "descripcion" => $tipo["descripcion"] ?? "",
                                                "precio"      => $tipo["precio"],
                                                "iva"         => $tipo["iva"] ?? 21,
                                                "duracion"    => (int) $tipo["duracion_meses"],
                                                "estado"      => $tipo["estado"],
                                            ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'
                                            class="rounded-full border border-[#e4e4e7] px-3 py-1 text-xs font-bold text-neutral-500 hover:bg-neutral-50 transition">
                                            Editar
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <p class="mt-4 text-xs text-neutral-500">
                Desactivar una modalidad no afecta a las membresías ya contratadas: solo deja de ofrecerse en el alta.
            </p>

        </section>
    </main>

<!-- Modal alta / edición de modalidad -->
<div id="modal-tipo"
     class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6 overflow-y-auto"
     onclick="if(event.target===this) cerrarModalTipo()">

    <div class="w-full max-w-lg rounded-[24px] bg-white p-8 shadow-2xl my-auto">

        <div class="flex items-center justify-between mb-6">
            <h2 id="modal-tipo-titulo" class="text-xl font-extrabold text-neutral-800">Nueva modalidad</h2>
            <button onclick="cerrarModalTipo()"
                class="text-neutral-500 hover:text-neutral-600 transition-colors text-2xl leading-none"
                aria-label="Cerrar">&times;</button>
        </div>

        <form method="POST" action="index.php?action=admin_membresias" class="space-y-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="accion"            id="tipo-accion" value="crear_tipo">
            <input type="hidden" name="id_tipo_membresia" id="tipo-id"     value="0">

            <div>
                <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                    Nombre <span class="text-red-400">*</span>
                </label>
                <input type="text" name="nombre" id="tipo-nombre" required maxlength="100"
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition"
                    placeholder="Ej: Mensual mañanas">
            </div>

            <div>
                <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                    Descripción
                </label>
                <input type="text" name="descripcion" id="tipo-descripcion" maxlength="255"
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition"
                    placeholder="Acceso de 7:00 a 15:00">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Precio con IVA (€) <span class="text-red-400">*</span>
                    </label>
                    <input type="number" name="precio" id="tipo-precio" required min="0" step="0.01"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition"
                        placeholder="35.00">
                    <p class="mt-1 text-[11px] text-neutral-400">Lo que paga el socio.</p>
                </div>
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Duración (meses) <span class="text-red-400">*</span>
                    </label>
                    <input type="number" name="duracion_meses" id="tipo-duracion" required min="1" max="60" value="1"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        IVA (%)
                    </label>
                    <input type="number" name="iva" id="tipo-iva" min="0" max="100" step="0.01" value="21"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
            </div>

            <div>
                <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                    Estado <span class="text-red-400">*</span>
                </label>
                <select name="estado" id="tipo-estado" required
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                    <option value="activo">Activo</option>
                    <option value="inactivo">Inactivo</option>
                </select>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                    class="flex-1 rounded-full bg-[#4f46e5] py-2.5 text-sm font-bold text-white hover:brightness-110 transition-all">
                    Guardar modalidad
                </button>
                <button type="button" onclick="cerrarModalTipo()"
                    class="flex-1 rounded-full border border-[#e4e4e7] py-2.5 text-sm font-bold text-neutral-500 hover:bg-neutral-50 transition-all">
                    Cancelar
                </button>
            </div>
        </form>

    </div>
</div>

<script>
function abrirModalTipo() {
    document.getElementById('modal-tipo-titulo').textContent = 'Nueva modalidad';
    document.getElementById('tipo-accion').value = 'crear_tipo';
    document.getElementById('tipo-id').value     = 0;
    document.getElementById('tipo-nombre').value = '';
    document.getElementById('tipo-descripcion').value = '';
    document.getElementById('tipo-precio').value = '';
    document.getElementById('tipo-iva').value = 21;
    document.getElementById('tipo-duracion').value = 1;
    document.getElementById('tipo-estado').value = 'activo';
    document.getElementById('modal-tipo').classList.remove('hidden');
}

function editarTipo(datos) {
    document.getElementById('modal-tipo-titulo').textContent = 'Editar modalidad';
    document.getElementById('tipo-accion').value = 'editar_tipo';
    document.getElementById('tipo-id').value     = datos.id;
    document.getElementById('tipo-nombre').value = datos.nombre;
    document.getElementById('tipo-descripcion').value = datos.descripcion;
    document.getElementById('tipo-precio').value = datos.precio;
    document.getElementById('tipo-iva').value = datos.iva || 21;
    document.getElementById('tipo-duracion').value = datos.duracion;
    document.getElementById('tipo-estado').value = datos.estado;
    document.getElementById('modal-tipo').classList.remove('hidden');
}

function cerrarModalTipo() {
    document.getElementById('modal-tipo').classList.add('hidden');
}
</script>

</div>
<?php require __DIR__ . '/../_footer.php'; ?>
