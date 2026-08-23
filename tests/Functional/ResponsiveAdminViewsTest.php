<?php

require_once dirname(__DIR__) . '/bootstrap.php';

$root = dirname(__DIR__, 2);
$views = glob($root . '/app/views/admin/*.php') ?: [];
$tables = 0;
$unwrappedTables = [];
$dialogs = 0;
$inaccessibleDialogs = [];

foreach ($views as $view) {
    $html = (string) file_get_contents($view);
    $offset = 0;
    while (($position = strpos($html, '<table', $offset)) !== false) {
        $tables++;
        $prefix = substr($html, max(0, $position - 500), min(500, $position));
        if (!str_contains($prefix, 'overflow-x-auto')) {
            $unwrappedTables[] = basename($view) . ':' . (substr_count(substr($html, 0, $position), "\n") + 1);
        }
        $offset = $position + 6;
    }

    if (preg_match_all('/<div\s+id="(modal-[^"]+)"([^>]*)>/i', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $dialogs++;
            $attributes = $match[2];
            if (!str_contains($attributes, 'role="dialog"')
                || !str_contains($attributes, 'aria-modal="true"')
                || !str_contains($attributes, 'aria-labelledby=')) {
                $inaccessibleDialogs[] = basename($view) . ':' . $match[1];
            }
        }
    }
}

$header = (string) file_get_contents($root . '/app/views/_header_admin.php');
$css = (string) file_get_contents($root . '/public/assets/css/style.css');

check('las tablas anchas tienen desplazamiento local', $tables >= 17 && $unwrappedTables === []);
check('todos los modales administrativos declaran diálogo accesible', $dialogs >= 9 && $inaccessibleDialogs === []);
check('layout compartido permite encoger cualquier main', str_contains($header, 'admin-layout flex flex-1 min-w-0 w-full')
    && str_contains($css, '.admin-layout > main') && str_contains($css, 'min-width: 0'));
check('modales pasan a una columna y acciones verticales en móvil', str_contains($css, '[role="dialog"] .grid.grid-cols-2')
    && str_contains($css, 'grid-template-columns: minmax(0, 1fr)')
    && str_contains($css, 'flex-direction: column'));
check('cabecera restringe logo y selector en móvil', str_contains($css, '.admin-header .js-logo-admin')
    && str_contains($css, 'max-width: 105px'));

finishTests();
