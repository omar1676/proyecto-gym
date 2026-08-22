<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/Money.php';
require_once dirname(__DIR__) . '/helpers/TenantLifecyclePolicy.php';

/**
 * Registra el reflejo operativo de ventas y cobros en la caja de una sede.
 *
 * Puede ejecutarse dentro de la transacción de venta/SEPA/membresía. Si hay
 * una caja abierta, el movimiento se asocia a ella; si no, se conserva con
 * sesión NULL para no perder trazabilidad ni romper flujos históricos.
 */
final class CashMovementRecorder
{
    private PDO $db;
    private int $empresaId;
    private int $sedeId;

    public function __construct(int $empresaId, int $sedeId, ?PDO $db = null)
    {
        if ($empresaId <= 0 || $sedeId <= 0) {
            throw new InvalidArgumentException('Empresa y sede son obligatorias para un movimiento de caja.');
        }
        $this->db = $db ?: Database::getInstance()->getConnection();
        $this->empresaId = $empresaId;
        $this->sedeId = $sedeId;
    }

    public static function normalizarMetodo(string $metodo): string
    {
        return match ($metodo) {
            'datafono', 'tarjeta' => 'tarjeta',
            'transferencia' => 'transferencia',
            'domiciliacion' => 'domiciliacion',
            'efectivo' => 'efectivo',
            default => 'otro',
        };
    }

    public function registrar(
        string $tipo,
        string $metodo,
        int $importeCents,
        ?int $idVenta,
        ?int $idCobro,
        ?int $idUsuario,
        string $concepto,
        ?string $motivo,
        string $idempotencyKey
    ): int {
        $tenantLifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->empresaId);
        if ($importeCents === 0) {
            throw new InvalidArgumentException('Un movimiento de caja no puede tener importe cero.');
        }
        if (!in_array($tipo, ['venta', 'anulacion_venta', 'cobro', 'devolucion', 'ajuste_entrada', 'ajuste_salida'], true)) {
            throw new InvalidArgumentException('Tipo de movimiento de caja no válido.');
        }
        $metodo = self::normalizarMetodo($metodo);
        $idempotencyKey = mb_substr(trim($idempotencyKey), 0, 100);
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('La idempotencia es obligatoria en caja.');
        }

        $existente = $this->db->prepare(
            'SELECT id_movimiento_caja FROM caja_movimiento
             WHERE id_empresa = :empresa AND idempotency_key = :clave LIMIT 1'
        );
        $existente->execute([':empresa' => $this->empresaId, ':clave' => $idempotencyKey]);
        $id = (int) $existente->fetchColumn();
        if ($id > 0) return $id;

        $sesion = $this->db->prepare(
            "SELECT id_sesion_caja FROM caja_sesion
             WHERE id_empresa = :empresa AND id_gimnasio = :sede AND estado = 'abierta'
             LIMIT 1 FOR UPDATE"
        );
        $sesion->execute([':empresa' => $this->empresaId, ':sede' => $this->sedeId]);
        $idSesion = (int) $sesion->fetchColumn() ?: null;

        $stmt = $this->db->prepare(
            'INSERT INTO caja_movimiento
             (id_empresa, id_gimnasio, id_sesion_caja, tipo, metodo, importe,
              afecta_efectivo, id_venta, id_cobro, id_usuario, concepto, motivo, idempotency_key)
             VALUES
             (:empresa, :sede, :sesion, :tipo, :metodo, :importe,
              :afecta, :venta, :cobro, :usuario, :concepto, :motivo, :clave)'
        );
        $stmt->execute([
            ':empresa' => $this->empresaId,
            ':sede' => $this->sedeId,
            ':sesion' => $idSesion,
            ':tipo' => $tipo,
            ':metodo' => $metodo,
            ':importe' => Money::decimal($importeCents),
            ':afecta' => $metodo === 'efectivo' ? 1 : 0,
            ':venta' => $idVenta ?: null,
            ':cobro' => $idCobro ?: null,
            ':usuario' => $idUsuario ?: null,
            ':concepto' => mb_substr($concepto, 0, 190),
            ':motivo' => $motivo !== null ? mb_substr($motivo, 0, 255) : null,
            ':clave' => $idempotencyKey,
        ]);
        return (int) $this->db->lastInsertId();
    }
}
