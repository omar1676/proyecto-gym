<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/TenantOnboardingFactory.php';

$db = Database::getInstance()->getConnection();
$sessionDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gimnera_f22_branding_sessions';
if (!is_dir($sessionDirectory)) mkdir($sessionDirectory, 0700, true);
session_save_path($sessionDirectory);
session_id('f22branding' . bin2hex(random_bytes(6)));
session_start();
$payload = TenantOnboardingFactory::input('Branding XSS', [
    'company_name' => 'TEST F22 Atlas Branding',
    'commercial_name' => 'Atlas & <script>alert(1)</script>',
    'site_name' => 'Atlas <Centro>',
    'primary_color' => '#123456',
    'text_color' => '#fedcba',
]);
$service = new TenantProvisioningService($db, 6);
$result = $service->provision($payload);
$company = $db->query('SELECT * FROM empresa WHERE id_empresa=' . (int) $result['company_id'])->fetch();
$site = $db->query('SELECT * FROM gimnasio WHERE id_gimnasio=' . (int) $result['site_id'])->fetch();
check('branding de empresa se persiste sin fallback Cleto', $company['nombre_comercial'] === 'Atlas & <script>alert(1)</script>' && !str_contains(json_encode($company), 'Cleto'));
check('branding inicial se copia a primera sede', $site['color_primario'] === '#123456' && $site['color_texto'] === '#fedcba');

$_SESSION['logueado'] = true;
$_SESSION['usuario_id'] = 6;
$_SESSION['usuario_rol'] = 'superadmin';
$_SESSION['empresa_id'] = 1;
$_SESSION['gimnasio_auth_id'] = 1;
$pageTitle = 'Empresas'; $paginaActiva = 'empresas';
$companies = array_values(array_filter($service->listCompanies(), static fn(array $row): bool => (int) $row['id_empresa'] === (int) $result['company_id']));
$error = null; $success = null; $credentials = null; $old = []; $requestId = $payload['idempotency_key'];
ob_start(); require dirname(__DIR__, 2) . '/app/views/admin/empresas.php'; $html = ob_get_clean();
check('nombre hostil se escapa en la vista', str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($html, '<script>alert(1)</script>'));
check('ampersand del nombre comercial se escapa', str_contains($html, 'Atlas &amp; &lt;script&gt;'));
$platformController = file_get_contents(dirname(__DIR__, 2) . '/app/controllers/PlatformController.php');
check('credenciales de onboarding no se serializan en sesión',
    !str_contains($platformController, "['onboarding_credentials']")
    && !str_contains($platformController, '$_SESSION[\'onboarding_credentials\']'));
check('formulario transporta idempotencia opaca generada por servidor',
    str_contains($html, 'name="onboarding_request_id"')
    && str_contains($html, 'value="' . htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8') . '"'));

$sessionFile = session_save_path() . DIRECTORY_SEPARATOR . 'sess_' . session_id();
session_write_close();
@unlink($sessionFile);

finishTests();
