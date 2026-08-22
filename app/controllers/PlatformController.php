<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Csrf.php';
require_once __DIR__ . '/../helpers/RequestContext.php';
require_once __DIR__ . '/../helpers/Sesion.php';
require_once __DIR__ . '/../helpers/TenantContext.php';
require_once __DIR__ . '/../helpers/AppLogger.php';
require_once __DIR__ . '/../services/TenantProvisioningService.php';

/** Área mínima de plataforma para altas de tenants. */
final class PlatformController
{
    private TenantContext $context;
    private TenantProvisioningService $service;

    public function __construct()
    {
        Sesion::iniciar();
        $this->context = TenantContext::desdeSesion();
        if (!$this->context->autenticado()) $this->redirect('login');
        if (!$this->context->esSuperadmin()) {
            http_response_code(403);
            exit('No tienes permiso para administrar empresas de la plataforma.');
        }
        try {
            $this->service = new TenantProvisioningService(
                Database::getInstance()->getConnection(),
                $this->context->usuarioId()
            );
        } catch (DomainException) {
            http_response_code(403);
            exit('No tienes permiso para administrar empresas de la plataforma.');
        }
    }

    public function list(): void
    {
        $this->renderPage(
            $this->flash('onboarding_error'),
            $this->flash('onboarding_success'),
            null,
            $this->flash('onboarding_old') ?: []
        );
    }

    public function create(): void
    {
        $this->requirePost();
        $requestId = trim((string) ($_POST['onboarding_request_id'] ?? ''));
        $input = [
            'idempotency_key' => $requestId,
            'company_name' => $_POST['company_name'] ?? '',
            'commercial_name' => $_POST['commercial_name'] ?? '',
            'company_email' => $_POST['company_email'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'site_name' => $_POST['site_name'] ?? '',
            'site_access_email' => $_POST['site_access_email'] ?? '',
            'owner_name' => $_POST['owner_name'] ?? '',
            'owner_surname' => $_POST['owner_surname'] ?? '',
            'owner_email' => $_POST['owner_email'] ?? '',
            'owner_username' => $_POST['owner_username'] ?? '',
            'primary_color' => $_POST['primary_color'] ?? '#4f46e5',
            'text_color' => $_POST['text_color'] ?? '#ffffff',
            'timezone' => $_POST['timezone'] ?? 'Europe/Madrid',
            'currency' => 'EUR',
            'categories' => $_POST['categories'] ?? '',
            'membership_types' => [],
        ];
        if (trim((string) ($_POST['membership_name'] ?? '')) !== '') {
            $input['membership_types'][] = [
                'name' => $_POST['membership_name'] ?? '',
                'description' => $_POST['membership_description'] ?? '',
                'price' => $_POST['membership_price'] ?? '',
                'duration_months' => $_POST['membership_duration'] ?? '',
                'vat' => $_POST['membership_vat'] ?? '21.00',
            ];
        }
        try {
            $result = $this->service->provision($input);
            if ($result['created']) {
                $credentials = [
                    'site_access_email' => $result['site_access_email'],
                    'site_temporary_password' => $result['site_temporary_password'],
                    'owner_username' => $result['owner_username'],
                    'owner_temporary_password' => $result['owner_temporary_password'],
                ];
                // Las claves viven solo durante esta respuesta no cacheable:
                // nunca se serializan en la sesión, logs ni almacenamiento.
                $this->renderPage(
                    null,
                    'Empresa creada y preparada para revisión. Las credenciales se muestran una sola vez.',
                    $credentials,
                    []
                );
                return;
            } else {
                $_SESSION['onboarding_success'] = 'La solicitud ya estaba procesada; no se duplicó ninguna entidad. Rota credenciales si no conservas las originales.';
            }
        } catch (Throwable $e) {
            $_SESSION['onboarding_error'] = $this->safeError($e);
            $_SESSION['onboarding_old'] = array_diff_key($input, ['membership_types' => true]);
        }
        $this->redirect('admin_empresas');
    }

    private function renderPage(?string $error, ?string $success, ?array $credentials, array $old): void
    {
        header('Cache-Control: no-store, private');
        $pageTitle = 'Empresas de la plataforma';
        $paginaActiva = 'empresas';
        $companies = $this->service->listCompanies();
        $requestId = RequestContext::newId();
        require __DIR__ . '/../views/admin/empresas.php';
    }

    public function activate(): void
    {
        $this->requirePost();
        $companyId = filter_var($_POST['company_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($companyId === false) {
            $_SESSION['onboarding_error'] = 'La empresa indicada no es válida.';
            $this->redirect('admin_empresas');
        }
        try {
            $result = $this->service->activate((int) $companyId);
            $_SESSION['onboarding_success'] = $result['already_active']
                ? 'La empresa ya estaba activa; no se repitió la operación.'
                : 'Empresa activada. Ya puede iniciar sesión con sus credenciales temporales.';
        } catch (Throwable $e) {
            $_SESSION['onboarding_error'] = $this->safeError($e);
        }
        $this->redirect('admin_empresas');
    }

    private function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !Csrf::validarPost()) {
            http_response_code(403);
            exit('Solicitud no válida o caducada.');
        }
    }

    private function flash(string $key): mixed
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $value;
    }

    private function safeError(Throwable $error): string
    {
        if ($error instanceof DomainException || $error instanceof InvalidArgumentException) {
            return $error->getMessage();
        }
        AppLogger::error('tenant_onboarding_ui_failed', ['error_type' => get_class($error)]);
        return 'No se pudo completar la operación. No se guardó ningún cambio parcial.';
    }

    private function redirect(string $action): never
    {
        header('Location: ' . APP_URL . '/index.php?action=' . $action);
        exit;
    }
}
