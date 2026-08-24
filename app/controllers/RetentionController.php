<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AppLogger.php';
require_once __DIR__ . '/../helpers/Authorization.php';
require_once __DIR__ . '/../helpers/Csrf.php';
require_once __DIR__ . '/../helpers/RequestContext.php';
require_once __DIR__ . '/../helpers/Sesion.php';
require_once __DIR__ . '/../helpers/TenantContext.php';
require_once __DIR__ . '/../services/RetentionService.php';

final class RetentionController
{
    private TenantContext $tenant;

    public function __construct()
    {
        Sesion::iniciar();
        $this->tenant = TenantContext::desdeSesion();
    }

    public function index(): void
    {
        $this->requirePermission('retention.view');
        $service = $this->service();
        $detections = $service->inbox();
        $metrics = $service->metrics();
        $pageTitle = 'Atención a socios';
        $paginaActiva = 'retention';
        require __DIR__ . '/../views/admin/retention.php';
    }

    public function act(): void
    {
        $this->requirePermission('retention.review');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            http_response_code(403);
            exit('Solicitud no válida.');
        }
        try {
            $this->service()->act(
                (int)($_POST['detection_id'] ?? 0),
                $this->tenant->usuarioId(),
                (string)($_POST['retention_action'] ?? ''),
                (string)($_POST['idempotency_key'] ?? ''),
                (int)($_POST['version'] ?? 0),
                isset($_POST['reason']) ? (string)$_POST['reason'] : null,
                (int)($_POST['postpone_days'] ?? 7)
            );
            $this->redirect(['ok'=>'Acción registrada. No se ha enviado ningún mensaje.']);
        } catch (DomainException|InvalidArgumentException $error) {
            $this->redirect(['err'=>$error->getMessage()]);
        } catch (Throwable $error) {
            AppLogger::error('retention_action_failed', ['company_id'=>$this->tenant->empresaId()]);
            $this->redirect(['err'=>'No se pudo registrar la acción. Inténtalo de nuevo.']);
        }
    }

    private function service(): RetentionService
    {
        return new RetentionService(
            Database::getInstance()->getConnection(),
            (int)$this->tenant->empresaId(),
            $this->tenant->sedeId()
        );
    }

    private function requirePermission(string $permission): void
    {
        if (!$this->tenant->autenticado()) {
            header('Location: ' . APP_URL . '/index.php?action=login');
            exit;
        }
        if ($this->tenant->empresaId() === null || !Authorization::can($this->tenant->rol(), $permission)) {
            AppLogger::write('SECURITY', 'authorization_denied', [
                'user_id'=>$this->tenant->usuarioId(), 'role'=>$this->tenant->rol(),
                'company_id'=>$this->tenant->empresaId(), 'site_id'=>$this->tenant->sedeId(),
                'permission'=>$permission,
            ]);
            http_response_code(403);
            exit('No tienes permiso para realizar esta operación.');
        }
    }

    private function redirect(array $params = []): never
    {
        $url = APP_URL . '/index.php?action=retention';
        if ($params !== []) $url .= '&' . http_build_query($params);
        header('Location: ' . $url);
        exit;
    }
}
