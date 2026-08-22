<?php

require_once __DIR__ . '/../../helpers/Csrf.php';
require __DIR__ . '/../_header_admin.php';
?>
    <main class="flex-1 bg-[#f7f7f8] px-5 py-8 lg:px-8">
        <section class="mx-auto max-w-6xl">

            <!-- Cabecera con botón Añadir producto -->
            <div class="flex flex-col gap-3 pt-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-[#111318] sm:text-3xl">Gestión de productos</h1>
                    <p class="mt-1.5 text-sm font-medium text-neutral-500">
                        Catálogo de bebidas, suplementos y merchandising con control de stock.
                    </p>
                </div>
                <button
                    onclick="abrirModalProducto()"
                    class="mt-2 sm:mt-0 inline-flex items-center gap-2 rounded-full bg-[#111318] px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:brightness-110 transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Añadir producto
                </button>
            </div>

            <!-- Mensaje de éxito -->
            <?php if ($mensajeExito !== ''): ?>
            <div class="mt-5 rounded-[14px] border border-[#bbf7d0] bg-[#f0fdf4] px-5 py-3 text-sm font-bold text-[#111318]">
                <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <!-- Error de validación -->
            <?php if ($errorProducto !== ''): ?>
            <div class="mt-5 rounded-[14px] border border-red-200 bg-red-50 px-5 py-3 text-sm font-bold text-red-600">
                <?= htmlspecialchars($errorProducto, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="<?= APP_URL ?>/index.php?action=admin_productos"
                  class="mt-6 flex flex-wrap items-end gap-3 rounded-[18px] border border-[#e4e4e7] bg-white p-4 shadow-sm">
                <?= Csrf::field() ?>
                <input type="hidden" name="accion" value="crear_categoria">
                <div class="min-w-[220px] flex-1">
                    <label for="nueva-categoria" class="mb-1 block text-xs font-extrabold uppercase tracking-widest text-neutral-500">Nueva categoría</label>
                    <input id="nueva-categoria" name="nombre_categoria" maxlength="100" required
                           class="w-full rounded-xl border border-[#e4e4e7] bg-white px-4 py-2.5 text-sm"
                           placeholder="Ej. Bebidas">
                </div>
                <button type="submit" class="rounded-full bg-[#4f46e5] px-5 py-2.5 text-sm font-bold text-white">Crear categoría</button>
            </form>

            <!-- Tarjetas de estadísticas -->
            <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Productos</p>
                    <div class="mt-5"><span class="text-4xl font-extrabold leading-none text-[#52525b]"><?= (int) $totalProductos ?></span></div>
                    <div class="mt-6 h-1.5 rounded-full bg-[#e4e4e7]"><div class="h-full w-[82%] rounded-full bg-[#4f46e5]"></div></div>
                </article>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Activos</p>
                    <div class="mt-5"><span class="text-4xl font-extrabold leading-none text-[#111318]"><?= (int) $productosActivos ?></span></div>
                    <div class="mt-6 h-1.5 rounded-full bg-[#e4e4e7]"><div class="h-full w-[88%] rounded-full bg-[#4f46e5]"></div></div>
                </article>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Bajo stock</p>
                    <div class="mt-5">
                        <span class="text-4xl font-extrabold leading-none <?= $numBajoStock > 0 ? 'text-[#111318]' : 'text-neutral-300' ?>"><?= (int) $numBajoStock ?></span>
                    </div>
                    <div class="mt-6 h-1.5 rounded-full bg-[#e4e4e7]"><div class="h-full w-[45%] rounded-full bg-[#4f46e5]"></div></div>
                </article>
                <article class="rounded-[20px] border border-[#e4e4e7] bg-white px-6 py-5 shadow-sm">
                    <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-500">Valor inventario</p>
                    <div class="mt-5"><span class="text-3xl font-extrabold leading-none text-[#111318]"><?= number_format($valorInventario, 2, ',', '.') ?> €</span></div>
                    <div class="mt-6 h-1.5 rounded-full bg-[#e4e4e7]"><div class="h-full w-[60%] rounded-full bg-[#4f46e5]"></div></div>
                </article>
            </div>

            <!-- Buscador -->
            <form method="GET" class="mt-6 flex flex-wrap gap-3 items-center">
                <input type="hidden" name="action" value="admin_productos">
                <input type="text" name="buscar"
                    value="<?= htmlspecialchars($busqueda ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Buscar por nombre, descripción o categoría..."
                    class="flex-1 min-w-[220px] rounded-xl border border-[#e4e4e7] bg-white px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                <button type="submit"
                    class="rounded-full bg-[#111318] px-5 py-2.5 text-sm font-bold text-white hover:brightness-110 transition">
                    Buscar
                </button>
                <?php if (!empty($busqueda)): ?>
                <a href="index.php?action=admin_productos"
                    class="rounded-full border border-[#e4e4e7] px-5 py-2.5 text-sm font-bold text-neutral-500 hover:bg-neutral-50 transition">
                    Limpiar
                </a>
                <?php endif; ?>
            </form>

            <?php if (!empty($busqueda)): ?>
            <p class="mt-3 text-sm text-neutral-500">
                Mostrando resultados para <strong>"<?= htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8') ?>"</strong>
                — <?= count($productos) ?> producto<?= count($productos) === 1 ? '' : 's' ?> encontrado<?= count($productos) === 1 ? '' : 's' ?>.
            </p>
            <?php endif; ?>

            <!-- Tabla de productos -->
            <div class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white shadow-sm overflow-hidden">
                <?php if (empty($productos)): ?>
                    <p class="p-6 text-sm text-neutral-500 italic">
                        <?= !empty($busqueda) ? 'No hay productos que coincidan con la búsqueda.' : 'No hay productos registrados todavía.' ?>
                    </p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm min-w-[920px]">
                            <thead class="border-b border-[#e4e4e7] bg-[#f4f4f5]">
                                <tr>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500 w-20">Imagen</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Producto</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Categoría</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Precio</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Stock</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Mínimo</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Estado</th>
                                    <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($productos as $producto):
                                    $tieneImagen = !empty($producto['imagen']);
                                    $bajoMinimo  = (int) $producto['stock'] <= (int) $producto['stock_minimo'];
                                ?>
                                <tr class="border-t border-[#e4e4e7] hover:bg-neutral-50 transition-colors">
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-2">
                                            <form method="POST" action="<?= APP_URL ?>/index.php?action=admin_subir_imagen_producto" enctype="multipart/form-data" class="relative group">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="id_producto" value="<?= (int) $producto['id_producto'] ?>">
                                                <label class="cursor-pointer block relative" title="Cambiar imagen del producto">
                                                    <div class="w-14 h-10 rounded-lg overflow-hidden relative bg-gradient-to-br from-[#3f3f3f] to-[#111111]">
                                                        <div class="absolute inset-0 flex items-center justify-center">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-white/90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7 12 3 4 7m16 0-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                                            </svg>
                                                        </div>
                                                        <?php if ($tieneImagen): ?>
                                                        <img src="assets/productos/<?= htmlspecialchars($producto['imagen'], ENT_QUOTES, 'UTF-8') ?>"
                                                            class="absolute inset-0 w-full h-full object-cover"
                                                            onerror="this.remove()">
                                                        <?php endif; ?>
                                                        <span class="absolute inset-0 bg-black/0 group-hover:bg-black/45 flex items-center justify-center transition-all opacity-0 group-hover:opacity-100">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 0 1 2-2h2l2-2h6l2 2h2a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9Zm9 9a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/>
                                                            </svg>
                                                        </span>
                                                    </div>
                                                    <input type="file" name="imagen" accept="image/*" class="hidden" onchange="this.form.submit()">
                                                </label>
                                            </form>
                                            <?php if ($tieneImagen): ?>
                                            <form method="POST" action="<?= APP_URL ?>/index.php?action=admin_quitar_imagen_producto" style="display:inline"
                                                onsubmit="return confirm('¿Quitar la imagen de este producto?');">
                                                <?= Csrf::field() ?>
                                                <input type="hidden" name="id_producto" value="<?= (int) $producto['id_producto'] ?>">
                                                <button type="submit" title="Quitar imagen" aria-label="Quitar imagen de <?= htmlspecialchars($producto['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                                                    class="text-neutral-500 hover:text-red-500 transition text-xs">✕</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3 font-bold text-neutral-700">
                                        <?= htmlspecialchars($producto['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($producto['descripcion'])): ?>
                                        <span class="block text-xs font-medium text-neutral-500 truncate max-w-[220px]">
                                            <?= htmlspecialchars($producto['descripcion'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 text-neutral-500">
                                        <?= !empty($producto['nombre_categoria'])
                                            ? htmlspecialchars($producto['nombre_categoria'], ENT_QUOTES, 'UTF-8')
                                            : '<span class="text-neutral-300">—</span>' ?>
                                    </td>
                                    <td class="px-5 py-3 font-bold text-neutral-700"><?= number_format((float) $producto['precio'], 2, ',', '.') ?> €</td>
                                    <td class="px-5 py-3">
                                        <form method="POST" action="index.php?action=admin_productos" class="inline-flex items-center gap-1.5">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="accion" value="actualizar_stock">
                                            <input type="hidden" name="id_producto" value="<?= (int) $producto['id_producto'] ?>">
                                            <label for="stock-producto-<?= (int) $producto['id_producto'] ?>" class="sr-only">Stock de <?= htmlspecialchars($producto['nombre'], ENT_QUOTES, 'UTF-8') ?></label>
                                            <input type="number" id="stock-producto-<?= (int) $producto['id_producto'] ?>" name="stock" min="0" max="99999"
                                                value="<?= (int) $producto['stock'] ?>"
                                                class="w-20 rounded-lg border px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-[#4f46e5]
                                                    <?= $bajoMinimo ? 'border-[#111318] text-[#111318] font-bold' : 'border-[#e4e4e7] text-neutral-700' ?>">
                                            <button type="submit" title="Guardar stock" aria-label="Guardar stock de <?= htmlspecialchars($producto['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                                                class="rounded-md bg-[#e4e4e7] px-2 py-1 text-xs font-bold text-[#111318] hover:bg-[#4338ca] transition">✓</button>
                                        </form>
                                    </td>
                                    <td class="px-5 py-3 text-neutral-500"><?= (int) $producto['stock_minimo'] ?></td>
                                    <td class="px-5 py-3">
                                        <form method="POST" action="index.php?action=admin_productos" style="display:inline">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="accion" value="toggle_estado_producto">
                                            <input type="hidden" name="id_producto" value="<?= (int) $producto['id_producto'] ?>">
                                            <button type="submit"
                                                title="Pulsa para cambiar el estado"
                                                class="inline-flex rounded-full px-3 py-1 text-xs font-bold cursor-pointer transition hover:brightness-95
                                                    <?= $producto['estado'] === 'activo' ? 'bg-[#dcfce7] text-[#15803d]' : 'bg-neutral-100 text-neutral-500' ?>">
                                                <?= htmlspecialchars($producto['estado'], ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <button type="button"
                                            onclick='editarProducto(<?= json_encode([
                                                "id"           => (int) $producto["id_producto"],
                                                "nombre"       => $producto["nombre"],
                                                "descripcion"  => $producto["descripcion"] ?? "",
                                                "precio"       => $producto["precio"],
                                                "iva"          => $producto["iva"] ?? 21,
                                                "stock_minimo" => (int) $producto["stock_minimo"],
                                                "estado"       => $producto["estado"],
                                                "categoria"    => (int) ($producto["id_categoria"] ?? 0),
                                            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'
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

        </section>
    </main>

<!-- Modal alta / edición de producto -->
<div id="modal-producto"
     class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6 overflow-y-auto"
     onclick="if(event.target===this) cerrarModalProducto()">

    <div class="w-full max-w-lg rounded-[24px] bg-white p-8 shadow-2xl my-auto">

        <div class="flex items-center justify-between mb-6">
            <h2 id="modal-producto-titulo" class="text-xl font-extrabold text-neutral-800">Nuevo producto</h2>
            <button onclick="cerrarModalProducto()"
                class="text-neutral-500 hover:text-neutral-600 transition-colors text-2xl leading-none"
                aria-label="Cerrar">&times;</button>
        </div>

        <form method="POST" action="index.php?action=admin_productos" class="space-y-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="accion"      id="producto-accion" value="crear_producto">
            <input type="hidden" name="id_producto" id="producto-id"     value="0">

            <div>
                <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                    Nombre <span class="text-red-400">*</span>
                </label>
                <input type="text" name="nombre" id="producto-nombre" required maxlength="150"
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition"
                    placeholder="Ej: Batido de proteína 500 ml">
            </div>

            <div>
                <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                    Descripción
                </label>
                <textarea name="descripcion" id="producto-descripcion" maxlength="200" rows="2"
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition resize-none"
                    placeholder="Descripción breve del producto..."></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Precio con IVA (€) <span class="text-red-400">*</span>
                    </label>
                    <input type="number" name="precio" id="producto-precio" required min="0" step="0.01"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition"
                        placeholder="2.50">
                    <p class="mt-1 text-[11px] text-neutral-400">Lo que paga el cliente. El desglose se calcula solo.</p>
                </div>
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        IVA (%)
                    </label>
                    <input type="number" name="iva" id="producto-iva" min="0" max="100" step="0.01" value="21"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Categoría
                    </label>
                    <select name="id_categoria" id="producto-categoria"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                        <option value="0">— Sin categoría —</option>
                        <?php foreach ($listaCategorias as $categoria): ?>
                        <option value="<?= (int) $categoria['id_categoria'] ?>">
                            <?= htmlspecialchars($categoria['nombre_categoria'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div id="producto-stock-wrap">
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Stock inicial <span class="text-red-400">*</span>
                    </label>
                    <input type="number" name="stock" id="producto-stock" min="0" value="0"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                    <p class="mt-1 text-[11px] text-neutral-500">Al editar, el stock se cambia desde la tabla.</p>
                </div>
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                        Stock mínimo <span class="text-red-400">*</span>
                    </label>
                    <input type="number" name="stock_minimo" id="producto-stock-minimo" required min="0" value="5"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                    <p class="mt-1 text-[11px] text-neutral-500">Avisa cuando el stock baje de aquí.</p>
                </div>
            </div>

            <div>
                <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                    Estado <span class="text-red-400">*</span>
                </label>
                <select name="estado" id="producto-estado" required
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                    <option value="activo">Activo</option>
                    <option value="inactivo">Inactivo</option>
                </select>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                    class="flex-1 rounded-full bg-[#4f46e5] py-2.5 text-sm font-bold text-white hover:brightness-110 transition-all">
                    Guardar producto
                </button>
                <button type="button" onclick="cerrarModalProducto()"
                    class="flex-1 rounded-full border border-[#e4e4e7] py-2.5 text-sm font-bold text-neutral-500 hover:bg-neutral-50 transition-all">
                    Cancelar
                </button>
            </div>
        </form>

    </div>
</div>

<script>
function abrirModalProducto() {
    document.getElementById('modal-producto-titulo').textContent = 'Nuevo producto';
    document.getElementById('producto-accion').value = 'crear_producto';
    document.getElementById('producto-id').value     = 0;
    document.getElementById('producto-nombre').value = '';
    document.getElementById('producto-descripcion').value = '';
    document.getElementById('producto-precio').value = '';
    document.getElementById('producto-iva').value = 21;
    document.getElementById('producto-categoria').value = '0';
    document.getElementById('producto-stock').value = 0;
    document.getElementById('producto-stock-minimo').value = 5;
    document.getElementById('producto-estado').value = 'activo';
    document.getElementById('producto-stock-wrap').style.display = '';
    document.getElementById('producto-stock').required = true;
    document.getElementById('modal-producto').classList.remove('hidden');
}

function editarProducto(datos) {
    document.getElementById('modal-producto-titulo').textContent = 'Editar producto';
    document.getElementById('producto-accion').value = 'editar_producto';
    document.getElementById('producto-id').value     = datos.id;
    document.getElementById('producto-nombre').value = datos.nombre;
    document.getElementById('producto-descripcion').value = datos.descripcion;
    document.getElementById('producto-precio').value = datos.precio;
    document.getElementById('producto-iva').value = datos.iva || 21;
    document.getElementById('producto-categoria').value = datos.categoria || '0';
    document.getElementById('producto-stock-minimo').value = datos.stock_minimo;
    document.getElementById('producto-estado').value = datos.estado;
    // El stock no se edita aquí para no pisar ventas registradas mientras el modal está abierto.
    document.getElementById('producto-stock-wrap').style.display = 'none';
    document.getElementById('producto-stock').required = false;
    document.getElementById('modal-producto').classList.remove('hidden');
}

function cerrarModalProducto() {
    document.getElementById('modal-producto').classList.add('hidden');
}
</script>

</div>
<?php require __DIR__ . '/../_footer.php'; ?>
