<?php
if (!isset($logs))      $logs      = [];
if (!isset($autores))   $autores   = [];
if (!isset($pageTitle)) $pageTitle = 'Historial de actividad';
$paginaActiva = 'log';
require __DIR__ . '/../_header_admin.php';

$esEmpresa = ($_SESSION['usuario_rol'] ?? '') === 'empresa';

$coloresRol = [
    'empresa' => 'bg-[#111318] text-white',
    'admin'       => 'bg-[#404040] text-white',
    'recepcion'   => 'bg-[#dcfce7] text-[#15803d]',
    'socio'       => 'bg-[#f4f4f5] text-neutral-500',
];
?>

<main class="flex-1 bg-[#f7f7f8] px-5 py-8 lg:px-8">
    <section class="mx-auto max-w-6xl">
        <div class="pt-4 mb-7">
            <h1 class="text-2xl font-extrabold tracking-tight text-[#111318] sm:text-3xl">Historial de actividad</h1>
            <p class="mt-1.5 text-sm font-medium text-neutral-500">
                Quién hizo cada cambio, sobre quién y qué valor se modificó.
            </p>
        </div>

        <!-- Filtros -->
        <form method="GET" class="mb-6 flex flex-wrap items-end gap-3">
            <input type="hidden" name="action" value="admin_log">
            <div>
                <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Empleado</label>
                <select name="autor"
                    class="rounded-xl border border-[#e4e4e7] bg-white px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
                    <option value="0">— Todos —</option>
                    <?php foreach ($autores as $autor): ?>
                    <option value="<?= (int) $autor['id_usuario'] ?>"
                        <?= (int) ($_GET['autor'] ?? 0) === (int) $autor['id_usuario'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(trim($autor['nombre'] . ' ' . $autor['apellidos']) ?: $autor['nombre_usuario'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Desde</label>
                <input type="date" name="desde" value="<?= htmlspecialchars($_GET['desde'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    class="rounded-xl border border-[#e4e4e7] bg-white px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
            </div>
            <div>
                <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Hasta</label>
                <input type="date" name="hasta" value="<?= htmlspecialchars($_GET['hasta'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    class="rounded-xl border border-[#e4e4e7] bg-white px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
            </div>
            <div class="flex-1 min-w-[180px]">
                <label class="block text-xs font-extrabold uppercase tracking-widest text-neutral-500 mb-1.5">Buscar</label>
                <input type="text" name="buscar" value="<?= htmlspecialchars($_GET['buscar'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Acción, detalle o nombre..."
                    class="w-full rounded-xl border border-[#e4e4e7] bg-white px-4 py-2.5 text-sm font-medium text-neutral-700 focus:outline-none focus:ring-2 focus:ring-[#4f46e5] transition">
            </div>
            <button type="submit"
                class="rounded-full bg-[#111318] px-5 py-2.5 text-sm font-bold text-white hover:brightness-125 transition">
                Filtrar
            </button>
            <a href="index.php?action=admin_log"
                class="rounded-full border border-[#e4e4e7] bg-white px-5 py-2.5 text-sm font-bold text-neutral-500 hover:bg-neutral-50 transition">
                Limpiar
            </a>
        </form>

        <div class="rounded-[22px] border border-[#e4e4e7] bg-white shadow-sm overflow-hidden">
            <?php if (empty($logs)): ?>
                <p class="p-6 text-sm text-neutral-500 italic">No hay actividad que coincida con el filtro.</p>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm min-w-[960px]">
                    <thead class="border-b border-[#e4e4e7] bg-[#f4f4f5]">
                        <tr>
                            <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Fecha</th>
                            <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Quién</th>
                            <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Acción</th>
                            <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Sobre</th>
                            <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Cambio</th>
                            <?php if ($esEmpresa): ?>
                            <th class="px-5 py-3 text-[11px] font-extrabold uppercase tracking-widest text-neutral-500">Sede</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log):
                            $rol   = $log['autor_rol'] ?? '';
                            $clase = $coloresRol[$rol] ?? 'bg-[#f4f4f5] text-neutral-500';
                            $autor = trim(($log['autor_nombre'] ?? '') . ' ' . ($log['autor_apellidos'] ?? ''));
                            $sobre = trim(($log['afectado_nombre'] ?? '') . ' ' . ($log['afectado_apellidos'] ?? ''));
                        ?>
                        <tr class="border-t border-[#e4e4e7] hover:bg-neutral-50 transition-colors align-top">
                            <td class="px-5 py-3 text-neutral-500 text-xs whitespace-nowrap">
                                <?= date('d/m/Y', strtotime($log['fecha'])) ?>
                                <span class="block"><?= date('H:i', strtotime($log['fecha'])) ?></span>
                            </td>
                            <td class="px-5 py-3">
                                <?php if ($autor !== ''): ?>
                                    <span class="block font-bold text-neutral-700"><?= htmlspecialchars($autor, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="mt-1 inline-block rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide <?= $clase ?>">
                                        <?= htmlspecialchars($rol ?: '—', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-neutral-500 italic">Sistema</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3">
                                <span class="block font-semibold text-neutral-700">
                                    <?= htmlspecialchars($log['accion'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php if (!empty($log['detalle'])): ?>
                                <span class="block text-xs text-neutral-500">
                                    <?= htmlspecialchars($log['detalle'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3">
                                <?php if ($sobre !== ''): ?>
                                    <span class="font-medium text-neutral-700"><?= htmlspecialchars($sobre, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php elseif (!empty($log['entidad'])): ?>
                                    <span class="text-neutral-500">
                                        <?= htmlspecialchars($log['entidad'], ENT_QUOTES, 'UTF-8') ?>
                                        <?= !empty($log['id_entidad']) ? '#' . (int) $log['id_entidad'] : '' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-neutral-300">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3 text-xs">
                                <?php if ($log['valor_anterior'] !== null || $log['valor_nuevo'] !== null): ?>
                                    <span class="text-neutral-500 line-through">
                                        <?= htmlspecialchars($log['valor_anterior'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <span class="mx-1 text-neutral-500">→</span>
                                    <span class="font-bold text-[#111318]">
                                        <?= htmlspecialchars($log['valor_nuevo'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-neutral-300">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($esEmpresa): ?>
                            <td class="px-5 py-3 text-xs text-neutral-500">
                                <?= htmlspecialchars($log['gimnasio_nombre'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <p class="mt-4 text-xs text-neutral-500">
            Se muestran las 200 entradas más recientes. El historial no se puede editar desde el panel.
        </p>
    </section>
</main>

</div>
<?php require __DIR__ . '/../_footer.php'; ?>
