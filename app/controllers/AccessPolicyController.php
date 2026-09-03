<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Sesion.php';
require_once __DIR__ . '/../helpers/TenantContext.php';
require_once __DIR__ . '/../helpers/Authorization.php';
require_once __DIR__ . '/../helpers/Csrf.php';
require_once __DIR__ . '/../helpers/AppLogger.php';
require_once __DIR__ . '/../helpers/AccessTime.php';
require_once __DIR__ . '/../services/AccessPolicyService.php';

final class AccessPolicyController
{
    private PDO $db;
    private TenantContext $tenant;

    public function __construct()
    {
        Sesion::iniciar();
        $this->db = Database::getInstance()->getConnection();
        $this->tenant = TenantContext::desdeSesion();
    }

    public function index(): void
    {
        $this->requirePermission('access.audit');
        $service = $this->service();
        $pageTitle = 'Control de acceso';
        $paginaActiva = 'access';
        $metrics = $service->dashboard();
        $policies = $service->listPolicies(null, 150);
        require __DIR__ . '/../views/admin/access_dashboard.php';
    }

    public function change(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validarPost()) {
            $this->fail('Solicitud no válida. Vuelve a intentarlo.', 0);
        }
        $memberId = filter_var($_POST['id_socio'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $version = filter_var($_POST['policy_version'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        $operation = strtolower(trim((string)($_POST['operacion'] ?? '')));
        $key = strtolower(trim((string)($_POST['_operation_id'] ?? '')));
        if ($memberId === false || $version === false || !preg_match('/^[a-f0-9]{32}$/', $key)) {
            $this->fail('La operación de acceso no es válida.', (int)($memberId ?: 0));
        }
        $reason = strtoupper(trim((string)($_POST['reason_code'] ?? '')));
        $note = trim((string)($_POST['reason_note'] ?? '')) ?: null;
        try {
            $service = $this->service();
            if (in_array($operation, ['temporary','extend'], true)) {
                $starts = $this->localDateTime((string)($_POST['starts_at'] ?? ''))
                    ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $expires = $this->localDateTime((string)($_POST['expires_at'] ?? ''));
                if ($expires === null) throw new DomainException('Indica la fecha y hora de caducidad.');
                if ($operation === 'extend') {
                    $service->extendTemporary((int)$memberId, $expires, $reason, $note, $key, (int)$version);
                } else {
                    $service->grantTemporary((int)$memberId, $starts, $expires, $reason, $note, $key, (int)$version);
                }
            } elseif ($operation === 'suspend') {
                $until = $this->localDateTime((string)($_POST['suspended_until'] ?? ''));
                $service->suspend((int)$memberId, $until, $reason, $note, $key, (int)$version);
            } elseif ($operation === 'deny') {
                $service->deny((int)$memberId, $reason, $note, $key, (int)$version);
            } elseif ($operation === 'permanent') {
                $service->blockPermanently((int)$memberId, $reason, $note, $key, (int)$version);
            } elseif ($operation === 'restore') {
                $service->restore((int)$memberId, $reason, $note, $key, (int)$version);
            } else {
                throw new DomainException('Operación de acceso desconocida.');
            }
            $this->redirect((int)$memberId, ['ok_access'=>1]);
        } catch (InvalidArgumentException|DomainException $error) {
            $this->fail($error->getMessage(), (int)$memberId);
        } catch (Throwable $error) {
            AppLogger::error('access_policy_change_failed', [
                'company_id'=>$this->tenant->empresaId(), 'site_id'=>$this->tenant->sedeId(),
                'member_id'=>(int)$memberId, 'error_class'=>get_class($error),
            ]);
            $this->fail('No se pudo guardar el cambio de acceso.', (int)$memberId);
        }
    }

    private function service(): AccessPolicyService
    {
        $this->requirePermission('access.view');
        $stmt=$this->db->prepare('SELECT configuracion FROM empresa WHERE id_empresa=:empresa LIMIT 1');
        $stmt->execute([':empresa'=>$this->tenant->empresaId()]);
        $config=json_decode((string)$stmt->fetchColumn(),true);
        $max=(int)($config['access_policy']['recepcion_max_temporary_days'] ?? 3);
        return new AccessPolicyService(
            $this->db, (int)$this->tenant->empresaId(), $this->tenant->sedeId(),
            $this->tenant->usuarioId(), $this->tenant->rol(), null, null, $max
        );
    }

    private function localDateTime(string $value): ?DateTimeImmutable
    {
        return AccessTime::parseLocal($value, 'Europe/Madrid');
    }

    private function requirePermission(string $permission): void
    {
        if (!$this->tenant->autenticado() || $this->tenant->empresaId() === null
            || !Authorization::can($this->tenant->rol(), $permission)) {
            http_response_code(403);
            exit('No tienes permiso para realizar esta operación.');
        }
    }

    private function fail(string $message, int $memberId): never
    {
        $this->redirect($memberId, ['err'=>$message]);
    }

    private function redirect(int $memberId, array $params): never
    {
        $query=['action'=>'admin_socios']+$params;
        if ($memberId>0) $query['detalle']=$memberId;
        header('Location: '.APP_URL.'/index.php?'.http_build_query($query));
        exit;
    }
}
