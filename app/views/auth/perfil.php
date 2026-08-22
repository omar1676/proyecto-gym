<?php
require_once __DIR__ . '/../../helpers/Csrf.php';

if (!isset($usuario))  $usuario  = [];
if (!isset($errores))  $errores  = [];
if (!isset($exito))    $exito    = null;

$pageTitle = 'Mi perfil';
require __DIR__ . '/../_header.php';

function valPerfil($campo, $usuario) {
    return htmlspecialchars($usuario[$campo] ?? '', ENT_QUOTES, 'UTF-8');
}

$fotoActual = !empty($usuario['foto'])
    ? APP_URL . '/index.php?action=media_foto&amp;id=' . (int) ($usuario['id_usuario'] ?? 0)
    : '';
?>

<main class="max-w-4xl mx-auto p-6 w-full flex-1">

    <div class="flex items-center gap-3 mb-6">
        <a href="index.php?action=admin" class="text-sm text-[#111318] hover:underline">← Volver al panel</a>
    </div>

    <h1 class="text-2xl font-bold text-[#111318] mb-6">Mi perfil</h1>

    <?php if ($exito): ?>
    <div class="mb-5 rounded-xl border border-green-200 bg-green-50 px-5 py-3 text-sm font-bold text-green-700">
        <i class="fa-solid fa-circle-check mr-2"></i><?= htmlspecialchars($exito) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($errores)): ?>
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm text-red-700">
        <ul class="list-disc list-inside space-y-1 font-semibold">
            <?php foreach ($errores as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" action="index.php?action=perfil_actualizar" enctype="multipart/form-data"
          class="bg-white rounded-xl shadow p-6 md:p-8 space-y-6">
        <?= Csrf::field() ?>

        <section>
            <h2 class="text-lg font-bold text-[#111318] mb-4">Foto de perfil</h2>
            <div class="flex items-center gap-5">
                <?php if ($fotoActual !== ''): ?>
                    <img src="<?= $fotoActual ?>" alt="Foto actual"
                         class="w-20 h-20 rounded-full object-cover border-2 border-[#d4d4d8]">
                <?php else: ?>
                    <div class="w-20 h-20 rounded-full bg-[#e4e4e7] flex items-center justify-center text-2xl font-bold text-[#111318]">
                        <?= strtoupper(substr($usuario['nombre'] ?? '?', 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div class="flex-1">
                    <input type="file" name="foto" accept="image/*"
                        class="w-full text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                    <p class="text-xs text-gray-400 mt-1">Opcional. JPG, PNG, GIF o WEBP — máx. 2 MB.</p>
                </div>
            </div>
        </section>

        <hr class="border-gray-100">

        <section>
            <h2 class="text-lg font-bold text-[#111318] mb-4">Datos personales</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Nombre *</label>
                    <input type="text" name="nombre" value="<?= valPerfil('nombre', $usuario) ?>" required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Apellidos *</label>
                    <input type="text" name="apellidos" value="<?= valPerfil('apellidos', $usuario) ?>" required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">DNI</label>
                    <input type="text" value="<?= valPerfil('dni', $usuario) ?>" disabled
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-500">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Teléfono</label>
                    <input type="text" name="telefono" value="<?= valPerfil('telefono', $usuario) ?>"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Correo *</label>
                    <input type="email" name="correo" value="<?= valPerfil('email', $usuario) ?>" required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Fecha de nacimiento</label>
                    <input type="date" name="fecha_nacimiento" value="<?= valPerfil('fecha_nacimiento', $usuario) ?>"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Género</label>
                    <input type="text" name="genero" value="<?= valPerfil('genero', $usuario) ?>"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                </div>
            </div>
        </section>

        <hr class="border-gray-100">

        <section>
            <h2 class="text-lg font-bold text-[#111318] mb-4">Dirección</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">País</label>
                    <input type="text" name="pais" value="<?= valPerfil('pais', $usuario) ?>"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Provincia</label>
                    <input type="text" name="provincia" value="<?= valPerfil('provincia', $usuario) ?>"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Localidad</label>
                    <input type="text" name="localidad" value="<?= valPerfil('localidad', $usuario) ?>"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Código postal</label>
                    <input type="text" name="codigo_postal" value="<?= valPerfil('codigo_postal', $usuario) ?>"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Dirección</label>
                    <input type="text" name="direccion" value="<?= valPerfil('direccion', $usuario) ?>"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                </div>
            </div>
        </section>

        <hr class="border-gray-100">

        <section>
            <h2 class="text-lg font-bold text-[#111318] mb-2">Cambiar contraseña</h2>
            <p class="text-xs text-gray-400 mb-4">Déjalo en blanco si no quieres cambiarla.</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <input type="password" name="contrasena_actual" placeholder="Contraseña actual" autocomplete="current-password"
                    class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                <input type="password" name="contrasena_nueva" placeholder="Nueva contraseña (mín. 8)" autocomplete="new-password"
                    class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
                <input type="password" name="contrasena_confirmar" placeholder="Confirmar nueva" autocomplete="new-password"
                    class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#4f46e5]">
            </div>
        </section>

        <div class="flex justify-end gap-3 pt-4">
            <a href="index.php?action=admin"
                class="px-6 py-2.5 rounded-lg border border-gray-200 text-sm font-bold text-gray-600 hover:bg-gray-50 transition">
                Cancelar
            </a>
            <button type="submit"
                class="px-8 py-2.5 rounded-lg bg-[#111318] text-white text-sm font-bold hover:brightness-110 transition">
                Guardar cambios
            </button>
        </div>
    </form>

</main>

<?php require __DIR__ . '/../_footer.php'; ?>
