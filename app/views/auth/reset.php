<?php
/**
 * Nueva contraseña, con la marca del gimnasio del empleado.
 *
 * Este enlace llega por correo y suele abrirse en otro navegador, donde no hay
 * sesión de gimnasio: la marca la da el controlador a partir de la cuenta a la
 * que pertenece el token, no de la sesión.
 */

require_once __DIR__ . '/../../helpers/Csrf.php';
require_once __DIR__ . '/../../helpers/Marca.php';

if (!isset($errores)) $errores = [];

$marca = Marca::de($gimnasio ?? null);

$e = function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nueva contraseña — <?= $e($marca['nombre']) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        :root {
            --marca:        <?= $e($marca['primario']) ?>;
            --marca-texto:  <?= $e($marca['texto']) ?>;
            --marca-enlace: <?= $e($marca['enlace']) ?>;
        }
        .fondo-marca {
            background:
                radial-gradient(120% 90% at 50% 0%, <?= $e($marca['fondoA']) ?> 0%, <?= $e($marca['fondoB']) ?> 100%);
        }
        .btn-marca       { background: var(--marca); color: var(--marca-texto); }
        .btn-marca:hover { filter: brightness(1.12); }
        .campo:focus     { border-color: var(--marca-enlace); outline: none;
                           box-shadow: 0 0 0 3px color-mix(in srgb, var(--marca-enlace) 22%, transparent); }
    </style>
</head>
<body class="fondo-marca min-h-screen">

<main class="flex min-h-screen items-center justify-center px-4 py-10">
    <div class="w-full max-w-[420px]">

        <div class="overflow-hidden rounded-[24px] bg-white shadow-[0_20px_50px_rgba(0,0,0,0.25)]">

            <div class="flex flex-col items-center gap-3 border-b border-[#ececf0] px-8 pt-9 pb-7">
                <?php if ($marca['logoUrl']): ?>
                <img src="<?= $e($marca['logoUrl']) ?>"
                     alt="<?= $e($marca['nombre']) ?>"
                     class="h-20 w-auto max-w-[240px] object-contain">
                <span class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-400">
                    <?= $e($marca['nombre']) ?>
                </span>
                <?php else: ?>
                <div class="flex h-16 w-16 items-center justify-center rounded-2xl text-2xl font-extrabold"
                     style="background: <?= $e($marca['primario']) ?>; color: <?= $e($marca['texto']) ?>">
                    <?= $e($marca['inicial']) ?>
                </div>
                <span class="text-lg font-extrabold tracking-tight text-[#111318]">
                    <?= $e($marca['nombre']) ?>
                </span>
                <?php endif; ?>
            </div>

            <div class="px-8 pb-8 pt-7">

                <h1 class="text-lg font-bold text-neutral-800">Nueva contraseña</h1>
                <p class="mt-1 mb-6 text-sm text-neutral-500">Elige una contraseña que no uses en otro sitio.</p>

                <?php if (!empty($errores)): ?>
                <div class="mb-5 rounded-xl border border-[#fecaca] bg-[#fee2e2] px-4 py-3 text-sm font-medium text-[#dc2626]">
                    <?php foreach ($errores as $error): ?>
                    <p><?= $e($error) ?></p>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form action="index.php?action=password_reset_submit" method="POST" class="space-y-4">
                    <?= Csrf::field() ?>
                    <div>
                        <label for="contrasena" class="mb-1.5 block text-xs font-extrabold uppercase tracking-widest text-neutral-500">
                            Nueva contraseña
                        </label>
                        <input id="contrasena" name="contrasena" type="password" required minlength="8" autofocus autocomplete="new-password"
                            class="campo w-full rounded-xl border border-[#d4d4d8] bg-white px-4 py-2.5 text-sm font-medium text-neutral-800 placeholder-neutral-400 transition"
                            placeholder="••••••••">
                    </div>

                    <div>
                        <label for="confirmar_contrasena" class="mb-1.5 block text-xs font-extrabold uppercase tracking-widest text-neutral-500">
                            Repítela
                        </label>
                        <input id="confirmar_contrasena" name="confirmar_contrasena" type="password" required minlength="8" autocomplete="new-password"
                            class="campo w-full rounded-xl border border-[#d4d4d8] bg-white px-4 py-2.5 text-sm font-medium text-neutral-800 placeholder-neutral-400 transition"
                            placeholder="••••••••">
                    </div>

                    <p class="text-xs text-neutral-400">Mínimo 8 caracteres.</p>

                    <button type="submit" class="btn-marca mt-2 w-full rounded-xl py-3 text-sm font-bold transition">
                        Cambiar contraseña
                    </button>
                </form>
            </div>
        </div>

    </div>
</main>

</body>
</html>
