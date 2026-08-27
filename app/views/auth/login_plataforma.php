<?php
/**
 * Paso 1 del acceso: identificación del GIMNASIO.
 *
 * Aquí manda NUESTRA marca. Se pide el email y la contraseña del gimnasio
 * (p. ej. acceso.sede@cliente.example); solo después se muestran los
 * empleados de esa sede.
 *
 * A propósito no se lista ningún gimnasio: desde fuera no debe poder saberse
 * cuáles existen.
 *
 * El logo sale de APP_LOGO (.env) y se pinta sobre una placa blanca: el de
 * un logo negro sobre transparente desaparecería sobre este fondo
 * oscuro. Sin APP_LOGO se mantiene el icono genérico.
 */

require_once __DIR__ . '/../../helpers/Csrf.php';

if (!isset($errores)) $errores = [];
if (!isset($exito))   $exito   = null;
if (!isset($old))     $old     = [];

$nombreApp = defined('APP_NOMBRE') ? APP_NOMBRE : 'Gimnasio';
$logoApp   = (defined('APP_LOGO') && APP_LOGO !== ''
              && is_file(__DIR__ . '/../../../public/assets/marca/' . APP_LOGO))
             ? 'assets/marca/' . rawurlencode(APP_LOGO)
             : null;

$sesionCaducada = !empty($_SESSION['caducada']);
$contrasenaCambiada = !empty($_SESSION['contrasena_cambiada']);
unset($_SESSION['contrasena_cambiada']);
unset($_SESSION['caducada']);

// Credenciales de prueba: solo llegan con valor en desarrollo (ver config.php).
// Lo que el usuario ya escribió manda siempre sobre el valor de prueba.
$devEmail = defined('DEV_GIMNASIO_EMAIL') ? DEV_GIMNASIO_EMAIL : '';
$devPass  = defined('DEV_GIMNASIO_PASS')  ? DEV_GIMNASIO_PASS  : '';
$prellenado = ($devEmail !== '' || $devPass !== '');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso — <?= htmlspecialchars($nombreApp, ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.3.3"
            integrity="sha384-aJ9rL4k6lF+91guGvUFVSkpIcge7Zd9EiI4TQDLoK9kFaFJgKHgjEXVvG/qA5COj"
            crossorigin="anonymous"></script>
    <style>body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }</style>
</head>
<body class="min-h-screen bg-[#111318] text-neutral-100">

<main class="flex min-h-screen items-center justify-center px-4 py-10">
    <div class="w-full max-w-[400px]">

        <!-- ================= MARCA DE LA PLATAFORMA =================
             El archivo se cambia en APP_LOGO (.env), dentro de
             public/assets/marca/. Sin logo queda el icono genérico.
        -->
        <div class="mb-8 flex flex-col items-center gap-3">
            <?php if ($logoApp): ?>
            <div class="rounded-2xl bg-white px-6 py-4">
                <img src="<?= htmlspecialchars($logoApp, ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars($nombreApp, ENT_QUOTES, 'UTF-8') ?>"
                     class="h-16 w-auto max-w-[220px] object-contain">
            </div>
            <h1 class="text-xs font-extrabold uppercase tracking-[0.18em] text-neutral-400">
                Plataforma de gestión
            </h1>
            <?php else: ?>
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl border border-white/20 bg-white/10">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 6.5v11M17.5 6.5v11M3 9v6M21 9v6M6.5 12h11"/>
                </svg>
            </div>
            <h1 class="text-xl font-extrabold uppercase tracking-tight text-white">
                Plataforma de gestión
            </h1>
            <?php endif; ?>
        </div>
        <!-- =========================================================== -->

        <div class="rounded-[20px] border border-white/10 bg-white/[0.04] p-8">

            <h2 class="text-base font-bold text-white">Acceso del gimnasio</h2>
            <p class="mt-1 mb-6 text-sm text-neutral-400">
                Identifica tu gimnasio para continuar.
            </p>

            <?php if ($sesionCaducada): ?>
            <div class="mb-5 rounded-xl border border-[#fcd34d]/40 bg-[#fcd34d]/10 px-4 py-3 text-sm font-medium text-[#fcd34d]">
                La sesión se cerró por inactividad.
            </div>
            <?php endif; ?>

            <?php if ($contrasenaCambiada): ?>
            <div class="mb-5 rounded-xl border border-[#fcd34d]/40 bg-[#fcd34d]/10 px-4 py-3 text-sm font-medium text-[#fcd34d]">
                Se cambió la contraseña de la cuenta y se cerraron las sesiones abiertas.
            </div>
            <?php endif; ?>

            <?php if (!empty($errores)): ?>
            <div class="mb-5 rounded-xl border border-[#f87171]/40 bg-[#f87171]/10 px-4 py-3 text-sm font-medium text-[#fca5a5]">
                <?php foreach ($errores as $error): ?>
                <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($exito)): ?>
            <div class="mb-5 rounded-xl border border-[#4ade80]/40 bg-[#4ade80]/10 px-4 py-3 text-sm font-medium text-[#86efac]">
                <?= htmlspecialchars($exito, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <form action="index.php?action=autenticar_gimnasio" method="POST" class="space-y-4">
                <?= Csrf::field() ?>

                <div>
                    <label for="email" class="mb-1.5 block text-xs font-extrabold uppercase tracking-widest text-neutral-400">
                        Email del gimnasio
                    </label>
                    <input id="email" name="email" type="email" required autofocus autocomplete="username"
                        value="<?= htmlspecialchars($old['email'] ?? $devEmail, ENT_QUOTES, 'UTF-8') ?>"
                        class="w-full rounded-xl border border-white/15 bg-white/5 px-4 py-2.5 text-sm font-medium text-white placeholder-neutral-500 transition focus:border-white/40 focus:outline-none focus:ring-2 focus:ring-white/10"
                        placeholder="gimnasio@ejemplo.com">
                </div>

                <div>
                    <label for="contrasena" class="mb-1.5 block text-xs font-extrabold uppercase tracking-widest text-neutral-400">
                        Contraseña
                    </label>
                    <div class="relative">
                        <input id="contrasena" name="contrasena" type="password" required autocomplete="current-password"
                            value="<?= htmlspecialchars($devPass, ENT_QUOTES, 'UTF-8') ?>"
                            class="w-full rounded-xl border border-white/15 bg-white/5 px-4 py-2.5 pr-11 text-sm font-medium text-white placeholder-neutral-500 transition focus:border-white/40 focus:outline-none focus:ring-2 focus:ring-white/10"
                            placeholder="••••••••">
                        <button type="button" onclick="alternarClave()" tabindex="-1"
                            aria-label="Mostrar u ocultar la contraseña"
                            class="absolute inset-y-0 right-0 flex items-center px-3 text-neutral-500 transition hover:text-white">
                            <svg id="icono-ojo" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit"
                    class="mt-2 w-full rounded-xl bg-white py-3 text-sm font-bold text-[#111318] transition hover:bg-neutral-200">
                    Continuar
                </button>
            </form>
        </div>

        <?php if ($prellenado): ?>
        <!-- Aviso de desarrollo: que nunca pase inadvertido que el formulario
             viene relleno. Este bloque no existe fuera de APP_ENV=development. -->
        <p class="mt-6 rounded-xl border border-[#fcd34d]/40 bg-[#fcd34d]/10 px-4 py-2.5 text-center text-xs font-medium text-[#fcd34d]">
            Modo desarrollo: credenciales de prueba rellenadas desde el <code>.env</code>.
        </p>
        <?php endif; ?>

        <p class="mt-8 text-center text-xs text-neutral-500">
            Acceso restringido. Cada gimnasio entra con sus propias credenciales.
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
