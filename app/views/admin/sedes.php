<?php

require_once __DIR__ . '/../../helpers/Csrf.php';
require __DIR__ . '/../_header_admin.php';
?>
    <main class="flex-1 bg-[#f7f7f8] px-5 py-8 lg:px-8">
        <section class="mx-auto max-w-6xl">

            <div class="flex flex-col gap-3 pt-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-[#111318] sm:text-3xl">Sedes</h1>
                    <p class="mt-1.5 text-sm font-medium text-neutral-500">
                        Gimnasios del grupo. Cada sede tiene sus propios socios, productos y caja.
                    </p>
                </div>
                <button onclick="abrirModalSede()"
                    class="mt-2 sm:mt-0 inline-flex items-center gap-2 rounded-full bg-[#4f46e5] px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:brightness-110 transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nueva sede
                </button>
            </div>

            <?php if ($mensajeExito !== ''): ?>
            <div class="mt-5 rounded-[14px] border border-[#bbf7d0] bg-[#f0fdf4] px-5 py-3 text-sm font-bold text-[#15803d]">
                <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <?php if ($errorSede !== ''): ?>
            <div class="mt-5 rounded-[14px] border border-[#fecaca] bg-[#fee2e2] px-5 py-3 text-sm font-bold text-[#dc2626]">
                <?= htmlspecialchars($errorSede, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <div class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white shadow-sm overflow-hidden">
                <?php if (empty($sedes)): ?>
                    <p class="p-6 text-sm text-neutral-500 italic">No hay sedes registradas.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm min-w-[880px]">
                        <thead class="border-b border-[#e4e4e7] bg-[#f4f4f5]">
                            <tr>
                                <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Sede</th>
                                <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Contacto</th>
                                <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Socios</th>
                                <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Personal</th>
                                <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Productos</th>
                                <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Estado</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sedes as $s): ?>
                            <tr class="border-t border-[#e4e4e7] hover:bg-neutral-50 transition-colors">
                                <td class="px-5 py-3">
                                    <div class="mb-1.5 flex items-center gap-2">
                                        <?php if (!empty($s['logo'])): ?>
                                        <img src="assets/gimnasios/<?= htmlspecialchars($s['logo'], ENT_QUOTES, 'UTF-8') ?>"
                                             alt="" class="h-8 w-8 rounded-lg object-contain border border-[#e4e4e7] bg-white p-0.5">
                                        <?php else: ?>
                                        <span class="flex h-8 w-8 items-center justify-center rounded-lg text-xs font-extrabold"
                                              style="background: <?= htmlspecialchars($s['color_primario'] ?: '#4f46e5', ENT_QUOTES, 'UTF-8') ?>; color: <?= htmlspecialchars($s['color_texto'] ?: '#ffffff', ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(mb_strtoupper(mb_substr($s['nombre'], 0, 1, 'UTF-8')), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if (!empty($s['email_acceso'])): ?>
                                        <span class="font-mono text-[10px] text-neutral-500" title="Email con el que entra este gimnasio">
                                            <?= htmlspecialchars($s['email_acceso'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="rounded-full bg-[#fef3c7] px-2 py-0.5 text-[10px] font-bold text-[#b45309]"
                                              title="Sin credenciales, este gimnasio no puede entrar">
                                            sin acceso
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="block font-bold text-neutral-800"><?= htmlspecialchars($s['nombre'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!empty($s['razon_social'])): ?>
                                    <span class="block text-xs text-neutral-500">
                                        <?= htmlspecialchars($s['razon_social'], ENT_QUOTES, 'UTF-8') ?>
                                        <?= !empty($s['cif']) ? ' · ' . htmlspecialchars($s['cif'], ENT_QUOTES, 'UTF-8') : '' ?>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3 text-neutral-500 text-xs">
                                    <?php if (!empty($s['direccion'])): ?>
                                    <span class="block"><?= htmlspecialchars($s['direccion'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($s['telefono'])): ?>
                                    <span class="block"><?= htmlspecialchars($s['telefono'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                    <?php if (empty($s['direccion']) && empty($s['telefono'])): ?>
                                    <span class="text-neutral-300">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3 font-bold text-neutral-700"><?= (int) $s['num_socios'] ?></td>
                                <td class="px-5 py-3 font-bold text-neutral-700"><?= (int) $s['num_empleados'] ?></td>
                                <td class="px-5 py-3 font-bold text-neutral-700"><?= (int) $s['num_productos'] ?></td>
                                <td class="px-5 py-3">
                                    <form method="POST" action="index.php?action=admin_sedes" style="display:inline"
                                        onsubmit="return confirm('<?= (int) $s['activo'] === 1 ? '¿Cerrar esta sede? Sus datos se conservan, pero dejará de aparecer en las altas.' : '¿Reabrir esta sede?' ?>');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="accion" value="toggle_sede">
                                        <input type="hidden" name="id_gimnasio" value="<?= (int) $s['id_gimnasio'] ?>">
                                        <button type="submit"
                                            class="rounded-full px-3 py-1 text-xs font-bold transition hover:brightness-95
                                                <?= (int) $s['activo'] === 1 ? 'bg-[#dcfce7] text-[#15803d]' : 'bg-neutral-100 text-neutral-500' ?>">
                                            <?= (int) $s['activo'] === 1 ? 'Abierta' : 'Cerrada' ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    <button type="button"
                                        onclick='abrirModalMarca(<?= json_encode([
                                            "id"     => (int) $s["id_gimnasio"],
                                            "nombre" => $s["nombre"],
                                            "logo"   => $s["logo"] ?? "",
                                            "color"  => $s["color_primario"] ?: "#4f46e5",
                                            "texto"  => $s["color_texto"] ?: "#ffffff",
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'
                                        class="rounded-full border border-[#e4e4e7] px-3 py-1 text-xs font-bold text-neutral-500 hover:bg-neutral-50 transition">
                                        Marca
                                    </button>
                                    <button type="button"
                                        onclick='editarSede(<?= json_encode([
                                            "id"           => (int) $s["id_gimnasio"],
                                            "nombre"       => $s["nombre"],
                                            "razon_social" => $s["razon_social"] ?? "",
                                            "cif"          => $s["cif"] ?? "",
                                            "email_acceso" => $s["email_acceso"] ?? "",
                                            "tiene_clave"  => !empty($s["contrasena_acceso"]),
                                            "direccion"    => $s["direccion"] ?? "",
                                            "telefono"     => $s["telefono"] ?? "",
                                            "email"        => $s["email"] ?? "",
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'
                                        class="rounded-full border border-[#e4e4e7] px-3 py-1 text-xs font-bold text-neutral-500 hover:bg-neutral-50 transition">
                                        Editar
                                    </button>
                                    <form method="POST" action="index.php?action=admin_sede_activa" style="display:inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id_gimnasio" value="<?= (int) $s['id_gimnasio'] ?>">
                                        <input type="hidden" name="volver_a" value="admin">
                                        <button type="submit"
                                            class="rounded-full bg-[#111318] px-3 py-1 text-xs font-bold text-white hover:brightness-125 transition">
                                            Trabajar aquí
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <p class="mt-4 text-xs text-neutral-500">
                Cerrar una sede no borra nada: sus ventas, socios y membresías siguen existiendo
                para la contabilidad. Solo deja de ofrecerse al dar de alta personal o socios.
            </p>

        </section>
    </main>

<!-- Modal alta / edición de sede -->
<div id="modal-sede"
     class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6 overflow-y-auto"
     onclick="if(event.target===this) cerrarModalSede()">

    <div class="w-full max-w-lg rounded-[24px] bg-white p-8 shadow-2xl my-auto">
        <div class="flex items-center justify-between mb-6">
            <h2 id="modal-sede-titulo" class="text-xl font-extrabold text-neutral-800">Nueva sede</h2>
            <button onclick="cerrarModalSede()"
                class="text-neutral-500 hover:text-neutral-700 transition-colors text-2xl leading-none"
                aria-label="Cerrar">&times;</button>
        </div>

        <form method="POST" action="index.php?action=admin_sedes" class="space-y-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="accion"      value="guardar_sede">
            <input type="hidden" name="id_gimnasio" id="sede-id" value="0">

            <div>
                <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">
                    Nombre <span class="text-[#dc2626]">*</span>
                </label>
                <input type="text" name="nombre" id="sede-nombre" required maxlength="120"
                    placeholder="Ej: Sede Centro"
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Razón social</label>
                    <input type="text" name="razon_social" id="sede-razon" maxlength="150"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">CIF</label>
                    <input type="text" name="cif" id="sede-cif" maxlength="20"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium uppercase text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
            </div>

            <div class="rounded-xl border border-[#e4e4e7] bg-[#f7f7f8] p-4">
                <p class="mb-3 text-xs font-extrabold uppercase tracking-widest text-neutral-500">Credenciales de acceso</p>
                <div class="space-y-3">
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-widest text-neutral-500 mb-1">
                            Email del gimnasio
                        </label>
                        <input type="email" name="email_acceso" id="sede-email-acceso" maxlength="255"
                            placeholder="gimnasio@ejemplo.com"
                            class="w-full rounded-xl border border-[#e4e4e7] bg-white px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-widest text-neutral-500 mb-1">
                            Contraseña del gimnasio
                        </label>
                        <input type="password" name="contrasena_acceso" id="sede-clave-acceso" minlength="12"
                            autocomplete="new-password"
                            class="w-full rounded-xl border border-[#e4e4e7] bg-white px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                        <p id="sede-aviso-clave" class="mt-1 text-[11px] text-neutral-500"></p>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Dirección</label>
                <input type="text" name="direccion" id="sede-direccion" maxlength="255"
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Teléfono</label>
                    <input type="text" name="telefono" id="sede-telefono" maxlength="20"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Email</label>
                    <input type="email" name="email" id="sede-email" maxlength="255"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
            </div>

            <p class="rounded-xl bg-[#f4f4f5] px-4 py-3 text-xs text-neutral-500">
                Los datos bancarios para domiciliar (IBAN e identificador de acreedor) se
                configuran en <strong>Domiciliaciones</strong>, trabajando desde esta sede.
            </p>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                    class="flex-1 rounded-full bg-[#4f46e5] py-2.5 text-sm font-bold text-white hover:brightness-110 transition-all">
                    Guardar sede
                </button>
                <button type="button" onclick="cerrarModalSede()"
                    class="flex-1 rounded-full border border-[#e4e4e7] py-2.5 text-sm font-bold text-neutral-500 hover:bg-neutral-50 transition-all">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: logo y colores de la sede -->
<div id="modal-marca"
     class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6 overflow-y-auto"
     onclick="if(event.target===this) this.classList.add('hidden')">

    <div class="w-full max-w-md rounded-[24px] bg-white p-8 shadow-2xl my-auto">
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-xl font-extrabold text-neutral-800">Marca de la sede</h2>
            <button onclick="document.getElementById('modal-marca').classList.add('hidden')"
                class="text-neutral-500 hover:text-neutral-700 transition-colors text-2xl leading-none"
                aria-label="Cerrar">&times;</button>
        </div>
        <p id="marca-nombre-sede" class="mb-6 text-sm font-medium text-neutral-500"></p>

        <form method="POST" action="index.php?action=admin_sede_marca" enctype="multipart/form-data" class="space-y-5">
            <?= Csrf::field() ?>
            <input type="hidden" name="id_gimnasio" id="marca-id" value="0">

            <div>
                <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Logo</label>
                <div class="flex items-center gap-4">
                    <div id="marca-vista" class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl border border-[#e4e4e7] bg-white p-1"></div>
                    <div class="flex-1">
                        <input type="file" name="logo" id="marca-archivo" accept="image/png,image/jpeg,image/webp,image/gif"
                            onchange="logoElegido(this)"
                            class="w-full text-xs text-neutral-600 file:mr-3 file:rounded-full file:border-0 file:bg-[#4f46e5] file:px-4 file:py-2 file:text-xs file:font-bold file:text-white hover:file:brightness-110">
                        <p class="mt-1 text-[11px] text-neutral-500">PNG, JPG o WEBP. Máximo 2 MB.</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Color principal</label>
                    <input type="color" name="color_primario" id="marca-color" value="#4f46e5"
                           oninput="previsualizarMarca()"
                           class="h-11 w-full cursor-pointer rounded-xl border border-[#e4e4e7] bg-white p-1">
                </div>
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Texto sobre él</label>
                    <input type="color" name="color_texto" id="marca-texto" value="#ffffff"
                           oninput="previsualizarMarca()"
                           class="h-11 w-full cursor-pointer rounded-xl border border-[#e4e4e7] bg-white p-1">
                </div>
            </div>

            <!-- Los colores salen del propio logo: al elegir uno se rellenan
                 solos y este botón permite repetirlo si se han tocado a mano. -->
            <div class="flex items-center gap-3">
                <button type="button" onclick="coloresDesdeLogo()"
                    class="rounded-full border border-[#e4e4e7] px-4 py-2 text-xs font-bold text-neutral-600 hover:bg-neutral-50 transition-all">
                    Sacar colores del logo
                </button>
                <span id="marca-aviso" class="text-[11px] text-neutral-500"></span>
            </div>

            <div class="rounded-xl border border-[#e4e4e7] p-4">
                <p class="mb-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Su pantalla de acceso</p>
                <!-- Miniatura de la pantalla real: mismo fondo de marca, misma
                     tarjeta blanca y mismo botón que ve el empleado. -->
                <div id="marca-fondo" class="rounded-xl p-5">
                    <div class="rounded-xl bg-white p-3 shadow-lg">
                        <div id="marca-cabecera" class="flex items-center justify-center border-b border-[#ececf0] pb-3">
                            <div id="marca-vista-mini" class="flex h-10 items-center justify-center"></div>
                        </div>
                        <div id="marca-boton" class="mt-3 rounded-lg py-2 text-center text-xs font-bold">Entrar</div>
                    </div>
                </div>
            </div>

            <div class="flex gap-3 pt-1">
                <button type="submit"
                    class="flex-1 rounded-full bg-[#4f46e5] py-2.5 text-sm font-bold text-white hover:brightness-110 transition-all">
                    Guardar marca
                </button>
                <button type="submit" name="accion_marca" value="quitar_logo" id="marca-quitar"
                    onclick="return confirm('¿Quitar el logo de esta sede?');"
                    class="rounded-full border border-[#fecaca] px-4 py-2.5 text-sm font-bold text-[#dc2626] hover:bg-[#fee2e2] transition-all">
                    Quitar logo
                </button>
            </div>
        </form>
    </div>
</div>

<script>
/* --- Marca de la sede: logo, colores sacados del logo y vista previa -------
 *
 * Los colores de cada gimnasio salen de su propio logo, y se calculan aquí, en
 * el navegador: el servidor no necesita la extensión GD, que en muchos
 * alojamientos no está activada.
 * ------------------------------------------------------------------------ */

var marcaNombre = '';   // nombre de la sede abierta en el modal
var marcaLogoUrl = '';  // logo visible ahora mismo (guardado o recién elegido)

function abrirModalMarca(datos) {
    marcaNombre  = datos.nombre;
    marcaLogoUrl = datos.logo ? 'assets/gimnasios/' + datos.logo : '';

    document.getElementById('marca-id').value = datos.id;
    document.getElementById('marca-nombre-sede').textContent = datos.nombre;
    document.getElementById('marca-color').value = datos.color;
    document.getElementById('marca-texto').value = datos.texto;
    document.getElementById('marca-archivo').value = '';
    document.getElementById('marca-aviso').textContent = '';

    // Sin logo no tiene sentido ofrecer quitarlo.
    document.getElementById('marca-quitar').style.display = datos.logo ? '' : 'none';

    previsualizarMarca();
    document.getElementById('modal-marca').classList.remove('hidden');
}

/** Al elegir un archivo se muestra y se sacan sus colores automáticamente. */
function logoElegido(input) {
    var archivo = input.files && input.files[0];
    if (!archivo) return;

    var lector = new FileReader();
    lector.onload = function (ev) {
        marcaLogoUrl = ev.target.result;   // data: URL, no ensucia el canvas
        previsualizarMarca();
        coloresDesdeLogo();
    };
    lector.readAsDataURL(archivo);
}

/** Rellena los dos selectores de color con la paleta del logo actual. */
function coloresDesdeLogo() {
    var aviso = document.getElementById('marca-aviso');

    if (!marcaLogoUrl) {
        aviso.textContent = 'Elige primero un logo.';
        return;
    }

    var img = new Image();
    img.onload = function () {
        var color = colorDominante(img);
        if (!color) {
            aviso.textContent = 'No se ha podido leer el color del logo.';
            return;
        }
        document.getElementById('marca-color').value = color;
        document.getElementById('marca-texto').value = textoSobre(color);
        aviso.textContent = 'Colores tomados del logo. Puedes ajustarlos.';
        previsualizarMarca();
    };
    img.onerror = function () { aviso.textContent = 'No se ha podido leer el logo.'; };
    img.src = marcaLogoUrl;
}

/**
 * Color de marca de una imagen.
 *
 * Se descartan los píxeles transparentes y los casi blancos (el papel del logo,
 * no su color) y el resto se agrupa por tonos parecidos.
 *
 * Entre los grupos manda el color sobre el gris: si hay tinta de color en una
 * proporción apreciable gana el color más presente, aunque un relleno neutro
 * ocupe más superficie, porque es el color el que identifica a la marca. Si el
 * logo es monocromo no hay nada cromático y gana su
 * tinta negra, que es justo lo que se quiere.
 */
function colorDominante(img) {
    var lado = 96;
    var lienzo = document.createElement('canvas');
    lienzo.width = lado;
    lienzo.height = lado;

    var ctx = lienzo.getContext('2d');
    ctx.drawImage(img, 0, 0, lado, lado);

    var datos;
    try {
        datos = ctx.getImageData(0, 0, lado, lado).data;
    } catch (e) {
        return null;   // imagen de otro dominio: el navegador no deja leerla
    }

    var grupos = {};
    var contados = 0;
    for (var i = 0; i < datos.length; i += 4) {
        var r = datos[i], g = datos[i + 1], b = datos[i + 2], a = datos[i + 3];
        if (a < 128) continue;

        var max = Math.max(r, g, b) / 255, min = Math.min(r, g, b) / 255;
        var luz = (max + min) / 2;
        var sat = max === min ? 0 : (luz > 0.5 ? (max - min) / (2 - max - min) : (max - min) / (max + min));

        // Blancos y casi blancos: es el papel del logo, no su color.
        if (luz > 0.92 && sat < 0.15) continue;

        var clave = (r >> 4) + '-' + (g >> 4) + '-' + (b >> 4);
        if (!grupos[clave]) grupos[clave] = { n: 0, r: 0, g: 0, b: 0, sat: 0 };
        var grupo = grupos[clave];
        grupo.n++;
        grupo.r += r; grupo.g += g; grupo.b += b;
        grupo.sat += sat;
        contados++;
    }
    if (!contados) return null;

    // Cuánta tinta de color hay: por debajo de una décima parte se considera
    // un detalle (una firma, un acento) y manda el tono principal del logo.
    var cromaticos = 0;
    for (var c in grupos) {
        if (grupos[c].sat / grupos[c].n >= 0.25) cromaticos += grupos[c].n;
    }
    var soloColor = cromaticos / contados >= 0.10;

    var mejor = null;
    for (var k in grupos) {
        var gr = grupos[k];
        if (soloColor && gr.sat / gr.n < 0.25) continue;
        if (!mejor || gr.n > mejor.n) mejor = gr;
    }
    if (!mejor) return null;

    // El tono exacto es la media del grupo, no el centro del cajón.
    return aHex(mejor.r / mejor.n, mejor.g / mejor.n, mejor.b / mejor.n);
}

function aHex(r, g, b) {
    var dos = function (v) {
        var s = Math.max(0, Math.min(255, Math.round(v))).toString(16);
        return s.length < 2 ? '0' + s : s;
    };
    return '#' + dos(r) + dos(g) + dos(b);
}

/** Luminancia relativa (WCAG): decide si encima va texto blanco o negro. */
function luminancia(hex) {
    var v = [1, 3, 5].map(function (p) {
        var c = parseInt(hex.substr(p, 2), 16) / 255;
        return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
}

function textoSobre(hex) {
    return luminancia(hex) > 0.45 ? '#111318' : '#ffffff';
}

function previsualizarMarca() {
    var color = document.getElementById('marca-color').value;
    var texto = document.getElementById('marca-texto').value;

    // Fondo de la pantalla de acceso: el color de marca con la misma
    // profundidad que aplica el helper Marca en el servidor.
    var fondo = document.getElementById('marca-fondo');
    fondo.style.background = 'radial-gradient(120% 90% at 50% 0%, ' + color + ' 0%, '
                           + mezclarConNegro(color, luminancia(color) < 0.35 ? 0.55 : 0.20) + ' 100%)';

    var boton = document.getElementById('marca-boton');
    boton.style.background = color;
    boton.style.color = texto;

    // El logo va sobre blanco, igual que en la pantalla real.
    var mini = document.getElementById('marca-vista-mini');
    var vista = document.getElementById('marca-vista');
    if (marcaLogoUrl) {
        vista.innerHTML = '<img src="' + marcaLogoUrl + '" alt="" class="h-full w-full object-contain">';
        mini.innerHTML  = '<img src="' + marcaLogoUrl + '" alt="" class="h-10 w-auto max-w-[150px] object-contain">';
    } else {
        var inicial = (marcaNombre || '?').charAt(0).toUpperCase();
        var chip = 'style="background:' + color + ';color:' + texto + '"';
        vista.innerHTML = '<span class="flex h-full w-full items-center justify-center rounded-lg text-lg font-extrabold" ' + chip + '>' + inicial + '</span>';
        mini.innerHTML  = '<span class="flex h-10 w-10 items-center justify-center rounded-xl text-base font-extrabold" ' + chip + '>' + inicial + '</span>';
    }
}

function mezclarConNegro(hex, peso) {
    var c = [1, 3, 5].map(function (p) { return parseInt(hex.substr(p, 2), 16) * (1 - peso); });
    return aHex(c[0], c[1], c[2]);
}

function abrirModalSede() {
    document.getElementById('modal-sede-titulo').textContent = 'Nueva sede';
    document.getElementById('sede-id').value        = 0;
    document.getElementById('sede-nombre').value    = '';
    document.getElementById('sede-razon').value     = '';
    document.getElementById('sede-cif').value       = '';
    document.getElementById('sede-direccion').value = '';
    document.getElementById('sede-telefono').value  = '';
    document.getElementById('sede-email').value     = '';
    document.getElementById('sede-email-acceso').value = '';
    document.getElementById('sede-clave-acceso').value = '';
    document.getElementById('sede-aviso-clave').textContent = 'Con estas credenciales entrará el gimnasio.';
    document.getElementById('modal-sede').classList.remove('hidden');
}

function editarSede(datos) {
    document.getElementById('modal-sede-titulo').textContent = 'Editar sede';
    document.getElementById('sede-id').value        = datos.id;
    document.getElementById('sede-nombre').value    = datos.nombre;
    document.getElementById('sede-razon').value     = datos.razon_social;
    document.getElementById('sede-cif').value       = datos.cif;
    document.getElementById('sede-direccion').value = datos.direccion;
    document.getElementById('sede-telefono').value  = datos.telefono;
    document.getElementById('sede-email').value     = datos.email;
    document.getElementById('sede-email-acceso').value = datos.email_acceso || '';
    document.getElementById('sede-clave-acceso').value = '';
    document.getElementById('sede-aviso-clave').textContent = datos.tiene_clave
        ? 'Ya tiene contraseña. Déjalo vacío para no cambiarla.'
        : 'Sin contraseña todavía: el gimnasio no puede entrar.';
    document.getElementById('modal-sede').classList.remove('hidden');
}

function cerrarModalSede() {
    document.getElementById('modal-sede').classList.add('hidden');
}
</script>

</div>
<?php require __DIR__ . '/../_footer.php'; ?>
