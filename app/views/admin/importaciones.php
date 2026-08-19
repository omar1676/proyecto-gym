<?php
require_once __DIR__ . '/../../helpers/Csrf.php';
require __DIR__ . '/../_header_admin.php';
$h = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$batchActual = $reporte['batch'] ?? null;
$campos = match ($batchActual['entity_type'] ?? 'socios') {
    'productos' => $camposProductos,
    'membresias' => $camposMembresias,
    default => $camposSocios,
};
?>
<main class="flex-1 bg-[#f7f7f8] px-5 py-8 lg:px-8">
  <section class="mx-auto max-w-6xl">
    <div class="pt-4">
      <h1 class="text-2xl font-extrabold tracking-tight text-[#111318] sm:text-3xl">Migraciones e importaciones</h1>
      <p class="mt-2 text-sm text-neutral-500">CSV controlado por tenant: subir, mapear, simular, revisar y confirmar.</p>
    </div>

    <?php if ($mensajeImportacion !== ''): ?>
      <div class="mt-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-semibold text-green-800" role="status"><?= $h($mensajeImportacion) ?></div>
    <?php endif; ?>
    <?php if ($errorImportacion !== ''): ?>
      <div class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700" role="alert"><?= $h($errorImportacion) ?></div>
    <?php endif; ?>

    <?php if ($sedeActivaImportacion === null): ?>
      <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
        <strong>Selecciona una sede concreta.</strong> La sede se obtiene de la sesión y nunca de una columna del archivo.
      </div>
    <?php else: ?>
      <form method="POST" enctype="multipart/form-data" action="<?= APP_URL ?>/index.php?action=admin_importacion_subir"
            class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-sm">
        <?= Csrf::field() ?>
        <h2 class="text-lg font-extrabold text-[#111318]">1. Subir CSV</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-3">
          <div>
            <label for="entity-type" class="mb-1 block text-sm font-bold">Entidad</label>
            <select id="entity-type" name="entity_type" required class="w-full rounded-xl border border-neutral-300 px-3 py-2.5">
              <option value="socios">Socios</option>
              <option value="productos">Productos</option>
              <option value="membresias">Membresías (solo dry-run)</option>
            </select>
          </div>
          <div>
            <label for="source-system" class="mb-1 block text-sm font-bold">Fuente lógica</label>
            <input id="source-system" name="source_system" value="generic" required maxlength="64" pattern="[a-z0-9][a-z0-9._-]{0,63}"
                   class="w-full rounded-xl border border-neutral-300 px-3 py-2.5">
          </div>
          <div>
            <label for="import-file" class="mb-1 block text-sm font-bold">Archivo CSV UTF-8</label>
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) IMPORT_MAX_BYTES ?>">
            <input id="import-file" type="file" name="archivo" accept=".csv,text/csv" required
                   class="w-full rounded-xl border border-neutral-300 px-3 py-2">
          </div>
        </div>
        <p class="mt-3 text-xs text-neutral-500">Máximo <?= number_format(IMPORT_MAX_BYTES / 1048576, 1, ',', '.') ?> MB y <?= number_format(IMPORT_MAX_ROWS, 0, ',', '.') ?> filas. XLSX y JSON aún no están habilitados.</p>
        <button class="mt-4 rounded-full bg-[#111318] px-5 py-2.5 text-sm font-bold text-white">Analizar encabezados</button>
      </form>
    <?php endif; ?>

    <?php if ($batchActual): ?>
      <section class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-extrabold text-[#111318]">Batch <?= $h($batchActual['uuid']) ?></h2>
            <p class="mt-1 text-sm text-neutral-500"><?= $h($batchActual['original_name']) ?> · <?= $h($batchActual['entity_type']) ?> · SHA-256 <?= $h(substr($batchActual['file_hash'], 0, 16)) ?>…</p>
          </div>
          <span class="rounded-full bg-neutral-100 px-3 py-1 text-xs font-bold uppercase"><?= $h($batchActual['status']) ?></span>
        </div>

        <?php if (in_array($batchActual['status'], ['uploaded','dry_run_ready','failed'], true) && $batchActual['storage_key']): ?>
          <form method="POST" action="<?= APP_URL ?>/index.php?action=admin_importacion_dry_run" class="mt-6">
            <?= Csrf::field() ?>
            <input type="hidden" name="batch" value="<?= $h($batchActual['uuid']) ?>">
            <h3 class="text-base font-extrabold">2. Mapeo de columnas y dry-run</h3>
            <div class="mt-4 overflow-x-auto">
              <table class="w-full min-w-[620px] text-left text-sm">
                <thead><tr class="border-b"><th class="p-2">Columna externa</th><th class="p-2">Campo interno</th></tr></thead>
                <tbody>
                <?php foreach ($batchActual['headers'] as $i => $external): ?>
                  <tr class="border-b border-neutral-100">
                    <td class="p-2 font-mono text-xs"><?= $h($external) ?><input type="hidden" name="external[]" value="<?= $h($external) ?>"></td>
                    <td class="p-2">
                      <label class="sr-only" for="map-<?= (int) $i ?>">Mapeo para <?= $h($external) ?></label>
                      <select id="map-<?= (int) $i ?>" name="internal[]" class="w-full rounded-lg border border-neutral-300 px-3 py-2">
                        <option value="">Ignorar</option>
                        <?php foreach ($campos as $field): ?>
                          <option value="<?= $h($field) ?>" <?= ($batchActual['mapping'][$external] ?? null) === $field ? 'selected' : '' ?>><?= $h($field) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php if (in_array($batchActual['entity_type'], ['socios','membresias'], true)): ?>
              <div class="mt-4 max-w-sm">
                <label for="date-format" class="mb-1 block text-sm font-bold">Formato explícito de fecha</label>
                <select id="date-format" name="date_format" class="w-full rounded-xl border border-neutral-300 px-3 py-2.5">
                  <option value="Y-m-d" <?= ($batchActual['options']['date_format'] ?? '') === 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                  <option value="d/m/Y" <?= ($batchActual['options']['date_format'] ?? '') === 'd/m/Y' ? 'selected' : '' ?>>DD/MM/YYYY</option>
                </select>
              </div>
            <?php endif; ?>
            <button class="mt-5 rounded-full bg-[#4f46e5] px-5 py-2.5 text-sm font-bold text-white">Ejecutar dry-run</button>
          </form>
        <?php endif; ?>

        <?php if ((int) $batchActual['row_count'] > 0): ?>
          <div class="mt-6 grid gap-3 sm:grid-cols-5">
            <?php foreach ([['Filas','row_count'],['Válidas','valid_count'],['Warnings','warning_count'],['Errores','error_count'],['Importadas','imported_count']] as [$label,$key]): ?>
              <div class="rounded-xl bg-neutral-50 p-4"><p class="text-xs font-bold uppercase text-neutral-500"><?= $h($label) ?></p><p class="mt-1 text-2xl font-extrabold"><?= (int) $batchActual[$key] ?></p></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($reporte['issues'])): ?>
          <div class="mt-6 overflow-x-auto">
            <h3 class="mb-3 text-base font-extrabold">Informe de validación</h3>
            <table class="w-full min-w-[850px] text-left text-sm">
              <thead><tr class="border-b bg-neutral-50"><th class="p-2">Fila</th><th class="p-2">Estado</th><th class="p-2">Campo</th><th class="p-2">Problema</th><th class="p-2">Valor</th><th class="p-2">Acción</th></tr></thead>
              <tbody>
              <?php foreach ($reporte['issues'] as $issue): ?>
                <tr class="border-b border-neutral-100">
                  <td class="p-2"><?= (int) $issue['row_number'] ?></td>
                  <td class="p-2 font-bold <?= $issue['severity'] === 'ERROR' ? 'text-red-700' : 'text-amber-700' ?>"><?= $h($issue['severity']) ?></td>
                  <td class="p-2"><?= $h($issue['field_name'] ?: '—') ?></td>
                  <td class="p-2"><?= $h($issue['message']) ?></td>
                  <td class="p-2 font-mono text-xs"><?= $h($issue['value_excerpt'] ?: '—') ?></td>
                  <td class="p-2"><?= $h($issue['proposed_action'] ?: '—') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <div class="mt-6 flex flex-wrap gap-3">
          <?php if (in_array($batchActual['status'], ['dry_run_ready','partial','failed'], true) && (int) $batchActual['error_count'] === 0): ?>
            <form method="POST" action="<?= APP_URL ?>/index.php?action=admin_importacion_confirmar">
              <?= Csrf::field() ?><input type="hidden" name="batch" value="<?= $h($batchActual['uuid']) ?>">
              <button class="rounded-full bg-green-700 px-5 py-2.5 text-sm font-bold text-white"><?= $batchActual['status'] === 'partial' ? 'Reanudar importación' : 'Confirmar importación' ?></button>
            </form>
          <?php endif; ?>
          <?php if (in_array($batchActual['status'], ['uploaded','dry_run_ready','failed'], true)): ?>
            <form method="POST" action="<?= APP_URL ?>/index.php?action=admin_importacion_descartar" onsubmit="return confirm('¿Descartar este batch y su archivo temporal?')">
              <?= Csrf::field() ?><input type="hidden" name="batch" value="<?= $h($batchActual['uuid']) ?>">
              <button class="rounded-full border border-red-200 px-5 py-2.5 text-sm font-bold text-red-700">Descartar</button>
            </form>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <section class="mt-6 rounded-[22px] border border-[#e4e4e7] bg-white p-6 shadow-sm">
      <h2 class="text-lg font-extrabold">Historial de batches</h2>
      <?php if (!$batches): ?><p class="mt-3 text-sm text-neutral-500">Todavía no hay importaciones.</p><?php else: ?>
        <div class="mt-4 overflow-x-auto"><table class="w-full min-w-[850px] text-left text-sm">
          <thead><tr class="border-b"><th class="p-2">Fecha</th><th class="p-2">Archivo</th><th class="p-2">Entidad</th><th class="p-2">Estado</th><th class="p-2">Filas</th><th class="p-2">Importadas</th><th class="p-2"></th></tr></thead>
          <tbody><?php foreach ($batches as $item): ?><tr class="border-b border-neutral-100">
            <td class="p-2"><?= $h($item['created_at']) ?></td><td class="p-2"><?= $h($item['original_name']) ?></td>
            <td class="p-2"><?= $h($item['entity_type']) ?></td><td class="p-2"><?= $h($item['status']) ?></td>
            <td class="p-2"><?= (int) $item['row_count'] ?></td><td class="p-2"><?= (int) $item['imported_count'] ?></td>
            <td class="p-2"><a class="font-bold text-[#4f46e5]" href="<?= APP_URL ?>/index.php?action=admin_importaciones&amp;batch=<?= rawurlencode($item['uuid']) ?>">Ver</a></td>
          </tr><?php endforeach; ?></tbody>
        </table></div>
      <?php endif; ?>
    </section>
  </section>
</main>
<?php require __DIR__ . '/../_footer.php'; ?>
