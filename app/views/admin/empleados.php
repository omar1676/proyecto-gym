<?php

require_once __DIR__ . '/../../helpers/Csrf.php';
require __DIR__ . '/../_header_admin.php';

$etiquetasRol = [
    'empresa' => ['Empresa',     'bg-[#111318] text-white'],
    'admin'       => ['Administrador', 'bg-[#eef2ff] text-[#4f46e5]'],
    'recepcion'   => ['Recepción',   'bg-[#f4f4f5] text-neutral-600'],
];
?>
    <main class="flex-1 bg-[#f7f7f8] px-5 py-8 lg:px-8">
        <section class="mx-auto max-w-6xl">

            <div class="flex flex-col gap-3 pt-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-[#111318] sm:text-3xl">Personal</h1>
                    <p class="mt-1.5 text-sm font-medium text-neutral-500">
                        <?= $esEmpresa
                            ? 'Empleados de todas las sedes.'
                            : 'Empleados de tu sede. Puedes dar de alta personal de recepción.' ?>
                    </p>
                </div>
                <button onclick="abrirModalEmpleado()"
                    class="mt-2 sm:mt-0 inline-flex items-center gap-2 rounded-full bg-[#4f46e5] px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:brightness-110 transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nuevo empleado
                </button>
            </div>

            <?php if ($mensajeExito !== ''): ?>
            <div class="mt-5 rounded-[14px] border border-[#bbf7d0] bg-[#f0fdf4] px-5 py-3 text-sm font-bold text-[#15803d]">
                <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <?php if ($errorEmpleado !== ''): ?>
            <div class="mt-5 rounded-[14px] border border-[#fecaca] bg-[#fee2e2] px-5 py-3 text-sm font-bold text-[#dc2626]">
                <?= htmlspecialchars($errorEmpleado, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <form method="GET" class="mt-6 flex flex-wrap gap-3 items-center">
                <input type="hidden" name="action" value="admin_empleados">
                <input type="text" name="buscar" value="<?= htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Buscar por nombre, email o usuario..."
                    class="flex-1 min-w-[220px] rounded-xl border border-[#e4e4e7] bg-white px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                <button type="submit"
                    class="rounded-full bg-[#4f46e5] px-5 py-2.5 text-sm font-bold text-white hover:brightness-110 transition">Buscar</button>
                <?php if ($busqueda !== ''): ?>
                <a href="index.php?action=admin_empleados"
                    class="rounded-full border border-[#e4e4e7] px-5 py-2.5 text-sm font-bold text-neutral-500 hover:bg-neutral-50 transition">Limpiar</a>
                <?php endif; ?>
            </form>

            <div class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white shadow-sm overflow-hidden">
                <?php if (empty($empleados)): ?>
                    <p class="p-6 text-sm text-neutral-500 italic">No hay empleados que coincidan.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm min-w-[900px]">
                        <thead class="border-b border-[#e4e4e7] bg-[#f4f4f5]">
                            <tr>
                                <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Empleado</th>
                                <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Usuario</th>
                                <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Contacto</th>
                                <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Rol</th>
                                <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Sede</th>
                                <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Acceso</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($empleados as $emp):
                                $rol = $emp['rol'];
                                $et  = $etiquetasRol[$rol] ?? $etiquetasRol['recepcion'];
                                $esYo = (int) $emp['id_usuario'] === $idPropio;
                                // Un admin solo gestiona a recepción; la empresa, a todos.
                                $puedeGestionar = $esEmpresa || $rol === 'recepcion';
                            ?>
                            <tr class="border-t border-[#e4e4e7] hover:bg-neutral-50 transition-colors">
                                <td class="px-5 py-3">
                                    <span class="block font-bold text-neutral-800">
                                        <?= htmlspecialchars($emp['nombre'] . ' ' . $emp['apellidos'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if ($esYo): ?>
                                        <span class="ml-1 rounded-full bg-[#eef2ff] px-2 py-0.5 text-[10px] font-bold text-[#4f46e5]">tú</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="block text-xs text-neutral-500"><?= htmlspecialchars($emp['dni'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td class="px-5 py-3 font-mono text-xs text-neutral-600"><?= htmlspecialchars($emp['nombre_usuario'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-5 py-3 text-neutral-500 text-xs">
                                    <span class="block"><?= htmlspecialchars($emp['email'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!empty($emp['telefono'])): ?>
                                    <span class="block"><?= htmlspecialchars($emp['telefono'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="rounded-full px-3 py-1 text-xs font-bold <?= $et[1] ?>"><?= $et[0] ?></span>
                                </td>
                                <td class="px-5 py-3 text-neutral-500">
                                    <?= $rol === 'empresa'
                                        ? '<span class="text-xs italic text-neutral-500">Todas</span>'
                                        : htmlspecialchars($emp['gimnasio_nombre'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="px-5 py-3">
                                    <?php if ($puedeGestionar && !$esYo): ?>
                                    <form method="POST" action="index.php?action=admin_empleado_toggle" style="display:inline"
                                        onsubmit="return confirm('<?= (int) $emp['activo'] === 1 ? '¿Bloquear el acceso de este empleado?' : '¿Restablecer el acceso de este empleado?' ?>');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id_usuario" value="<?= (int) $emp['id_usuario'] ?>">
                                        <button type="submit"
                                            class="rounded-full px-3 py-1 text-xs font-bold transition hover:brightness-95
                                                <?= (int) $emp['activo'] === 1 ? 'bg-[#dcfce7] text-[#15803d]' : 'bg-[#fee2e2] text-[#dc2626]' ?>">
                                            <?= (int) $emp['activo'] === 1 ? 'Activo' : 'Bloqueado' ?>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <span class="rounded-full px-3 py-1 text-xs font-bold
                                        <?= (int) $emp['activo'] === 1 ? 'bg-[#dcfce7] text-[#15803d]' : 'bg-[#fee2e2] text-[#dc2626]' ?>">
                                        <?= (int) $emp['activo'] === 1 ? 'Activo' : 'Bloqueado' ?>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <?php if ($puedeGestionar): ?>
                                    <button type="button"
                                        onclick='editarEmpleado(<?= json_encode([
                                            "id"        => (int) $emp["id_usuario"],
                                            "nombre"    => $emp["nombre"],
                                            "apellidos" => $emp["apellidos"],
                                            "email"     => $emp["email"],
                                            "telefono"  => $emp["telefono"] ?? "",
                                            "rol"       => $emp["rol"],
                                            "sede"      => (int) ($emp["id_gimnasio"] ?? 0),
                                            "esYo"      => $esYo,
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'
                                        class="rounded-full border border-[#e4e4e7] px-3 py-1 text-xs font-bold text-neutral-500 hover:bg-neutral-50 transition">
                                        Editar
                                    </button>
                                    <?php else: ?>
                                    <span class="text-xs text-neutral-300">Sin permiso</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <p class="mt-4 text-xs text-neutral-500">
                Bloquear a un empleado le impide entrar, pero conserva todo lo que hizo en el
                historial. Por eso no hay opción de borrar.
            </p>

        </section>
    </main>

<!-- Modal alta de empleado -->
<div id="modal-empleado"
     class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6 overflow-y-auto"
     onclick="if(event.target===this) this.classList.add('hidden')">

    <div class="w-full max-w-lg rounded-[24px] bg-white p-8 shadow-2xl my-auto">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-extrabold text-neutral-800">Nuevo empleado</h2>
            <button onclick="document.getElementById('modal-empleado').classList.add('hidden')"
                class="text-neutral-500 hover:text-neutral-700 transition-colors text-2xl leading-none"
                aria-label="Cerrar">&times;</button>
        </div>

        <form method="POST" action="index.php?action=admin_empleado_crear" class="space-y-4">
            <?= Csrf::field() ?>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Nombre <span class="text-[#dc2626]">*</span></label>
                    <input type="text" name="nombre" required maxlength="100"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Apellidos <span class="text-[#dc2626]">*</span></label>
                    <input type="text" name="apellidos" required maxlength="150"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">DNI <span class="text-[#dc2626]">*</span></label>
                    <input type="text" name="dni" required maxlength="20"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium uppercase text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Teléfono</label>
                    <input type="text" name="telefono" maxlength="20"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
            </div>

            <div>
                <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Email <span class="text-[#dc2626]">*</span></label>
                <input type="email" name="email" required maxlength="255"
                    class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Usuario <span class="text-[#dc2626]">*</span></label>
                    <input type="text" name="usuario" required maxlength="60"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Contraseña <span class="text-[#dc2626]">*</span></label>
                    <input type="password" name="contrasena" required minlength="8"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
            </div>

            <?php if ($esEmpresa): ?>
            <div class="grid grid-cols-2 gap-4 border-t border-[#e4e4e7] pt-4">
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Rol</label>
                    <select name="rol"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                        <option value="recepcion">Recepción</option>
                        <option value="admin">Administrador</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Sede <span class="text-[#dc2626]">*</span></label>
                    <select name="id_gimnasio" required
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                        <?php foreach ($sedes as $sd): ?>
                        <option value="<?= (int) $sd['id_gimnasio'] ?>"><?= htmlspecialchars($sd['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php else: ?>
            <p class="rounded-xl bg-[#f4f4f5] px-4 py-3 text-xs text-neutral-500">
                Se dará de alta como <strong>recepción</strong> en tu sede. Para crear administradores
                o personal de otra sede hace falta el rol de empresa.
            </p>
            <?php endif; ?>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                    class="flex-1 rounded-full bg-[#4f46e5] py-2.5 text-sm font-bold text-white hover:brightness-110 transition-all">
                    Dar de alta
                </button>
                <button type="button" onclick="document.getElementById('modal-empleado').classList.add('hidden')"
                    class="flex-1 rounded-full border border-[#e4e4e7] py-2.5 text-sm font-bold text-neutral-500 hover:bg-neutral-50 transition-all">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal edición de empleado -->
<div id="modal-editar-empleado"
     class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4 py-6 overflow-y-auto"
     onclick="if(event.target===this) this.classList.add('hidden')">

    <div class="w-full max-w-lg rounded-[24px] bg-white p-8 shadow-2xl my-auto">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-extrabold text-neutral-800">Editar empleado</h2>
            <button onclick="document.getElementById('modal-editar-empleado').classList.add('hidden')"
                class="text-neutral-500 hover:text-neutral-700 transition-colors text-2xl leading-none"
                aria-label="Cerrar">&times;</button>
        </div>

        <form method="POST" action="index.php?action=admin_empleado_editar" class="space-y-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="id_usuario" id="ed-emp-id" value="0">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Nombre <span class="text-[#dc2626]">*</span></label>
                    <input type="text" name="nombre" id="ed-emp-nombre" required maxlength="100"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Apellidos <span class="text-[#dc2626]">*</span></label>
                    <input type="text" name="apellidos" id="ed-emp-apellidos" required maxlength="150"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Email <span class="text-[#dc2626]">*</span></label>
                    <input type="email" name="email" id="ed-emp-email" required maxlength="255"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Teléfono</label>
                    <input type="text" name="telefono" id="ed-emp-telefono" maxlength="20"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                </div>
            </div>

            <?php if ($esEmpresa): ?>
            <div class="grid grid-cols-2 gap-4 border-t border-[#e4e4e7] pt-4">
                <div>
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Rol</label>
                    <select name="rol" id="ed-emp-rol" onchange="alternarSedeEmpleado()"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                        <option value="recepcion">Recepción</option>
                        <option value="admin">Administrador</option>
                        <option value="empresa">Empresa (acceso a todas las sedes)</option>
                    </select>
                    <p id="ed-emp-aviso-rol" class="mt-1 text-[11px] text-neutral-500"></p>
                </div>
                <div id="ed-emp-sede-wrap">
                    <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Sede</label>
                    <select name="id_gimnasio" id="ed-emp-sede"
                        class="w-full rounded-xl border border-[#e4e4e7] bg-[#f4f4f5] px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                        <?php foreach ($sedes as $sd): ?>
                        <option value="<?= (int) $sd['id_gimnasio'] ?>"><?= htmlspecialchars($sd['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>

            <div class="flex gap-3 pt-2">
                <button type="submit"
                    class="flex-1 rounded-full bg-[#4f46e5] py-2.5 text-sm font-bold text-white hover:brightness-110 transition-all">
                    Guardar cambios
                </button>
                <button type="button" onclick="document.getElementById('modal-editar-empleado').classList.add('hidden')"
                    class="flex-1 rounded-full border border-[#e4e4e7] py-2.5 text-sm font-bold text-neutral-500 hover:bg-neutral-50 transition-all">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalEmpleado() {
    document.getElementById('modal-empleado').classList.remove('hidden');
}

function editarEmpleado(datos) {
    document.getElementById('ed-emp-id').value        = datos.id;
    document.getElementById('ed-emp-nombre').value    = datos.nombre;
    document.getElementById('ed-emp-apellidos').value = datos.apellidos;
    document.getElementById('ed-emp-email').value     = datos.email;
    document.getElementById('ed-emp-telefono').value  = datos.telefono || '';

    var selRol = document.getElementById('ed-emp-rol');
    if (selRol) {
        selRol.value = datos.rol;
        // Cambiarse el rol a uno mismo dejaría al panel sin quien lo administre.
        selRol.disabled = !!datos.esYo;
        document.getElementById('ed-emp-aviso-rol').textContent =
            datos.esYo ? 'No puedes cambiarte el rol a ti mismo.' : '';

        var selSede = document.getElementById('ed-emp-sede');
        if (selSede && datos.sede) selSede.value = datos.sede;
        alternarSedeEmpleado();
    }

    document.getElementById('modal-editar-empleado').classList.remove('hidden');
}

// La empresa no pertenece a ninguna sede: las ve todas.
function alternarSedeEmpleado() {
    var selRol = document.getElementById('ed-emp-rol');
    var caja   = document.getElementById('ed-emp-sede-wrap');
    if (!selRol || !caja) return;
    caja.style.display = selRol.value === 'empresa' ? 'none' : '';
}
</script>

</div>
<?php require __DIR__ . '/../_footer.php'; ?>
