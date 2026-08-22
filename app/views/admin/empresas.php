<?php
require_once __DIR__ . '/../../helpers/Csrf.php';
require __DIR__ . '/../_header_admin.php';
$e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<main class="flex-1 bg-[#f7f7f8] px-5 py-8 lg:px-8">
  <section class="mx-auto max-w-6xl">
    <div class="pt-4">
      <h1 class="text-3xl font-extrabold text-[#111318]">Empresas de la plataforma</h1>
      <p class="mt-2 text-sm text-neutral-500">Provisioning mínimo de tenants. Ninguna empresa queda activa antes de la revisión.</p>
    </div>

    <?php if ($error): ?><div class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700"><?= $e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="mt-5 rounded-xl border border-green-200 bg-green-50 p-4 text-sm font-bold text-green-800"><?= $e($success) ?></div><?php endif; ?>
    <?php if (is_array($credentials)): ?>
      <section class="mt-5 rounded-xl border-2 border-amber-300 bg-amber-50 p-5" aria-live="polite">
        <h2 class="font-extrabold text-amber-950">Credenciales temporales — copia única</h2>
        <p class="mt-1 text-sm text-amber-900">Guárdalas ahora en el gestor de contraseñas. No se volverán a mostrar.</p>
        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
          <div><dt class="font-bold">Acceso sede</dt><dd class="break-all font-mono"><?= $e($credentials['site_access_email'] ?? '') ?></dd></div>
          <div><dt class="font-bold">Clave sede</dt><dd class="break-all font-mono"><?= $e($credentials['site_temporary_password'] ?? '') ?></dd></div>
          <div><dt class="font-bold">Usuario dirección</dt><dd class="break-all font-mono"><?= $e($credentials['owner_username'] ?? '') ?></dd></div>
          <div><dt class="font-bold">Clave dirección</dt><dd class="break-all font-mono"><?= $e($credentials['owner_temporary_password'] ?? '') ?></dd></div>
        </dl>
      </section>
    <?php endif; ?>

    <section class="mt-8 rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-sm">
      <h2 class="text-xl font-extrabold">Nuevo gimnasio</h2>
      <p class="mt-1 text-sm text-neutral-500">Crea empresa, primera sede, marca, dirección y configuración en una sola transacción.</p>
      <form method="POST" action="<?= APP_URL ?>/index.php?action=admin_empresa_crear" class="mt-6 grid gap-4 md:grid-cols-2">
        <?= Csrf::field() ?>
        <input type="hidden" name="onboarding_request_id" value="<?= $e($requestId) ?>">
        <label class="text-sm font-bold">Razón social<input name="company_name" required maxlength="150" value="<?= $e($old['company_name'] ?? '') ?>" class="mt-1 w-full rounded-xl border p-2.5 font-normal"></label>
        <label class="text-sm font-bold">Nombre comercial<input name="commercial_name" required maxlength="150" value="<?= $e($old['commercial_name'] ?? '') ?>" class="mt-1 w-full rounded-xl border p-2.5 font-normal"></label>
        <label class="text-sm font-bold">Email empresa<input type="email" name="company_email" required maxlength="190" value="<?= $e($old['company_email'] ?? '') ?>" class="mt-1 w-full rounded-xl border p-2.5 font-normal"></label>
        <label class="text-sm font-bold">Teléfono<input name="phone" required maxlength="30" value="<?= $e($old['phone'] ?? '') ?>" class="mt-1 w-full rounded-xl border p-2.5 font-normal"></label>
        <label class="text-sm font-bold">Primera sede<input name="site_name" required maxlength="120" value="<?= $e($old['site_name'] ?? '') ?>" class="mt-1 w-full rounded-xl border p-2.5 font-normal"></label>
        <label class="text-sm font-bold">Email técnico de acceso<input type="email" name="site_access_email" required maxlength="190" value="<?= $e($old['site_access_email'] ?? '') ?>" class="mt-1 w-full rounded-xl border p-2.5 font-normal"></label>
        <label class="text-sm font-bold">Nombre de dirección<input name="owner_name" required maxlength="100" value="<?= $e($old['owner_name'] ?? '') ?>" class="mt-1 w-full rounded-xl border p-2.5 font-normal"></label>
        <label class="text-sm font-bold">Apellidos de dirección<input name="owner_surname" required maxlength="150" value="<?= $e($old['owner_surname'] ?? '') ?>" class="mt-1 w-full rounded-xl border p-2.5 font-normal"></label>
        <label class="text-sm font-bold">Email de dirección<input type="email" name="owner_email" required maxlength="190" value="<?= $e($old['owner_email'] ?? '') ?>" class="mt-1 w-full rounded-xl border p-2.5 font-normal"></label>
        <label class="text-sm font-bold">Usuario de dirección<input name="owner_username" required maxlength="60" pattern="[a-zA-Z0-9._-]{3,60}" value="<?= $e($old['owner_username'] ?? '') ?>" class="mt-1 w-full rounded-xl border p-2.5 font-normal"></label>
        <label class="text-sm font-bold">Color principal<input type="color" name="primary_color" value="<?= $e($old['primary_color'] ?? '#4f46e5') ?>" class="mt-1 h-11 w-full rounded-xl border p-1"></label>
        <label class="text-sm font-bold">Color de texto<input type="color" name="text_color" value="<?= $e($old['text_color'] ?? '#ffffff') ?>" class="mt-1 h-11 w-full rounded-xl border p-1"></label>
        <label class="text-sm font-bold">Zona horaria<select name="timezone" class="mt-1 w-full rounded-xl border p-2.5 font-normal"><option value="Europe/Madrid">Europe/Madrid</option><option value="Atlantic/Canary">Atlantic/Canary</option></select></label>
        <label class="text-sm font-bold">Categorías iniciales, una por línea<textarea name="categories" maxlength="2000" class="mt-1 w-full rounded-xl border p-2.5 font-normal" placeholder="Bebidas&#10;Suplementos"><?= $e($old['categories'] ?? '') ?></textarea></label>
        <fieldset class="md:col-span-2 rounded-xl border p-4">
          <legend class="px-2 text-sm font-extrabold">Tarifa inicial opcional</legend>
          <div class="grid gap-3 md:grid-cols-4">
            <label class="text-xs font-bold">Nombre<input name="membership_name" maxlength="100" class="mt-1 w-full rounded-xl border p-2.5 font-normal"></label>
            <label class="text-xs font-bold">Precio<input name="membership_price" inputmode="decimal" class="mt-1 w-full rounded-xl border p-2.5 font-normal"></label>
            <label class="text-xs font-bold">Meses<input type="number" name="membership_duration" min="1" max="120" class="mt-1 w-full rounded-xl border p-2.5 font-normal"></label>
            <label class="text-xs font-bold">IVA<input name="membership_vat" value="21.00" inputmode="decimal" class="mt-1 w-full rounded-xl border p-2.5 font-normal"></label>
          </div>
          <label class="mt-3 block text-xs font-bold">Descripción<input name="membership_description" maxlength="255" class="mt-1 w-full rounded-xl border p-2.5 font-normal"></label>
        </fieldset>
        <p class="md:col-span-2 text-xs text-neutral-500">Email funcional y control de acceso nacen desactivados. Importación es opcional y se ejecuta después mediante el flujo validado existente.</p>
        <button type="submit" class="md:col-span-2 rounded-full bg-[#111318] px-6 py-3 font-bold text-white">Crear y preparar revisión</button>
      </form>
    </section>

    <section class="mt-8 overflow-hidden rounded-[22px] border border-[#e4e4e7] bg-white shadow-sm">
      <div class="overflow-x-auto"><table class="min-w-[900px] w-full text-left text-sm">
        <thead class="bg-neutral-100"><tr><th class="p-4">Empresa</th><th class="p-4">Estado</th><th class="p-4">Sedes</th><th class="p-4">Dirección</th><th class="p-4">Tarifas</th><th class="p-4">Categorías</th><th class="p-4">Acción</th></tr></thead>
        <tbody>
        <?php foreach ($companies as $company): ?>
          <tr class="border-t"><td class="p-4"><strong><?= $e($company['nombre_comercial'] ?: $company['nombre']) ?></strong><br><span class="text-xs text-neutral-500"><?= $e($company['slug']) ?></span></td>
          <td class="p-4"><span class="rounded-full bg-neutral-100 px-3 py-1 text-xs font-bold"><?= $e($company['onboarding_state']) ?></span></td>
          <td class="p-4"><?= (int) $company['sites'] ?></td><td class="p-4"><?= (int) $company['owners'] ?></td><td class="p-4"><?= (int) $company['membership_types'] ?></td><td class="p-4"><?= (int) $company['categories'] ?></td>
          <td class="p-4"><?php if ($company['onboarding_state'] === 'READY_FOR_REVIEW'): ?><form method="POST" action="<?= APP_URL ?>/index.php?action=admin_empresa_activar"><?= Csrf::field() ?><input type="hidden" name="company_id" value="<?= (int) $company['id_empresa'] ?>"><button class="rounded-full bg-green-700 px-4 py-2 text-xs font-bold text-white">Activar</button></form><?php else: ?><span class="text-xs text-neutral-400">Sin acción</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
  </section>
</main>
<?php require __DIR__ . '/../_footer.php'; ?>
