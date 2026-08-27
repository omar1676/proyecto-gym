<?php
/**
 * Recuperar contraseña, con la marca del gimnasio desde el que se pidió.
 *
 * Se llega aquí desde el login de la sede, así que la pantalla conserva su
 * logo y sus colores: para el empleado es la misma casa, no otra web. Si se
 * entra sin gimnasio identificado (un enlace guardado, por ejemplo), Marca
 * devuelve la identidad neutra de la plataforma.
 */

require_once __DIR__ . '/../../helpers/Csrf.php';
require_once __DIR__ . '/../../helpers/Marca.php';

if (!isset($errores)) $errores = [];
if (!isset($exito))   $exito   = null;
if (!isset($old))     $old     = [];

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
    <title>Recuperar contraseña — <?= $e($marca['nombre']) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.3.3"
            integrity="sha384-aJ9rL4k6lF+91guGvUFVSkpIcge7Zd9EiI4TQDLoK9kFaFJgKHgjEXVvG/qA5COj"
            crossorigin="anonymous"></script>
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
        .txt-marca       { color: var(--marca-enlace); }
        .campo:focus     { border-color: var(--marca-enlace); outline: none;
                           box-shadow: 0 0 0 3px color-mix(in srgb, var(--marca-enlace) 22%, transparent); }
    </style>
</head>
<body class="fondo-marca min-h-screen">

<main class="flex min-h-screen items-center justify-center px-4 py-10">
    <div class="w-full max-w-[420px]">

        <div class="overflow-hidden rounded-[24px] bg-white shadow-[0_20px_50px_rgba(0,0,0,0.25)]">

            <!-- El logo, siempre sobre blanco: puede ser oscuro sobre transparente. -->
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

                <h1 class="text-lg font-bold text-neutral-800">Recuperar contraseña</h1>
                <p class="mt-1 mb-6 text-sm text-neutral-500">
                    Introduce tu correo y te enviaremos un enlace para restablecerla.
                </p>

                <?php if (!empty($errores)): ?>
                <div class="mb-5 rounded-xl border border-[#fecaca] bg-[#fee2e2] px-4 py-3 text-sm font-medium text-[#dc2626]">
                    <?php foreach ($errores as $error): ?>
                    <p><?= $e($error) ?></p>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($exito)): ?>
                <div class="mb-5 rounded-xl border border-[#bbf7d0] bg-[#f0fdf4] px-4 py-3 text-sm font-medium text-[#15803d]">
                    <?= $e($exito) ?>
                </div>
                <?php endif; ?>

                <form action="index.php?action=password_forgot_submit" method="POST" class="space-y-4">
                    <?= Csrf::field() ?>

                    <div>
                        <label for="correo" class="mb-1.5 block text-xs font-extrabold uppercase tracking-widest text-neutral-500">
                            Correo electrónico
                        </label>
                        <input id="correo" name="correo" type="email" required autofocus autocomplete="email"
                            value="<?= $e($old['correo'] ?? '') ?>"
                            class="campo w-full rounded-xl border border-[#d4d4d8] bg-white px-4 py-2.5 text-sm font-medium text-neutral-800 placeholder-neutral-400 transition"
                            placeholder="tu@correo.com">
                    </div>

                    <button type="submit" class="btn-marca mt-2 w-full rounded-xl py-3 text-sm font-bold transition">
                        Enviar enlace de recuperación
                    </button>

                    <p class="pt-1 text-center text-sm">
                        <!-- Con gimnasio identificado se vuelve a su login; si no,
                             al primer paso. Lo decide el controlador. -->
                        <a href="index.php?action=<?= $e($volverA ?? 'login') ?>" class="txt-marca font-medium transition hover:underline">
                            Volver al inicio de sesión
                        </a>
                    </p>
                </form>
            </div>
        </div>

    </div>
</main>

</body>
</html>
