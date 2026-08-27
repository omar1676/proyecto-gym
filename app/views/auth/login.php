<?php
/**
 * Paso 2 del acceso: login del gimnasio, con su propia marca.
 *
 * Toda la pantalla se construye con el logo y los colores de la ficha de la
 * sede, así que cada gimnasio ve la suya: el fondo es su color, el botón es su
 * color y arriba está su logo. Mientras no se suba uno se usa la inicial del
 * nombre sobre el color principal.
 *
 * El logo va siempre sobre una placa blanca. Un logo puede ser negro sobre
 * transparente (muchas marcas lo usan) y desaparecería sobre un fondo
 * oscuro; sobre blanco se ve cualquiera.
 *
 * Los colores se interpolan en atributos `style` porque son datos, no clases:
 * Marca solo deja pasar valores #rrggbb, así que no puede colarse otra cosa.
 */

require_once __DIR__ . '/../../helpers/Marca.php';
require_once __DIR__ . '/../../helpers/Csrf.php';

if (!isset($errores)) $errores = [];
if (!isset($exito))   $exito   = null;
if (!isset($old))     $old     = [];

$marca = Marca::de($gimnasio ?? null);

$sesionCaducada = !empty($_SESSION['caducada']);
$contrasenaCambiada = !empty($_SESSION['contrasena_cambiada']);
unset($_SESSION['contrasena_cambiada']);
unset($_SESSION['caducada']);

// Credenciales de prueba: solo llegan con valor en desarrollo (ver config.php).
// Lo que el usuario ya escribió manda siempre sobre el valor de prueba.
$devUsuario = defined('DEV_USUARIO')      ? DEV_USUARIO      : '';
$devPass    = defined('DEV_USUARIO_PASS') ? DEV_USUARIO_PASS : '';
$prellenado = ($devUsuario !== '' || $devPass !== '');

$e = function (?string $v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso — <?= $e($marca['nombre']) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.3.3"
            integrity="sha384-aJ9rL4k6lF+91guGvUFVSkpIcge7Zd9EiI4TQDLoK9kFaFJgKHgjEXVvG/qA5COj"
            crossorigin="anonymous"></script>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        /* Los colores de la sede como variables: así se usan igual en el botón,
           el foco de los campos y los enlaces sin repetirlos por todas partes. */
        :root {
            --marca:        <?= $e($marca['primario']) ?>;
            --marca-texto:  <?= $e($marca['texto']) ?>;
            --marca-enlace: <?= $e($marca['enlace']) ?>;
            --sobre-fondo:  <?= $e($marca['sobreFondo']) ?>;
            --tenue:        <?= $e($marca['tenue']) ?>;
        }
        /* Fondo: el color de la sede con un degradado suave hacia su versión
           más profunda, para que no quede un plano liso. */
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

            <!-- Marca del gimnasio: el logo siempre sobre blanco -->
            <div class="flex flex-col items-center gap-3 border-b border-[#ececf0] px-8 pt-9 pb-7">
                <?php if ($marca['logoUrl']): ?>
                <img src="<?= $e($marca['logoUrl']) ?>"
                     alt="<?= $e($marca['nombre']) ?>"
                     class="h-20 w-auto max-w-[240px] object-contain">
                <!-- El nombre va también con logo: distingue una sede de otra
                     cuando la cadena repite la misma imagen en todas. -->
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

                <h1 class="text-lg font-bold text-neutral-800">Acceso al panel</h1>
                <p class="mt-1 mb-6 text-sm text-neutral-500">Introduce tus credenciales para continuar.</p>

                <?php if ($sesionCaducada): ?>
                <div class="mb-5 rounded-xl border border-[#fcd34d] bg-[#fffbeb] px-4 py-3 text-sm font-medium text-[#b45309]">
                    Tu sesión se cerró por inactividad. Vuelve a entrar.
                </div>
                <?php endif; ?>

                <?php if ($contrasenaCambiada): ?>
                <div class="mb-5 rounded-xl border border-[#fcd34d] bg-[#fffbeb] px-4 py-3 text-sm font-medium text-[#b45309]">
                    Se ha cambiado la contraseña de esta cuenta, así que las sesiones abiertas se han cerrado.
                </div>
                <?php endif; ?>

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

                <form action="index.php?action=autenticar" method="POST" class="space-y-4">
                    <?= Csrf::field() ?>
                    <!-- El gimnasio no viaja en el formulario: sale de la sesión
                         abierta en el primer paso, así no se puede manipular. -->
                    <div>
                        <label for="usuario" class="mb-1.5 block text-xs font-extrabold uppercase tracking-widest text-neutral-500">
                            Usuario
                        </label>
                        <input id="usuario" name="usuario" type="text" required autofocus autocomplete="username"
                            value="<?= $e($old['usuario'] ?? $devUsuario) ?>"
                            class="campo w-full rounded-xl border border-[#d4d4d8] bg-white px-4 py-2.5 text-sm font-medium text-neutral-800 placeholder-neutral-400 transition"
                            placeholder="tu usuario">
                    </div>

                    <div>
                        <label for="contrasena" class="mb-1.5 block text-xs font-extrabold uppercase tracking-widest text-neutral-500">
                            Contraseña
                        </label>
                        <div class="relative">
                            <input id="contrasena" name="contrasena" type="password" required autocomplete="current-password"
                                value="<?= $e($devPass) ?>"
                                class="campo w-full rounded-xl border border-[#d4d4d8] bg-white px-4 py-2.5 pr-11 text-sm font-medium text-neutral-800 placeholder-neutral-400 transition"
                                placeholder="••••••••">
                            <button type="button" onclick="alternarClave()" tabindex="-1"
                                aria-label="Mostrar u ocultar la contraseña"
                                class="absolute inset-y-0 right-0 flex items-center px-3 text-neutral-400 transition hover:text-neutral-700">
                                <svg id="icono-ojo" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-1">
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-neutral-600">
                            <input type="checkbox" name="mantener_sesion" class="h-4 w-4 cursor-pointer rounded border-[#d4d4d8]"
                                   style="accent-color: <?= $e($marca['enlace']) ?>">
                            Mantener sesión
                        </label>
                        <a href="index.php?action=password_forgot" class="txt-marca text-sm font-medium transition hover:underline">
                            ¿Olvidaste la contraseña?
                        </a>
                    </div>

                    <button type="submit" class="btn-marca mt-2 w-full rounded-xl py-3 text-sm font-bold transition">
                        Entrar
                    </button>

                    <?php if ($prellenado): ?>
                    <!-- Aviso de desarrollo: que nunca pase inadvertido que el
                         formulario viene relleno. No existe fuera de development. -->
                    <p class="rounded-xl border border-[#fcd34d] bg-[#fffbeb] px-4 py-2.5 text-center text-xs font-medium text-[#b45309]">
                        Modo desarrollo: credenciales de prueba rellenadas desde el <code>.env</code>.
                    </p>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <p class="mt-6 text-center text-sm">
            <form method="POST" action="index.php?action=salir_gimnasio" class="inline">
               <?= Csrf::field() ?>
            <button type="submit" class="font-medium transition hover:underline"
               style="color: <?= $e($marca['tenue']) ?>">
                ← Salir de <?= $e($marca['nombre']) ?>
            </button>
            </form>
        </p>

    </div>
</main>

<script>
function alternarClave() {
    var campo = document.getElementById('contrasena');
    var ojo   = document.getElementById('icono-ojo');
    var oculto = campo.type === 'password';
    campo.type = oculto ? 'text' : 'password';
    ojo.innerHTML = oculto
        ? '<path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5c1.6 0 3 .5 4.2 1.1M21.5 12s-1.4 2.6-4 4.3M9.9 9.9a3 3 0 0 0 4.2 4.2M4 4l16 16"/>'
        : '<path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/>';
}
</script>

</body>
</html>
