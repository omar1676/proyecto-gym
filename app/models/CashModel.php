<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Money.php';
require_once __DIR__ . '/../helpers/TenantLifecyclePolicy.php';
require_once __DIR__ . '/../helpers/SafeException.php';
require_once __DIR__ . '/../services/CashMovementRecorder.php';

/** Caja física por sede y turno. Los importes se calculan siempre en céntimos. */
final class CashModel
{
    private PDO $db;
    private int $empresaId;
    private int $sedeId;
    private CashMovementRecorder $recorder;

    public function __construct(int $sedeId, int $empresaId)
    {
        if ($sedeId <= 0 || $empresaId <= 0) {
            throw new InvalidArgumentException('La caja exige una sede concreta.');
        }
        $this->db = Database::getInstance()->getConnection();
        $this->empresaId = $empresaId;
        $this->sedeId = $sedeId;
        $this->recorder = new CashMovementRecorder($empresaId, $sedeId, $this->db);
    }

    public function abrir($saldoInicial, int $idUsuario, string &$error): ?int
    {
        $tenantLifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->empresaId);
        try {
            $saldoCents = Money::cents($saldoInicial);
        } catch (InvalidArgumentException $e) {
            $error = 'El saldo inicial no es válido.';
            return null;
        }
        if ($saldoCents < 0 || !$this->usuarioEnEmpresa($idUsuario)) {
            $error = $saldoCents < 0 ? 'El saldo inicial no puede ser negativo.' : 'Usuario no autorizado para esta empresa.';
            return null;
        }

        try {
            $this->db->beginTransaction();
            $lock = $this->db->prepare('SELECT id_gimnasio FROM gimnasio WHERE id_gimnasio = :sede AND id_empresa = :empresa FOR UPDATE');
            $lock->execute([':sede' => $this->sedeId, ':empresa' => $this->empresaId]);
            if (!$lock->fetchColumn()) {
                $this->db->rollBack();
                $error = 'La sede no pertenece a la empresa activa.';
                return null;
            }
            $abierta = $this->db->prepare("SELECT id_sesion_caja FROM caja_sesion WHERE id_empresa = :empresa AND id_gimnasio = :sede AND estado = 'abierta' LIMIT 1 FOR UPDATE");
            $abierta->execute([':empresa' => $this->empresaId, ':sede' => $this->sedeId]);
            if ($abierta->fetchColumn()) {
                $this->db->rollBack();
                $error = 'Ya existe una caja abierta en esta sede.';
                return null;
            }
            $stmt = $this->db->prepare(
                'INSERT INTO caja_sesion (id_empresa, id_gimnasio, id_usuario_apertura, saldo_inicial)
                 VALUES (:empresa, :sede, :usuario, :saldo)'
            );
            $stmt->execute([
                ':empresa' => $this->empresaId,
                ':sede' => $this->sedeId,
                ':usuario' => $idUsuario,
                ':saldo' => Money::decimal($saldoCents),
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->db->commit();
            return $id;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            SafeException::log('cash_model_failed', $e, 'CashModel.abrir');
            $error = $e->getCode() === '23000' ? 'Ya existe una caja abierta en esta sede.' : 'No se pudo abrir la caja.';
            return null;
        }
    }

    public function abierta(): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT cs.*, u.nombre AS usuario_apertura_nombre, u.apellidos AS usuario_apertura_apellidos,
                    COALESCE(SUM(CASE WHEN cm.afecta_efectivo = 1 THEN cm.importe ELSE 0 END), 0) AS movimientos_efectivo,
                    cs.saldo_inicial + COALESCE(SUM(CASE WHEN cm.afecta_efectivo = 1 THEN cm.importe ELSE 0 END), 0) AS saldo_esperado_actual
             FROM caja_sesion cs
             LEFT JOIN usuario u ON u.id_usuario = cs.id_usuario_apertura
             LEFT JOIN caja_movimiento cm ON cm.id_sesion_caja = cs.id_sesion_caja
             WHERE cs.id_empresa = :empresa AND cs.id_gimnasio = :sede AND cs.estado = 'abierta'
             GROUP BY cs.id_sesion_caja LIMIT 1"
        );
        $stmt->execute([':empresa' => $this->empresaId, ':sede' => $this->sedeId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function movimientoManual(string $tipo, $importe, string $motivo, int $idUsuario, string &$error, ?string $operacionId = null): ?int
    {
        $tenantLifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->empresaId);
        if (!in_array($tipo, ['ajuste_entrada', 'ajuste_salida'], true)) {
            $error = 'Tipo de ajuste no válido.';
            return null;
        }
        $motivo = trim($motivo);
        if ($motivo === '') {
            $error = 'El motivo es obligatorio.';
            return null;
        }
        try {
            $importeCents = Money::cents($importe);
        } catch (InvalidArgumentException $e) {
            $error = 'El importe no es válido.';
            return null;
        }
        if ($importeCents <= 0 || !$this->usuarioEnEmpresa($idUsuario)) {
            $error = $importeCents <= 0 ? 'El importe debe ser mayor que cero.' : 'Usuario no autorizado.';
            return null;
        }
        if ($tipo === 'ajuste_salida') $importeCents *= -1;
        $clave = $operacionId && preg_match('/^[a-f0-9]{32}$/', $operacionId)
            ? 'ajuste:' . $operacionId
            : 'ajuste:' . bin2hex(random_bytes(16));

        try {
            $this->db->beginTransaction();
            if (!$this->bloquearAbierta()) {
                $this->db->rollBack();
                $error = 'No hay una caja abierta en esta sede.';
                return null;
            }
            $id = $this->recorder->registrar(
                $tipo, 'efectivo', $importeCents, null, null, $idUsuario,
                $tipo === 'ajuste_entrada' ? 'Entrada manual' : 'Salida manual',
                $motivo, $clave
            );
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            SafeException::log('cash_model_failed', $e, 'CashModel.movimientoManual');
            $error = 'No se pudo registrar el ajuste de caja.';
            return null;
        }
    }

    public function cerrar($saldoDeclarado, int $idUsuario, string $observacion, string &$error): ?array
    {
        $tenantLifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->empresaId);
        try {
            $declaradoCents = Money::cents($saldoDeclarado);
        } catch (InvalidArgumentException $e) {
            $error = 'El saldo declarado no es válido.';
            return null;
        }
        if ($declaradoCents < 0 || !$this->usuarioEnEmpresa($idUsuario)) {
            $error = $declaradoCents < 0 ? 'El saldo declarado no puede ser negativo.' : 'Usuario no autorizado.';
            return null;
        }

        try {
            $this->db->beginTransaction();
            $sesion = $this->bloquearAbierta();
            if (!$sesion) {
                $this->db->rollBack();
                $error = 'No hay una caja abierta en esta sede.';
                return null;
            }
            $mov = $this->db->prepare(
                'SELECT COALESCE(SUM(importe), 0) FROM caja_movimiento
                 WHERE id_sesion_caja = :sesion AND afecta_efectivo = 1'
            );
            $mov->execute([':sesion' => (int) $sesion['id_sesion_caja']]);
            $esperadoCents = Money::cents($sesion['saldo_inicial']) + Money::cents($mov->fetchColumn());
            $diferenciaCents = $declaradoCents - $esperadoCents;

            $stmt = $this->db->prepare(
                "UPDATE caja_sesion SET estado = 'cerrada', id_usuario_cierre = :usuario,
                    fecha_cierre = NOW(), saldo_esperado = :esperado,
                    saldo_declarado = :declarado, diferencia = :diferencia, observacion = :observacion
                 WHERE id_sesion_caja = :id AND estado = 'abierta'"
            );
            $stmt->execute([
                ':usuario' => $idUsuario,
                ':esperado' => Money::decimal($esperadoCents),
                ':declarado' => Money::decimal($declaradoCents),
                ':diferencia' => Money::decimal($diferenciaCents),
                ':observacion' => trim($observacion) !== '' ? mb_substr(trim($observacion), 0, 500) : null,
                ':id' => (int) $sesion['id_sesion_caja'],
            ]);
            if ($stmt->rowCount() !== 1) {
                $this->db->rollBack();
                $error = 'La caja ya fue cerrada por otro usuario.';
                return null;
            }
            $this->db->commit();
            return [
                'id_sesion_caja' => (int) $sesion['id_sesion_caja'],
                'saldo_esperado' => Money::decimal($esperadoCents),
                'saldo_declarado' => Money::decimal($declaradoCents),
                'diferencia' => Money::decimal($diferenciaCents),
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            SafeException::log('cash_model_failed', $e, 'CashModel.cerrar');
            $error = 'No se pudo cerrar la caja.';
            return null;
        }
    }

    public function movimientosAbierta(): array
    {
        $sesion = $this->abierta();
        if (!$sesion) return [];
        return $this->movimientos((int) $sesion['id_sesion_caja']);
    }

    public function movimientos(int $idSesion): array
    {
        $stmt = $this->db->prepare(
            'SELECT cm.*, u.nombre AS usuario_nombre, u.apellidos AS usuario_apellidos
             FROM caja_movimiento cm
             LEFT JOIN usuario u ON u.id_usuario = cm.id_usuario
             INNER JOIN caja_sesion cs ON cs.id_sesion_caja = cm.id_sesion_caja
             WHERE cm.id_sesion_caja = :sesion AND cs.id_empresa = :empresa AND cs.id_gimnasio = :sede
             ORDER BY cm.fecha DESC, cm.id_movimiento_caja DESC'
        );
        $stmt->execute([':sesion' => $idSesion, ':empresa' => $this->empresaId, ':sede' => $this->sedeId]);
        return $stmt->fetchAll();
    }

    public function historial(int $limite = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT cs.*, ua.nombre AS apertura_nombre, ua.apellidos AS apertura_apellidos,
                    uc.nombre AS cierre_nombre, uc.apellidos AS cierre_apellidos
             FROM caja_sesion cs
             LEFT JOIN usuario ua ON ua.id_usuario = cs.id_usuario_apertura
             LEFT JOIN usuario uc ON uc.id_usuario = cs.id_usuario_cierre
             WHERE cs.id_empresa = :empresa AND cs.id_gimnasio = :sede AND cs.estado = 'cerrada'
             ORDER BY cs.fecha_cierre DESC LIMIT :limite"
        );
        $stmt->bindValue(':empresa', $this->empresaId, PDO::PARAM_INT);
        $stmt->bindValue(':sede', $this->sedeId, PDO::PARAM_INT);
        $stmt->bindValue(':limite', max(1, min(200, $limite)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function resumenPeriodo(string $desde, string $hasta): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS sesiones_cerradas,
                    COALESCE(SUM(saldo_esperado), 0) AS saldo_esperado,
                    COALESCE(SUM(saldo_declarado), 0) AS saldo_declarado,
                    COALESCE(SUM(diferencia), 0) AS diferencias
             FROM caja_sesion
             WHERE id_empresa = :empresa AND id_gimnasio = :sede AND estado = 'cerrada'
               AND DATE(fecha_cierre) BETWEEN :desde AND :hasta"
        );
        $stmt->execute([':empresa' => $this->empresaId, ':sede' => $this->sedeId, ':desde' => $desde, ':hasta' => $hasta]);
        return $stmt->fetch() ?: [];
    }

    private function bloquearAbierta(): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM caja_sesion
             WHERE id_empresa = :empresa AND id_gimnasio = :sede AND estado = 'abierta'
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([':empresa' => $this->empresaId, ':sede' => $this->sedeId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function usuarioEnEmpresa(int $idUsuario): bool
    {
        if ($idUsuario <= 0) return false;
        $stmt = $this->db->prepare(
            "SELECT 1 FROM usuario
             WHERE id_usuario = :usuario AND activo = 1
               AND (rol = 'superadmin' OR id_empresa = :empresa) LIMIT 1"
        );
        $stmt->execute([':usuario' => $idUsuario, ':empresa' => $this->empresaId]);
        return (bool) $stmt->fetchColumn();
    }
}
