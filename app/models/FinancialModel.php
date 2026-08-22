<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Money.php';
require_once __DIR__ . '/../helpers/TenantLifecyclePolicy.php';
require_once __DIR__ . '/../services/CashMovementRecorder.php';

/** Escrituras económicas centralizadas. No inicia transacciones anidadas. */
final class FinancialModel
{
    private PDO $db;
    private int $empresaId;
    private int $sedeId;
    private CashMovementRecorder $caja;

    public function __construct(int $empresaId, int $sedeId, ?PDO $db = null)
    {
        if ($empresaId <= 0 || $sedeId <= 0) {
            throw new InvalidArgumentException('El contexto económico exige empresa y sede.');
        }
        $this->db = $db ?: Database::getInstance()->getConnection();
        $this->empresaId = $empresaId;
        $this->sedeId = $sedeId;
        $this->caja = new CashMovementRecorder($empresaId, $sedeId, $this->db);
    }

    /** Crea obligación y, si el cobro es inmediato, el cobro confirmado. */
    public function registrarMembresia(int $idMembresia, ?int $idUsuario = null): array
    {
        $tenantLifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->empresaId);
        $stmt = $this->db->prepare(
            "SELECT sm.*, g.id_empresa
             FROM socio_membresia sm
             INNER JOIN gimnasio g ON g.id_gimnasio = sm.id_gimnasio
             WHERE sm.id_socio_membresia = :id
               AND sm.id_gimnasio = :sede AND g.id_empresa = :empresa
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([':id' => $idMembresia, ':sede' => $this->sedeId, ':empresa' => $this->empresaId]);
        $m = $stmt->fetch();
        if (!$m) throw new RuntimeException('La membresía no pertenece al contexto económico.');

        $buscar = $this->db->prepare('SELECT id_obligacion, estado FROM obligacion_pago WHERE id_socio_membresia = :id LIMIT 1');
        $buscar->execute([':id' => $idMembresia]);
        $existente = $buscar->fetch();
        if ($existente) {
            return ['id_obligacion' => (int) $existente['id_obligacion'], 'id_cobro' => null, 'estado' => $existente['estado']];
        }

        $importeCents = Money::cents($m['precio_pagado']) + Money::cents($m['precio_suplemento']);
        $esExenta = (int) $m['es_prueba'] === 1 || $importeCents === 0;
        $inmediato = !$esExenta && in_array($m['metodo_pago'], ['efectivo', 'datafono'], true);
        $estado = $esExenta ? 'exenta' : ($inmediato ? 'pagada' : 'pendiente');
        $concepto = 'Membresía ' . $m['nombre_tipo'];
        if (!empty($m['nombre_suplemento'])) $concepto .= ' + ' . $m['nombre_suplemento'];

        $insert = $this->db->prepare(
            'INSERT INTO obligacion_pago
             (id_empresa, id_gimnasio, id_socio, id_socio_membresia, concepto,
              importe, fecha_emision, fecha_vencimiento, estado, origen, id_usuario_creador, idempotency_key)
             VALUES
             (:empresa, :sede, :socio, :membresia, :concepto,
              :importe, :emision, :vencimiento, :estado, :origen, :usuario, :clave)'
        );
        $insert->execute([
            ':empresa' => $this->empresaId,
            ':sede' => $this->sedeId,
            ':socio' => (int) $m['id_socio'],
            ':membresia' => $idMembresia,
            ':concepto' => mb_substr($concepto, 0, 190),
            ':importe' => Money::decimal($importeCents),
            ':emision' => $m['fecha_inicio'],
            ':vencimiento' => $m['fecha_inicio'],
            ':estado' => $estado,
            ':origen' => $m['origen'] === 'automatica' ? 'membresia' : 'membresia',
            ':usuario' => $idUsuario ?: null,
            ':clave' => !empty($m['idempotency_key']) ? 'membresia:' . $m['idempotency_key'] : null,
        ]);
        $idObligacion = (int) $this->db->lastInsertId();
        $idCobro = null;

        if ($inmediato) {
            $metodo = CashMovementRecorder::normalizarMetodo($m['metodo_pago']);
            $idCobro = $this->crearCobro([
                'id_socio' => (int) $m['id_socio'],
                'id_obligacion' => $idObligacion,
                'id_socio_membresia' => $idMembresia,
                'concepto' => $concepto,
                'importe_cents' => $importeCents,
                'metodo' => $metodo,
                'estado' => 'confirmado',
                'id_usuario' => $idUsuario,
                'origen' => 'mostrador',
                'idempotency_key' => !empty($m['idempotency_key']) ? 'cobro:' . $m['idempotency_key'] : 'cobro-membresia-' . $idMembresia,
            ]);
            $this->caja->registrar(
                'cobro', $metodo, $importeCents, null, $idCobro, $idUsuario,
                $concepto, null, 'cobro-membresia-' . $idMembresia
            );
        }

        $this->db->prepare("UPDATE socio_membresia SET estado_pago = :estado WHERE id_socio_membresia = :id")
            ->execute([':estado' => in_array($estado, ['pagada', 'exenta'], true) ? 'pagado' : 'pendiente', ':id' => $idMembresia]);

        return ['id_obligacion' => $idObligacion, 'id_cobro' => $idCobro, 'estado' => $estado];
    }

    /** Crea el intento de cobro asociado a un recibo SEPA recién generado. */
    public function registrarReciboRemesa(int $idRecibo, ?int $idUsuario = null): int
    {
        $tenantLifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->empresaId);
        $stmt = $this->db->prepare(
            "SELECT rr.*, r.id_gimnasio, r.created_at, g.id_empresa, o.id_obligacion
             FROM remesa_recibo rr
             INNER JOIN remesa r ON r.id_remesa = rr.id_remesa
             INNER JOIN gimnasio g ON g.id_gimnasio = r.id_gimnasio
             LEFT JOIN obligacion_pago o ON o.id_socio_membresia = rr.id_socio_membresia
             WHERE rr.id_recibo = :id AND r.id_gimnasio = :sede AND g.id_empresa = :empresa
             LIMIT 1"
        );
        $stmt->execute([':id' => $idRecibo, ':sede' => $this->sedeId, ':empresa' => $this->empresaId]);
        $r = $stmt->fetch();
        if (!$r) throw new RuntimeException('El recibo no pertenece al contexto económico.');

        return $this->crearCobro([
            'id_socio' => (int) $r['id_socio'],
            'id_obligacion' => !empty($r['id_obligacion']) ? (int) $r['id_obligacion'] : null,
            'id_socio_membresia' => !empty($r['id_socio_membresia']) ? (int) $r['id_socio_membresia'] : null,
            'id_remesa_recibo' => $idRecibo,
            'concepto' => $r['concepto'],
            'importe_cents' => Money::cents($r['importe']),
            'metodo' => 'domiciliacion',
            'estado' => 'presentado',
            'id_usuario' => $idUsuario,
            'referencia' => $r['referencia_mandato'],
            'origen' => 'remesa',
            'idempotency_key' => 'recibo-remesa-' . $idRecibo,
        ]);
    }

    /** Confirma exactamente los cobros presentados de una remesa. */
    public function confirmarRemesa(int $idRemesa, ?int $idUsuario = null): void
    {
        $tenantLifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->empresaId);
        $stmt = $this->db->prepare(
            "SELECT c.id_cobro, c.id_obligacion, c.importe, c.concepto
             FROM cobro c
             INNER JOIN remesa_recibo rr ON rr.id_recibo = c.id_remesa_recibo
             INNER JOIN remesa r ON r.id_remesa = rr.id_remesa
             WHERE r.id_remesa = :remesa AND r.id_gimnasio = :sede
               AND c.id_empresa = :empresa AND c.estado = 'presentado'
             FOR UPDATE"
        );
        $stmt->execute([':remesa' => $idRemesa, ':sede' => $this->sedeId, ':empresa' => $this->empresaId]);
        $cobros = $stmt->fetchAll();
        $update = $this->db->prepare(
            "UPDATE cobro SET estado = 'confirmado', fecha_estado = NOW(), id_usuario = COALESCE(:usuario, id_usuario)
             WHERE id_cobro = :id AND estado = 'presentado'"
        );
        foreach ($cobros as $c) {
            $update->execute([':usuario' => $idUsuario ?: null, ':id' => (int) $c['id_cobro']]);
            if ($update->rowCount() !== 1) continue;
            $this->sincronizarObligacion((int) ($c['id_obligacion'] ?? 0));
            $this->caja->registrar(
                'cobro', 'domiciliacion', Money::cents($c['importe']), null,
                (int) $c['id_cobro'], $idUsuario, $c['concepto'], null,
                'confirmacion-cobro-' . (int) $c['id_cobro']
            );
        }
    }

    /** Devuelve un cobro una sola vez y reactiva la deuda de su obligación. */
    public function devolverRecibo(int $idRecibo, string $motivo, ?int $idUsuario = null): bool
    {
        $tenantLifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->empresaId);
        $stmt = $this->db->prepare(
            "SELECT c.* FROM cobro c
             INNER JOIN remesa_recibo rr ON rr.id_recibo = c.id_remesa_recibo
             INNER JOIN remesa r ON r.id_remesa = rr.id_remesa
             WHERE c.id_remesa_recibo = :recibo AND r.id_gimnasio = :sede
               AND c.id_empresa = :empresa AND c.estado IN ('presentado','confirmado')
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([':recibo' => $idRecibo, ':sede' => $this->sedeId, ':empresa' => $this->empresaId]);
        $cobro = $stmt->fetch();
        if (!$cobro) return false;

        $update = $this->db->prepare(
            "UPDATE cobro SET estado = 'devuelto', motivo = :motivo, fecha_estado = NOW(), id_usuario = COALESCE(:usuario, id_usuario)
             WHERE id_cobro = :id AND estado IN ('presentado','confirmado')"
        );
        $update->execute([
            ':motivo' => mb_substr($motivo, 0, 255),
            ':usuario' => $idUsuario ?: null,
            ':id' => (int) $cobro['id_cobro'],
        ]);
        if ($update->rowCount() !== 1) return false;

        $this->sincronizarObligacion((int) ($cobro['id_obligacion'] ?? 0), true);
        // Solo se compensa un movimiento que llegó a confirmarse. Un intento
        // presentado y rechazado nunca fue ingreso y no debe generar un neto negativo.
        if ($cobro['estado'] === 'confirmado') {
            $this->caja->registrar(
                'devolucion', $cobro['metodo'], -Money::cents($cobro['importe']), null,
                (int) $cobro['id_cobro'], $idUsuario, 'Devolución: ' . $cobro['concepto'],
                $motivo, 'devolucion-cobro-' . (int) $cobro['id_cobro']
            );
        }
        return true;
    }

    private function crearCobro(array $datos): int
    {
        if (!empty($datos['id_remesa_recibo'])) {
            $buscar = $this->db->prepare('SELECT id_cobro FROM cobro WHERE id_remesa_recibo = :id LIMIT 1');
            $buscar->execute([':id' => $datos['id_remesa_recibo']]);
            $id = (int) $buscar->fetchColumn();
            if ($id > 0) return $id;
        }
        $buscar = $this->db->prepare('SELECT id_cobro FROM cobro WHERE id_empresa = :empresa AND idempotency_key = :clave LIMIT 1');
        $buscar->execute([':empresa' => $this->empresaId, ':clave' => $datos['idempotency_key']]);
        $id = (int) $buscar->fetchColumn();
        if ($id > 0) return $id;

        $stmt = $this->db->prepare(
            'INSERT INTO cobro
             (id_empresa, id_gimnasio, id_socio, id_obligacion, id_socio_membresia,
              id_remesa_recibo, concepto, importe, metodo, estado, id_usuario,
              referencia, origen, idempotency_key)
             VALUES
             (:empresa, :sede, :socio, :obligacion, :membresia,
              :recibo, :concepto, :importe, :metodo, :estado, :usuario,
              :referencia, :origen, :clave)'
        );
        $stmt->execute([
            ':empresa' => $this->empresaId,
            ':sede' => $this->sedeId,
            ':socio' => $datos['id_socio'],
            ':obligacion' => $datos['id_obligacion'] ?? null,
            ':membresia' => $datos['id_socio_membresia'] ?? null,
            ':recibo' => $datos['id_remesa_recibo'] ?? null,
            ':concepto' => mb_substr($datos['concepto'], 0, 190),
            ':importe' => Money::decimal($datos['importe_cents']),
            ':metodo' => $datos['metodo'],
            ':estado' => $datos['estado'],
            ':usuario' => $datos['id_usuario'] ?: null,
            ':referencia' => $datos['referencia'] ?? null,
            ':origen' => $datos['origen'],
            ':clave' => mb_substr($datos['idempotency_key'], 0, 80),
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function sincronizarObligacion(int $idObligacion, bool $devuelta = false): void
    {
        if ($idObligacion <= 0) return;
        $stmt = $this->db->prepare(
            "SELECT o.importe, o.fecha_vencimiento, o.id_socio_membresia,
                    COALESCE(SUM(CASE WHEN c.estado = 'confirmado' THEN c.importe ELSE 0 END), 0) AS cobrado
             FROM obligacion_pago o
             LEFT JOIN cobro c ON c.id_obligacion = o.id_obligacion
             WHERE o.id_obligacion = :id AND o.id_empresa = :empresa AND o.id_gimnasio = :sede
             GROUP BY o.id_obligacion"
        );
        $stmt->execute([':id' => $idObligacion, ':empresa' => $this->empresaId, ':sede' => $this->sedeId]);
        $o = $stmt->fetch();
        if (!$o) return;
        $pagada = Money::cents($o['cobrado']) >= Money::cents($o['importe']);
        $estado = $pagada ? 'pagada' : (($devuelta || $o['fecha_vencimiento'] < date('Y-m-d')) ? 'vencida' : 'pendiente');
        $this->db->prepare('UPDATE obligacion_pago SET estado = :estado WHERE id_obligacion = :id')
            ->execute([':estado' => $estado, ':id' => $idObligacion]);
        if (!empty($o['id_socio_membresia'])) {
            $this->db->prepare("UPDATE socio_membresia SET estado_pago = :estado WHERE id_socio_membresia = :id")
                ->execute([':estado' => $pagada ? 'pagado' : 'pendiente', ':id' => (int) $o['id_socio_membresia']]);
        }
    }
}
