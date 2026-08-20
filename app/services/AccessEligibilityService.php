<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/SocioFinancialService.php';

/**
 * Decide únicamente el estado lógico interno. No contiene ni llama código de
 * puertas, controladoras, huellas o cualquier otro hardware.
 */
final class AccessEligibilityService
{
    public const PERMITIDO = 'PERMITIDO';
    public const BLOQUEADO = 'BLOQUEADO';
    public const REVISAR = 'REVISAR';

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
        if (!$estado) return $this->resultado(self::BLOQUEADO, 'Socio inexistente o fuera del ámbito autorizado.');
        if ((int) $estado['socio']['activo'] !== 1) {
            return $this->resultado(self::BLOQUEADO, 'Socio dado de baja o suspendido.');
        }

        $m = $estado['membresia'];
        if (!$m) return $this->resultado(self::BLOQUEADO, 'No existe una membresía vigente.');
        $politica = $this->politica();
        if ((int) $m['es_prueba'] === 1) {
            return !empty($politica['permitir_pruebas'])
                ? $this->resultado(self::PERMITIDO, 'Periodo de prueba vigente.')
                : $this->resultado(self::REVISAR, 'Periodo de prueba vigente; política pendiente de confirmación.');
        }

        if ($estado['deuda_cents'] > 0 || $estado['recibos_devueltos'] > 0) {
            $bloquear = !empty($politica['bloquear_impagos']);
            $gracia = max(0, (int) ($politica['dias_gracia_impago'] ?? 0));
            if ($bloquear && $this->superaGracia($idSocio, $gracia)) {
                return $this->resultado(self::BLOQUEADO, 'Impago fuera del periodo de gracia definido por la empresa.');
            }
            return $this->resultado(
                self::REVISAR,
                $estado['recibos_devueltos'] > 0
                    ? 'Recibo devuelto; la política de Cleto debe decidir el margen antes de bloquear.'
                    : 'Existe deuda pendiente; la política de Cleto debe decidir el margen antes de bloquear.'
            );
        }
        return $this->resultado(self::PERMITIDO, 'Membresía vigente y sin incidencias económicas conocidas.');
    }

    /** Variante sin consultas por socio para listados ya paginados. */
    public function evaluarResumen(array $socio, array $economico): array
    {
        if ((int) ($socio['activo'] ?? 1) !== 1) {
            return $this->resultado(self::BLOQUEADO, 'Socio dado de baja o suspendido.');
        }
        $estadoMembresia = (string) ($socio['estado_membresia'] ?? 'sin_membresia');
        if (!in_array($estadoMembresia, ['activa', 'prueba'], true)) {
            return $this->resultado(self::BLOQUEADO, 'No existe una membresía vigente.');
        }
        if ($estadoMembresia === 'prueba') {
            return !empty($this->politica()['permitir_pruebas'])
                ? $this->resultado(self::PERMITIDO, 'Periodo de prueba vigente.')
                : $this->resultado(self::REVISAR, 'Periodo de prueba; política pendiente de confirmación.');
        }
        if ((int) ($economico['deuda_cents'] ?? 0) > 0 || (int) ($economico['devueltos'] ?? 0) > 0) {
            return $this->resultado(
                self::REVISAR,
                (int) ($economico['devueltos'] ?? 0) > 0
                    ? 'Recibo devuelto; pendiente de política comercial de Cleto.'
                    : 'Deuda pendiente; pendiente de política comercial de Cleto.'
            );
        }
        return $this->resultado(self::PERMITIDO, 'Membresía vigente y sin incidencias económicas conocidas.');
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

    private function resultado(string $estado, string $motivo): array
    {
        return ['estado' => $estado, 'motivo' => $motivo, 'solo_logico' => true];
    }
}
