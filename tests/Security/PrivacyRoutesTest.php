<?php

require_once dirname(__DIR__) . '/bootstrap.php';

$root = dirname(__DIR__, 2);
$views = [
    'app/views/_header.php', 'app/views/_header_admin.php',
    'app/views/auth/perfil.php', 'app/views/admin/socios.php',
];
foreach ($views as $view) {
    $source = (string) file_get_contents($root . '/' . $view);
    check($view . ' no publica assets/fotos', !str_contains($source, 'assets/fotos/'));
    check($view . ' usa endpoint privado', str_contains($source, 'action=media_foto'));
}
$controller = (string) file_get_contents($root . '/app/controllers/MediaController.php');
check('endpoint exige sesión y TenantContext', str_contains($controller, 'TenantContext::desdeSesion()') && str_contains($controller, 'autenticado()'));
check('endpoint resuelve usuario mediante modelo con tenant/sede', str_contains($controller, 'new UserModel($context->sedeId(), $context->empresaId())'));
check('respuesta de foto es privada y nosniff', str_contains($controller, 'private, no-store') && str_contains($controller, 'nosniff'));
check('almacén público de fotos ya no forma parte del árbol runtime', !is_file($root . '/public/assets/fotos/.htaccess'));

finishTests();
