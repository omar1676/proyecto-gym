<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/SocioFinancialService.php';
require_once __DIR__ . '/AccessDecision.php';

/**
 * Decide únicamente el estado lógico interno. No contiene ni llama código de
 * puertas, controladoras, huellas o cualquier otro hardware.
 */
final class AccessEligibilityService
{
    public const PERMITIDO = AccessDecision::PERMITIDO;
    public const BLOQUEADO = AccessDecision::BLOQUEADO;
    public const REVISAR = AccessDecision::REVISAR;

    private PDO $db;
    private int $empresaId;
    private ?int $sedeId;
    private SocioFinancialService $finanzas;

    public function __construct(int $empresaId, ?int $sedeId = null)
    {
        $this->db = Database::getInstance()->getConnection();
        $this->empresaId = $empresaId;
        $this->sedeId = $sedeId;
        $this->finanzas = new SocioFinancialService($empresaId, $sedeId);
    }

    public function evaluar(int $idSocio): array
    {
        $estado = $this->finanzas->estado($idSocio);
        if (!$estado) {
            return $this->resultado(
                self::BLOQUEADO,
                'MEMBER_NOT_FOUND_OR_OUT_OF_SCOPE',
                'Socio inexistente o fuera del ámbito autorizado.',
                $idSocio,
                $this->sedeId,
                ['member' => 'missing']
            );
        }
        $sedeSocio = (int) ($estado['socio']['id_gimnasio'] ?? 0) ?: $this->sedeId;
        $version = $this->versionMaterial($estado);
        if ((int) $estado['socio']['activo'] !== 1) {
            return $this->resultado(self::BLOQUEADO, 'MEMBER_INACTIVE', 'Socio dado de baja o suspendido.', $idSocio, $sedeSocio, $version);
        }

        $m = $estado['membresia'];
        if (!$m) {
            return $this->resultado(self::BLOQUEADO, 'NO_ACTIVE_MEMBERSHIP', 'No existe una membresía vigente.', $idSocio, $sedeSocio, $version);
        }
        $politica = $this->politica();
        if ((int) $m['es_prueba'] === 1) {
            return !empty($politica['permitir_pruebas'])
                ? $this->resultado(self::PERMITIDO, 'TRIAL_ACTIVE', 'Periodo de prueba vigente.', $idSocio, $sedeSocio, $version)
                : $this->resultado(self::REVISAR, 'TRIAL_POLICY_REVIEW', 'Periodo de prueba vigente; política pendiente de confirmación.', $idSocio, $sedeSocio, $version);
        }

        if ($estado['deuda_cents'] > 0 || $estado['recibos_devueltos'] > 0) {
            $bloquear = !empty($politica['bloquear_impagos']);
            $gracia = max(0, (int) ($politica['dias_gracia_impago'] ?? 0));
            if ($bloquear && $this->superaGracia($idSocio, $gracia)) {
                return $this->resultado(
                    self::BLOQUEADO,
                    $estado['recibos_devueltos'] > 0 ? 'RETURNED_PAYMENT' : 'PAYMENT_GRACE_EXCEEDED',
                    'Impago fuera del periodo de gracia definido por la empresa.',
                    $idSocio,
                    $sedeSocio,
                    $version
                );
            }
            return $this->resultado(
                self::REVISAR,
                $estado['recibos_devueltos'] > 0 ? 'RETURNED_PAYMENT' : 'PAYMENT_REVIEW',
                $estado['recibos_devueltos'] > 0
                    ? 'Recibo devuelto; la política de Cleto debe decidir el margen antes de bloquear.'
                    : 'Existe deuda pendiente; la política de Cleto debe decidir el margen antes de bloquear.',
                $idSocio,
                $sedeSocio,
                $version
            );
        }
        return $this->resultado(self::PERMITIDO, 'MEMBERSHIP_ACTIVE', 'Membresía vigente y sin incidencias económicas conocidas.', $idSocio, $sedeSocio, $version);
    }

    /** Decisión formal utilizable por la frontera de integración. */
    public function decidir(int $idSocio): AccessDecision
    {
        $resultado = $this->evaluar($idSocio);
        $sede = (int) ($resultado['sede_id'] ?? 0);
        if ($sede <= 0) {
            throw new DomainException('La sincronización de acceso exige una sede concreta y autorizada.');
        }
        return new AccessDecision(
            $this->empresaId,
            $sede,
            $idSocio,
            $resultado['estado'],
            $resultado['reason_code'],
            null,
            null,
            $resultado['decision_version']
        );
    }

    /** Variante sin consultas por socio para listados ya paginados. */
    public function evaluarResumen(array $socio, array $economico): array
    {
        $idSocio = (int) ($socio['id_usuario'] ?? 0);
        $sede = (int) ($socio['id_gimnasio'] ?? 0) ?: $this->sedeId;
        $version = [$socio, $economico];
        if ((int) ($socio['activo'] ?? 1) !== 1) {
            return $this->resultado(self::BLOQUEADO, 'MEMBER_INACTIVE', 'Socio dado de baja o suspendido.', $idSocio, $sede, $version);
        }
        $estadoMembresia = (string) ($socio['estado_membresia'] ?? 'sin_membresia');
        if (!in_array($estadoMembresia, ['activa', 'prueba'], true)) {
            return $this->resultado(self::BLOQUEADO, 'NO_ACTIVE_MEMBERSHIP', 'No existe una membresía vigente.', $idSocio, $sede, $version);
        }
        if ($estadoMembresia === 'prueba') {
            return !empty($this->politica()['permitir_pruebas'])
                ? $this->resultado(self::PERMITIDO, 'TRIAL_ACTIVE', 'Periodo de prueba vigente.', $idSocio, $sede, $version)
                : $this->resultado(self::REVISAR, 'TRIAL_POLICY_REVIEW', 'Periodo de prueba; política pendiente de confirmación.', $idSocio, $sede, $version);
        }
        if ((int) ($economico['deuda_cents'] ?? 0) > 0 || (int) ($economico['devueltos'] ?? 0) > 0) {
            return $this->resultado(
                self::REVISAR,
                (int) ($economico['devueltos'] ?? 0) > 0 ? 'RETURNED_PAYMENT' : 'PAYMENT_REVIEW',
                (int) ($economico['devueltos'] ?? 0) > 0
                    ? 'Recibo devuelto; pendiente de política comercial de Cleto.'
                    : 'Deuda pendiente; pendiente de política comercial de Cleto.',
                $idSocio,
                $sede,
                $version
            );
        }
        return $this->resultado(self::PERMITIDO, 'MEMBERSHIP_ACTIVE', 'Membresía vigente y sin incidencias económicas conocidas.', $idSocio, $sede, $version);
    }

    private function politica(): array
    {
        $stmt = $this->db->prepare('SELECT configuracion FROM empresa WHERE id_empresa = :empresa LIMIT 1');
        $stmt->execute([':empresa' => $this->empresaId]);
        $config = json_decode((string) $stmt->fetchColumn(), true);
        $guardada = is_array($config) && is_array($config['access_policy'] ?? null) ? $config['access_policy'] : [];
        return array_merge([
            'permitir_pruebas' => true,
            // Seguro por defecto: una deuda se revisa, no se convierte en una
            // orden de bloqueo sin una decisión comercial explícita.
            'bloquear_impagos' => false,
            'dias_gracia_impago' => 0,
        ], $guardada);
    }

    private function superaGracia(int $idSocio, int $dias): bool
    {
        $stmt = $this->db->prepare(
            "SELECT MIN(o.fecha_vencimiento)
             FROM obligacion_pago o
             LEFT JOIN (
                 SELECT id_obligacion, SUM(CASE WHEN estado = 'confirmado' THEN importe ELSE 0 END) cobrado
                 FROM cobro GROUP BY id_obligacion
             ) c ON c.id_obligacion = o.id_obligacion
             WHERE o.id_empresa = :empresa AND o.id_socio = :socio
               AND o.estado NOT IN ('cancelada','exenta')
               AND GREATEST(o.importe - COALESCE(c.cobrado, 0), 0) > 0"
        );
        $stmt->execute([':empresa' => $this->empresaId, ':socio' => $idSocio]);
        $fecha = $stmt->fetchColumn();
        return $fecha && strtotime($fecha . ' +' . $dias . ' days') < strtotime(date('Y-m-d'));
    }

    private function resultado(
        string $estado,
        string $reasonCode,
        string $motivo,
        int $idSocio,
        ?int $idSede,
        array $versionMaterial
    ): array
    {
        return [
            'estado' => $estado,
            'motivo' => $motivo,
            'reason_code' => $reasonCode,
            'empresa_id' => $this->empresaId,
            'sede_id' => $idSede,
            'socio_id' => $idSocio,
            'decision_version' => hash('sha256', (string) json_encode($versionMaterial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'solo_logico' => true,
        ];
    }

    /** Reduce el estado económico a material opaco para idempotencia. */
    private function versionMaterial(array $estado): array
    {
        return [
            'socio_activo' => (int) ($estado['socio']['activo'] ?? 0),
            'membresia_id' => (int) ($estado['membresia']['id_socio_membresia'] ?? 0),
            'membresia_fin' => (string) ($estado['membresia']['fecha_fin'] ?? ''),
            'prueba' => (int) ($estado['membresia']['es_prueba'] ?? 0),
            'deuda_cents' => (int) ($estado['deuda_cents'] ?? 0),
            'devueltos' => (int) ($estado['recibos_devueltos'] ?? 0),
        ];
    }
}
