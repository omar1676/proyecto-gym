<?php
require __DIR__ . '/../_header_admin.php';
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<main class="flex-1 bg-[#f7f7f8] px-4 py-8 sm:px-6 lg:px-8">
    <section class="mx-auto max-w-6xl">
        <div class="mb-7 pt-4">
            <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-indigo-600">Retention V1</p>
            <h1 class="mt-2 text-2xl font-extrabold tracking-tight text-[#111318] sm:text-3xl">Socios que podrían necesitar atención</h1>
            <p class="mt-2 max-w-3xl text-sm text-neutral-600">Se compara cada socio únicamente con su propio historial. Una detección no significa que vaya a darse de baja.</p>
        </div>

        <?php if (!empty($_GET['ok'])): ?>
            <div class="mb-5 rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-800"><?= $escape($_GET['ok']) ?></div>
        <?php endif; ?>
        <?php if (!empty($_GET['err'])): ?>
            <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800"><?= $escape($_GET['err']) ?></div>
        <?php endif; ?>

        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <article class="rounded-2xl border border-neutral-200 bg-white p-4"><p class="text-xs font-bold uppercase text-neutral-500">En bandeja</p><p class="mt-2 text-2xl font-extrabold"><?= (int)$metrics['total'] ?></p></article>
            <article class="rounded-2xl border border-neutral-200 bg-white p-4"><p class="text-xs font-bold uppercase text-neutral-500">Revisados</p><p class="mt-2 text-2xl font-extrabold"><?= (int)$metrics['reviewed'] ?></p></article>
            <article class="rounded-2xl border border-neutral-200 bg-white p-4"><p class="text-xs font-bold uppercase text-neutral-500">Descartados</p><p class="mt-2 text-2xl font-extrabold"><?= (int)$metrics['dismissed'] ?></p></article>
            <article class="rounded-2xl border border-neutral-200 bg-white p-4"><p class="text-xs font-bold uppercase text-neutral-500">Contactados</p><p class="mt-2 text-2xl font-extrabold"><?= (int)$metrics['contacted'] ?></p></article>
            <article class="rounded-2xl border border-neutral-200 bg-white p-4"><p class="text-xs font-bold uppercase text-neutral-500">Regresaron</p><p class="mt-2 text-2xl font-extrabold"><?= (int)$metrics['returned'] ?></p></article>
        </div>

        <?php if ($metrics['evaluated'] !== null): ?>
        <p class="mb-5 text-xs text-neutral-500">Última ejecución: <?= (int)$metrics['evaluated'] ?> evaluados · <?= (int)$metrics['insufficient'] ?> sin histórico suficiente · <?= (int)$metrics['normal'] ?> normales · <?= (int)$metrics['attention'] ?> atención · <?= (int)$metrics['high_attention'] ?> atención alta.</p>
        <?php endif; ?>

        <?php if ($detections === []): ?>
            <div class="rounded-3xl border border-dashed border-neutral-300 bg-white px-6 py-14 text-center">
                <h2 class="text-lg font-extrabold text-neutral-800">No hay casos pendientes</h2>
                <p class="mt-2 text-sm text-neutral-500">La bandeja se llenará cuando el job encuentre una caída explicable con histórico suficiente.</p>
            </div>
        <?php else: ?>
            <div class="space-y-4">
            <?php foreach ($detections as $item): ?>
                <article class="rounded-3xl border border-neutral-200 bg-white p-5 shadow-sm sm:p-6">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-extrabold text-neutral-900"><?= $escape(trim($item['nombre'].' '.$item['apellidos'])) ?></h2>
                                <span class="rounded-full px-3 py-1 text-xs font-extrabold <?= $item['level']==='HIGH_ATTENTION' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800' ?>">
                                    <?= $item['level']==='HIGH_ATTENTION' ? 'Atención alta' : 'Atención' ?>
                                </span>
                                <span class="rounded-full bg-neutral-100 px-3 py-1 text-xs font-bold text-neutral-700"><?= $escape($item['activity_family']==='GENERAL' ? 'Actividad general' : $item['activity_family']) ?></span>
                            </div>
                            <p class="mt-1 text-xs text-neutral-500"><?= $escape($item['sede_nombre']) ?> · última asistencia <?= $item['last_attendance_utc'] ? $escape(date('d/m/Y', strtotime($item['last_attendance_utc']))) : 'sin fecha' ?></p>
                            <p class="mt-4 max-w-3xl text-sm leading-6 text-neutral-700"><?= $escape($item['explanation']) ?></p>
                            <?php if (!empty($item['contacted_at_utc'])): ?><p class="mt-2 text-xs font-semibold text-neutral-500">Último contacto manual: <?= $escape(date('d/m/Y H:i', strtotime($item['contacted_at_utc']))) ?></p><?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-5 rounded-2xl border border-indigo-100 bg-indigo-50 p-4">
                        <p class="text-xs font-extrabold uppercase tracking-wider text-indigo-700">Previsualización — no enviado</p>
                        <p class="mt-2 text-sm leading-6 text-neutral-700"><?= $escape($item['suggested_message']) ?></p>
                    </div>

                    <div class="mt-5 flex flex-wrap gap-2">
                        <?php foreach ([
                            ['REVIEW','Revisado'], ['POSTPONE','Posponer 7 días'], ['CONTACT_MANUAL','Marcar contacto manual']
                        ] as [$action,$label]): ?>
                        <form method="POST" action="<?= APP_URL ?>/index.php?action=retention_action">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="detection_id" value="<?= (int)$item['id_retention_detection'] ?>">
                            <input type="hidden" name="version" value="<?= (int)$item['version'] ?>">
                            <input type="hidden" name="retention_action" value="<?= $escape($action) ?>">
                            <input type="hidden" name="postpone_days" value="7">
                            <input type="hidden" name="idempotency_key" value="<?= $escape(RequestContext::newId()) ?>">
                            <button class="rounded-full border border-neutral-300 px-4 py-2 text-xs font-bold text-neutral-700 hover:bg-neutral-50" type="submit"><?= $escape($label) ?></button>
                        </form>
                        <?php endforeach; ?>
                        <form method="POST" action="<?= APP_URL ?>/index.php?action=retention_action" class="flex flex-wrap gap-2">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="detection_id" value="<?= (int)$item['id_retention_detection'] ?>">
                            <input type="hidden" name="version" value="<?= (int)$item['version'] ?>">
                            <input type="hidden" name="retention_action" value="DISMISS">
                            <input type="hidden" name="idempotency_key" value="<?= $escape(RequestContext::newId()) ?>">
                            <label class="sr-only" for="reason-<?= (int)$item['id_retention_detection'] ?>">Motivo opcional</label>
                            <input id="reason-<?= (int)$item['id_retention_detection'] ?>" name="reason" maxlength="255" placeholder="Motivo opcional" class="rounded-full border border-neutral-300 px-4 py-2 text-xs">
                            <button class="rounded-full border border-neutral-300 px-4 py-2 text-xs font-bold text-neutral-700 hover:bg-neutral-50" type="submit">Descartar</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../_footer.php'; ?>
