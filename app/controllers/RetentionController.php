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
        $service = $this->service(true);
        $detections = $service->inbox(12);
        $metrics = $service->metrics();
        $returned = $service->cases(['state'=>'returned'],1,6)['items'];
        $recentVisits = $service->recentVisits(10);
        $search = $service->search((string)($_GET['q']??''),$this->page('search_page'),12);
        $sites = $this->tenant->rol()==='direccion' ? $service->sites() : [];
        $selectedSite = $this->selectedSite();
        $pageTitle = 'Retención';
        $paginaActiva = 'retention';
        require __DIR__ . '/../views/admin/retention.php';
    }

    public function cases(): void
    {
        $this->requirePermission('retention.view');
        $service=$this->service(true);
        $filters=[
            'state'=>(string)($_GET['state']??'attention'),
            'activity'=>(string)($_GET['activity']??''),
            'workflow'=>(string)($_GET['workflow']??''),
        ];
        $result=$service->cases($filters,$this->page('page'),20);
        $cases=$result['items'];
        $pagination=$result['pagination'];
        $sites=$this->tenant->rol()==='direccion'?$service->sites():[];
        $selectedSite=$this->selectedSite();
        $pageTitle='Todos los estados de retención';
        $paginaActiva='retention';
        require __DIR__ . '/../views/admin/retention_cases.php';
    }

    public function history(): void
    {
        $this->requirePermission('retention.view');
        $service=$this->service(true);
        $historyFilters=[
            'from'=>(string)($_GET['from']??''),'to'=>(string)($_GET['to']??''),
            'activity'=>(string)($_GET['activity']??''),'member'=>(string)($_GET['member']??''),
        ];
        $filterError=null;
        try {
            $result=$service->attendanceHistory($historyFilters,$this->page('page'),20);
        } catch (InvalidArgumentException $error) {
            $filterError=$error->getMessage();
            $result=$service->attendanceHistory([],1,20);
        }
        $visits=$result['items'];
        $pagination=$result['pagination'];
        $filters=$result['filters'];
        $sites=$this->tenant->rol()==='direccion'?$service->sites():[];
        $selectedSite=$this->selectedSite();
        $pageTitle='Historial de asistencia';
        $paginaActiva='retention';
        require __DIR__ . '/../views/admin/retention_history.php';
    }

    public function act(): void
    {
        $this->requirePermission('retention.review');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            http_response_code(403);
            exit('Solicitud no válida.');
        }
        try {
            $this->service(false)->act(
                (int)($_POST['detection_id'] ?? 0),
                $this->tenant->usuarioId(),
                (string)($_POST['retention_action'] ?? ''),
                (string)($_POST['idempotency_key'] ?? ''),
                (int)($_POST['version'] ?? 0),
                isset($_POST['reason']) ? (string)$_POST['reason'] : null,
                (int)($_POST['postpone_days'] ?? 7)
            );
            $this->redirect(['ok'=>'Acción registrada. No se ha enviado ningún mensaje.'],(string)($_POST['return_to']??'retention'));
        } catch (DomainException|InvalidArgumentException $error) {
            $this->redirect(['err'=>$error->getMessage()],(string)($_POST['return_to']??'retention'));
        } catch (Throwable $error) {
            AppLogger::error('retention_action_failed', ['company_id'=>$this->tenant->empresaId()]);
            $this->redirect(['err'=>'No se pudo registrar la acción. Inténtalo de nuevo.'],(string)($_POST['return_to']??'retention'));
        }
    }

    private function service(bool $allowDirectionFilter): RetentionService
    {
        $site=$this->tenant->sedeId();
        if($allowDirectionFilter&&$this->tenant->rol()==='direccion'&&$site===null){
            $site=$this->selectedSite();
        }
        try {
            return new RetentionService(
                Database::getInstance()->getConnection(),
                (int)$this->tenant->empresaId(),
                $site
            );
        } catch (DomainException $error) {
            AppLogger::write('SECURITY','retention_site_scope_denied',[
                'user_id'=>$this->tenant->usuarioId(),'company_id'=>$this->tenant->empresaId(),
                'session_site_id'=>$this->tenant->sedeId(),'requested_site_id'=>$site,
            ]);
            http_response_code(403);
            exit('No tienes permiso para consultar esa sede.');
        }
    }

    private function selectedSite(): ?int
    {
        if($this->tenant->rol()!=='direccion') return $this->tenant->sedeId();
        if($this->tenant->sedeId()!==null) return $this->tenant->sedeId();
        $raw=(string)($_GET['site']??'');
        if($raw===''||$raw==='0') return $this->tenant->sedeId();
        if(!ctype_digit($raw)||(int)$raw<=0) return $this->tenant->sedeId();
        return (int)$raw;
    }

    private function page(string $name): int
    {
        $raw=(string)($_GET[$name]??'1');
        return ctype_digit($raw)?max(1,min(10000,(int)$raw)):1;
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

    private function redirect(array $params = [],string $action='retention'): never
    {
        if(!in_array($action,['retention','retention_cases'],true)) $action='retention';
        $url = APP_URL . '/index.php?action='.$action;
        if ($params !== []) $url .= '&' . http_build_query($params);
        header('Location: ' . $url);
        exit;
    }
}
