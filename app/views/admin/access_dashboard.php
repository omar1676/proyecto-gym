<?php require __DIR__ . '/../_header_admin.php'; ?>
<main class="flex-1 min-w-0 bg-[#f7f7f8] px-4 py-6 sm:px-6 lg:px-8">
<section class="mx-auto max-w-6xl">
    <div class="pt-4">
        <h1 class="text-2xl font-extrabold text-[#111318]">Control de acceso</h1>
        <p class="mt-1 text-sm text-neutral-500">Estado lógico interno. No confirma aperturas ni entradas físicas.</p>
    </div>
    <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <article class="rounded-2xl border bg-white p-5"><p class="text-xs font-bold uppercase text-neutral-500">Temporales activos</p><p class="mt-2 text-3xl font-extrabold"><?= (int)$metrics['states']['TEMPORARY'] ?></p></article>
        <article class="rounded-2xl border bg-white p-5"><p class="text-xs font-bold uppercase text-neutral-500">Caducan hoy</p><p class="mt-2 text-3xl font-extrabold"><?= (int)$metrics['expiring_today'] ?></p></article>
        <article class="rounded-2xl border bg-white p-5"><p class="text-xs font-bold uppercase text-neutral-500">Caducan mañana</p><p class="mt-2 text-3xl font-extrabold"><?= (int)$metrics['expiring_tomorrow'] ?></p><p class="text-xs text-neutral-500"><?= (int)$metrics['expiring_72h'] ?> en 72 h</p></article>
        <article class="rounded-2xl border bg-white p-5"><p class="text-xs font-bold uppercase text-neutral-500">Suspendidos / denegados</p><p class="mt-2 text-3xl font-extrabold"><?= (int)$metrics['states']['SUSPENDED']+(int)$metrics['states']['DENIED'] ?></p></article>
        <article class="rounded-2xl border bg-white p-5"><p class="text-xs font-bold uppercase text-neutral-500">Fallos de sincronización</p><p class="mt-2 text-3xl font-extrabold"><?= (int)$metrics['sync']['FAILED'] ?></p><p class="text-xs text-neutral-500">Modo proveedor: <?= htmlspecialchars(defined('ACCESS_CONTROL_MODE') ? ACCESS_CONTROL_MODE : 'disabled',ENT_QUOTES,'UTF-8') ?> · separado de la política</p></article>
    </div>
    <div class="mt-6 overflow-x-auto rounded-2xl border bg-white">
        <table class="min-w-[850px] w-full text-left text-sm">
            <thead class="bg-neutral-100 text-xs uppercase text-neutral-500"><tr><th class="p-3">Socio</th><th class="p-3">Estado</th><th class="p-3">Motivo</th><th class="p-3">Inicio UTC</th><th class="p-3">Caducidad UTC</th><th class="p-3">Versión</th></tr></thead>
            <tbody><?php foreach($policies as $policy): ?><tr class="border-t"><td class="p-3 font-bold"><a class="text-indigo-700" href="index.php?action=admin_socios&amp;detalle=<?= (int)$policy['id_socio'] ?>"><?= htmlspecialchars(trim($policy['nombre'].' '.$policy['apellidos']),ENT_QUOTES,'UTF-8') ?></a></td><td class="p-3"><?= htmlspecialchars($policy['state'],ENT_QUOTES,'UTF-8') ?></td><td class="p-3"><?= htmlspecialchars($policy['reason_code'],ENT_QUOTES,'UTF-8') ?></td><td class="p-3"><?= htmlspecialchars((string)$policy['starts_at_utc'],ENT_QUOTES,'UTF-8') ?></td><td class="p-3"><?= htmlspecialchars((string)$policy['expires_at_utc'],ENT_QUOTES,'UTF-8') ?></td><td class="p-3"><?= (int)$policy['version'] ?></td></tr><?php endforeach; ?></tbody>
        </table>
        <?php if(!$policies): ?><p class="p-6 text-sm text-neutral-500">Todavía no existen excepciones manuales de acceso.</p><?php endif; ?>
    </div>
</section>
</main>
<?php require __DIR__ . '/../_footer.php'; ?>
