<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/Money.php';

/** Estado financiero derivado; no persiste totales ni etiquetas redundantes. */
final class SocioFinancialService
{
    private PDO $db;
    private int $empresaId;
    private ?int $sedeId;

    public function __construct(int $empresaId, ?int $sedeId = null)
    {
        $this->db = Database::getInstance()->getConnection();
        $this->empresaId = $empresaId;
        $this->sedeId = $sedeId;
    }

    public function estado(int $idSocio): ?array
    {
        $socio = $this->socioEnAmbito($idSocio);
        if (!$socio) return null;

        $membresia = $this->membresiaActual($idSocio);
        $stmt = $this->db->prepare(
            "SELECT o.id_obligacion, o.importe, o.fecha_vencimiento, o.estado,
                    COALESCE(SUM(CASE WHEN c.estado = 'confirmado' THEN c.importe ELSE 0 END), 0) AS cobrado,
                    SUM(CASE WHEN c.estado = 'devuelto' THEN 1 ELSE 0 END) AS devoluciones,
                    SUM(CASE WHEN c.estado = 'presentado' THEN 1 ELSE 0 END) AS presentados
             FROM obligacion_pago o
             LEFT JOIN cobro c ON c.id_obligacion = o.id_obligacion
             WHERE o.id_socio = :socio AND o.id_empresa = :empresa" . $this->filtroSede('o') . "
               AND o.estado NOT IN ('cancelada','exenta')
             GROUP BY o.id_obligacion, o.importe, o.fecha_vencimiento, o.estado"
        );
        $stmt->execute([':socio' => $idSocio, ':empresa' => $this->empresaId]);
        $deudaCents = 0;
        $devueltos = 0;
        $presentados = 0;
        $vencidas = 0;
        foreach ($stmt->fetchAll() as $o) {
            $pendiente = max(0, Money::cents($o['importe']) - Money::cents($o['cobrado']));
            $deudaCents += $pendiente;
            $devueltos += (int) $o['devoluciones'];
            $presentados += (int) $o['presentados'];
            if ($pendiente > 0 && $o['fecha_vencimiento'] < date('Y-m-d')) $vencidas++;
        }

        $ultimo = $this->db->prepare(
            "SELECT id_cobro, concepto, importe, metodo, estado, fecha, fecha_estado, referencia
             FROM cobro WHERE id_socio = :socio AND id_empresa = :empresa" . $this->filtroSede('') . "
             ORDER BY fecha_estado DESC, id_cobro DESC LIMIT 1"
        );
        $ultimo->execute([':socio' => $idSocio, ':empresa' => $this->empresaId]);
        $ultimoCobro = $ultimo->fetch() ?: null;

        if ($devueltos > 0 && $deudaCents > 0) $estado = 'DEVUELTO';
        elseif ($vencidas > 0) $estado = 'IMPAGADO';
        elseif ($deudaCents > 0 || $presentados > 0) $estado = 'PENDIENTE';
        elseif ($membresia && (int) $membresia['es_prueba'] === 1) $estado = 'EXENTO';
        else $estado = 'AL_CORRIENTE';

        return [
            'socio' => $socio,
            'membresia' => $membresia,
            'precio_contratado' => $membresia ? Money::decimal(Money::cents($membresia['precio_pagado']) + Money::cents($membresia['precio_suplemento'])) : null,
            'fecha_inicio' => $membresia['fecha_inicio'] ?? null,
            'fecha_vencimiento' => $membresia['fecha_fin'] ?? null,
            'proximo_vencimiento' => $membresia['fecha_fin'] ?? null,
            'ultimo_cobro' => $ultimoCobro,
            'deuda' => Money::decimal($deudaCents),
            'deuda_cents' => $deudaCents,
            'recibos_devueltos' => $devueltos,
            'estado_economico' => $estado,
        ];
    }

    /** Resumen en una consulta agregada para listados; evita N+1. */
    public function resumenPorSocios(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (!$ids) return [];
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT o.id_socio,
                       COALESCE(SUM(GREATEST(o.importe - COALESCE(cc.cobrado, 0), 0)), 0) AS deuda,
                       COALESCE(SUM(cc.devueltos), 0) AS devueltos,
                       COALESCE(SUM(CASE WHEN o.fecha_vencimiento < CURDATE()
                            AND GREATEST(o.importe - COALESCE(cc.cobrado, 0), 0) > 0 THEN 1 ELSE 0 END), 0) AS vencidas
                FROM obligacion_pago o
                LEFT JOIN (
                    SELECT id_obligacion,
                           SUM(CASE WHEN estado = 'confirmado' THEN importe ELSE 0 END) AS cobrado,
                           SUM(CASE WHEN estado = 'devuelto' THEN 1 ELSE 0 END) AS devueltos
                    FROM cobro GROUP BY id_obligacion
                ) cc ON cc.id_obligacion = o.id_obligacion
                WHERE o.id_empresa = ?" . $this->filtroSedePosicional('o') . "
                  AND o.id_socio IN ($marcas) AND o.estado NOT IN ('cancelada','exenta')
                GROUP BY o.id_socio";
        $params = [$this->empresaId];
        if ($this->sedeId !== null) $params[] = $this->sedeId;
        array_push($params, ...$ids);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $resultado = [];
        foreach ($stmt->fetchAll() as $r) {
            $deudaCents = Money::cents($r['deuda']);
            $estado = (int) $r['devueltos'] > 0 && $deudaCents > 0
                ? 'DEVUELTO'
                : ((int) $r['vencidas'] > 0 ? 'IMPAGADO' : ($deudaCents > 0 ? 'PENDIENTE' : 'AL_CORRIENTE'));
            $resultado[(int) $r['id_socio']] = [
                'deuda' => Money::decimal($deudaCents),
                'deuda_cents' => $deudaCents,
                'devueltos' => (int) $r['devueltos'],
                'estado_economico' => $estado,
            ];
        }

        $ultimos = $this->db->prepare(
            "SELECT c.id_socio, c.id_cobro, c.importe, c.metodo, c.estado, c.fecha_estado
             FROM cobro c
             INNER JOIN (
                 SELECT id_socio, MAX(id_cobro) AS id_cobro
                 FROM cobro
                 WHERE id_empresa = ?" . $this->filtroSedePosicional('') . " AND id_socio IN ($marcas)
                 GROUP BY id_socio
             ) ultimo ON ultimo.id_cobro = c.id_cobro"
        );
        $paramsUltimo = [$this->empresaId];
        if ($this->sedeId !== null) $paramsUltimo[] = $this->sedeId;
        array_push($paramsUltimo, ...$ids);
        $ultimos->execute($paramsUltimo);
        foreach ($ultimos->fetchAll() as $c) {
            $idSocio = (int) $c['id_socio'];
            if (!isset($resultado[$idSocio])) {
                $resultado[$idSocio] = [
                    'deuda' => '0.00', 'deuda_cents' => 0, 'devueltos' => 0,
                    'estado_economico' => 'AL_CORRIENTE',
                ];
            }
            $resultado[$idSocio]['ultimo_cobro'] = $c;
        }
        return $resultado;
    }

    public function historial(int $idSocio, int $limite = 100): array
    {
        if (!$this->socioEnAmbito($idSocio)) return [];
        $stmt = $this->db->prepare(
            "SELECT 'membresia' AS tipo, sm.id_socio_membresia AS id, sm.created_at AS fecha,
                    CONCAT(sm.nombre_tipo, IF(sm.nombre_suplemento IS NULL, '', CONCAT(' + ', sm.nombre_suplemento))) AS concepto,
                    (sm.precio_pagado + sm.precio_suplemento) AS importe, sm.estado_pago AS estado
             FROM socio_membresia sm
             WHERE sm.id_socio = :socio1" . $this->filtroSede('sm') . "
             UNION ALL
             SELECT 'cobro', c.id_cobro, c.fecha_estado, c.concepto, c.importe, c.estado
             FROM cobro c
             WHERE c.id_socio = :socio2 AND c.id_empresa = :empresa" . $this->filtroSede('c') . "
             ORDER BY fecha DESC LIMIT :limite"
        );
        $stmt->bindValue(':socio1', $idSocio, PDO::PARAM_INT);
        $stmt->bindValue(':socio2', $idSocio, PDO::PARAM_INT);
        $stmt->bindValue(':empresa', $this->empresaId, PDO::PARAM_INT);
        $stmt->bindValue(':limite', max(1, min(500, $limite)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function resumenPeriodo(string $desde, string $hasta): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN c.estado = 'confirmado' THEN c.importe ELSE 0 END), 0) AS cobros_confirmados,
                COALESCE(SUM(CASE WHEN c.estado = 'devuelto' THEN c.importe ELSE 0 END), 0) AS devoluciones,
                COUNT(CASE WHEN c.estado = 'devuelto' THEN 1 END) AS num_devoluciones
             FROM cobro c
             WHERE c.id_empresa = :empresa" . $this->filtroSede('c') . "
               AND DATE(c.fecha_estado) BETWEEN :desde AND :hasta"
        );
        $stmt->execute([':empresa' => $this->empresaId, ':desde' => $desde, ':hasta' => $hasta]);
        $resumen = $stmt->fetch() ?: [];
        $deuda = $this->db->prepare(
            "SELECT COALESCE(SUM(GREATEST(o.importe - COALESCE(cc.cobrado, 0), 0)), 0)
             FROM obligacion_pago o
             LEFT JOIN (
                 SELECT id_obligacion, SUM(CASE WHEN estado = 'confirmado' THEN importe ELSE 0 END) cobrado
                 FROM cobro GROUP BY id_obligacion
             ) cc ON cc.id_obligacion = o.id_obligacion
             WHERE o.id_empresa = :empresa" . $this->filtroSede('o') . "
               AND o.estado NOT IN ('cancelada','exenta')"
        );
        $deuda->execute([':empresa' => $this->empresaId]);
        $resumen['deuda_pendiente'] = $deuda->fetchColumn() ?: '0.00';
        return $resumen;
    }

    public function resumenCajaPeriodo(string $desde, string $hasta): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS sesiones_cerradas,
                    COALESCE(SUM(saldo_esperado), 0) AS saldo_esperado,
                    COALESCE(SUM(saldo_declarado), 0) AS saldo_declarado,
                    COALESCE(SUM(diferencia), 0) AS diferencias
             FROM caja_sesion
             WHERE id_empresa = :empresa" . $this->filtroSede('') . "
               AND estado = 'cerrada' AND DATE(fecha_cierre) BETWEEN :desde AND :hasta"
        );
        $stmt->execute([':empresa' => $this->empresaId, ':desde' => $desde, ':hasta' => $hasta]);
        return $stmt->fetch() ?: [];
    }

    private function membresiaActual(int $idSocio): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM socio_membresia
             WHERE id_socio = :socio AND fecha_fin >= CURDATE()" . $this->filtroSede('') . "
             ORDER BY fecha_fin DESC, id_socio_membresia DESC LIMIT 1"
        );
        $stmt->execute([':socio' => $idSocio]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function socioEnAmbito(int $idSocio): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id_usuario, id_empresa, id_gimnasio, nombre, apellidos, activo
             FROM usuario WHERE id_usuario = :socio AND rol = 'socio' AND id_empresa = :empresa" . $this->filtroSede('') . " LIMIT 1"
        );
        $stmt->execute([':socio' => $idSocio, ':empresa' => $this->empresaId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function filtroSede(string $alias): string
    {
        if ($this->sedeId === null) return '';
        $p = $alias === '' ? '' : $alias . '.';
        return ' AND ' . $p . 'id_gimnasio = ' . (int) $this->sedeId;
    }

    private function filtroSedePosicional(string $alias): string
    {
        if ($this->sedeId === null) return '';
        $p = $alias === '' ? '' : $alias . '.';
        return ' AND ' . $p . 'id_gimnasio = ?';
    }
}
