<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/helpers/Csrf.php';
require_once dirname(__DIR__, 2) . '/app/helpers/Menu.php';
require_once dirname(__DIR__, 2) . '/app/helpers/RequestContext.php';

$sessionDir = sys_get_temp_dir() . '/gimnera_f24_view_' . bin2hex(random_bytes(5));
mkdir($sessionDir, 0700, true);
session_save_path($sessionDir);
session_start();
$_SESSION['usuario_rol'] = 'admin';
$_SESSION['usuario_id'] = 1;
$_SESSION['usuario_nombre'] = 'admin';
$_SESSION['usuario_nombre_real'] = 'Admin Sintético';
$_SESSION['gimnasio_nombre'] = 'Tenant Sintético';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = ['action'=>'retention'];
RequestContext::resetForTests('f2400000-0000-4000-8000-000000000001', 'WEB');

$pageTitle = 'Atención a socios';
$paginaActiva = 'retention';
$metrics = [
    'total'=>1,'reviewed'=>0,'dismissed'=>0,'contacted'=>0,'returned'=>0,
    'evaluated'=>6,'insufficient'=>2,'normal'=>2,'attention'=>1,'high_attention'=>1,
];
$detections = [[
    'id_retention_detection'=>1,'version'=>1,'nombre'=>'<script>alert(1)</script>',
    'apellidos'=>'Sintético','level'=>'HIGH_ATTENTION','activity_family'=>'GENERAL',
    'sede_nombre'=>'Sede Demo','last_attendance_utc'=>'2026-08-01 10:00:00','contacted_at_utc'=>null,
    'explanation'=>'Frecuencia habitual: 4.00. Caída aproximada: 100%.',
    'suggested_message'=>'Hola, Ana. Cuando quieras volver a entrenar, aquí te esperamos.',
]];
ob_start();
require dirname(__DIR__, 2) . '/app/views/admin/retention.php';
$html = ob_get_clean();
$mainStart = strpos($html, '<main ');
$mainEnd = strpos($html, '</main>', $mainStart === false ? 0 : $mainStart);
$retentionHtml = ($mainStart !== false && $mainEnd !== false)
    ? substr($html, $mainStart, $mainEnd - $mainStart + strlen('</main>'))
    : $html;

check('pantalla usa título prudente y no predice baja', str_contains($retentionHtml, 'podrían necesitar atención') && !str_contains($retentionHtml, 'se va a dar de baja'));
check('preview declara explícitamente que no envía', str_contains(mb_strtolower($retentionHtml), 'previsualización — no enviado'));
check('no ofrece WhatsApp SMS email ni botón enviar', !preg_match('/whatsapp|sms|correo|>\s*enviar\s*</iu', $retentionHtml));
check('bandeja no muestra DNI IBAN ni datos económicos', !preg_match('/\bDNI\b|\bIBAN\b|cuota|impago|deuda/iu', $retentionHtml));
check('nombre hostil queda escapado', !str_contains($retentionHtml, '<script>alert(1)</script>') && str_contains($retentionHtml, '&lt;script&gt;'));
check('acciones son POST y llevan CSRF', substr_count($retentionHtml, 'method="POST"') === 4 && substr_count($retentionHtml, 'name="_csrf"') === 4);
check('menú admin contiene atención socios', str_contains(Menu::render('admin','retention'), 'Atención socios'));
check('menú recepción no expone Retention', !str_contains(Menu::render('recepcion',''), 'Atención socios'));
check('menú superadmin global no ofrece una ruta sin tenant', !str_contains(Menu::render('superadmin',''), 'Atención socios'));

session_write_close();
foreach (glob($sessionDir . '/*') ?: [] as $file) if (is_file($file)) unlink($file);
rmdir($sessionDir);
finishTests();
