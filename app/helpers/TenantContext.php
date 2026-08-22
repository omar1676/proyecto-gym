<?php

require_once __DIR__ . '/../config/database.php';

/** Contexto de autorización calculado en servidor para la petición actual. */
final class TenantContext
{
    private $db;
    private $usuarioId;
    private $rol;
    private $empresaId;
    private $sedeId;

    private function __construct(PDO $db, int $usuarioId, string $rol, ?int $empresaId, ?int $sedeId)
    {
        $this->db = $db;
        $this->usuarioId = $usuarioId;
        $this->rol = $rol;
        $this->empresaId = $empresaId;
        $this->sedeId = $sedeId;
    }

    public static function desdeSesion(): self
    {
        $db = Database::getInstance()->getConnection();
        $id = (int) ($_SESSION['usuario_id'] ?? 0);
        if ($id <= 0 || empty($_SESSION['logueado'])) {
            return new self($db, 0, '', null, null);
        }

        $stmt = $db->prepare(
            "SELECT u.id_usuario, u.rol, u.id_empresa, u.id_gimnasio, u.activo,
                    g.id_empresa AS empresa_sede
             FROM usuario u
             LEFT JOIN gimnasio g ON g.id_gimnasio = u.id_gimnasio
             WHERE u.id_usuario = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $u = $stmt->fetch();
        if (!$u || (int) $u['activo'] !== 1) {
            return new self($db, 0, '', null, null);
        }

        $rol = (string) $u['rol'];
        $empresaUsuario = (int) ($u['id_empresa'] ?? 0);
        $empresaSede = (int) ($u['empresa_sede'] ?? 0);
        // SUPERADMIN es una identidad interna de plataforma. Un registro con
        // ese texto de rol ligado a un tenant es inconsistente y no recibe
        // contexto autorizado, aunque una ruta futura lo creara por error.
        if ($rol === 'superadmin' && $empresaUsuario > 0) {
            return new self($db, 0, '', null, null);
        }
        $empresa = $empresaUsuario ?: $empresaSede;
        if (in_array($rol, ['admin', 'recepcion', 'socio'], true)
            && ($empresaUsuario <= 0 || $empresaSede <= 0 || $empresaUsuario !== $empresaSede)) {
            return new self($db, 0, '', null, null);
        }
        // El superadmin trabaja dentro de la empresa de la sede identificada.
        // La administración global de empresas queda para una pantalla futura.
        if ($rol === 'superadmin') {
            $sedeAcceso = (int) ($_SESSION['gimnasio_auth_id'] ?? 0);
            if ($sedeAcceso > 0) {
                $stmt = $db->prepare('SELECT id_empresa FROM gimnasio WHERE id_gimnasio = :id');
                $stmt->execute([':id' => $sedeAcceso]);
                $empresa = (int) $stmt->fetchColumn();
            }
        }
        if ($empresa <= 0) {
            return new self($db, 0, '', null, null);
        }
        $stmt = $db->prepare("SELECT 1 FROM empresa WHERE id_empresa = :id AND estado = 'activa'");
        $stmt->execute([':id' => $empresa]);
        if (!$stmt->fetchColumn()) {
            return new self($db, 0, '', null, null);
        }
        $sede = null;
        if (in_array($rol, ['admin', 'recepcion'], true)) {
            $sede = (int) $u['id_gimnasio'];
        } elseif ($rol === 'direccion' && !empty($_SESSION['gimnasio_activo'])) {
            $candidata = (int) $_SESSION['gimnasio_activo'];
            $sede = self::sedeDeEmpresa($db, $candidata, $empresa) ? $candidata : null;
        } elseif ($rol === 'superadmin' && !empty($_SESSION['gimnasio_activo'])) {
            $candidata = (int) $_SESSION['gimnasio_activo'];
            $sede = self::sedeDeEmpresa($db, $candidata, $empresa) ? $candidata : null;
        }

        return new self($db, $id, $rol, $empresa ?: null, $sede ?: null);
    }

    private static function sedeDeEmpresa(PDO $db, int $sede, ?int $empresa): bool
    {
        if ($sede <= 0 || !$empresa) return false;
        $stmt = $db->prepare('SELECT 1 FROM gimnasio WHERE id_gimnasio = :s AND id_empresa = :e');
        $stmt->execute([':s' => $sede, ':e' => $empresa]);
        return (bool) $stmt->fetchColumn();
    }

    public function autenticado(): bool { return $this->usuarioId > 0; }
    public function usuarioId(): int { return $this->usuarioId; }
    public function rol(): string { return $this->rol; }
    public function empresaId(): ?int { return $this->empresaId; }
    public function sedeId(): ?int { return $this->sedeId; }
    public function esSuperadmin(): bool { return $this->rol === 'superadmin'; }
    public function esDireccion(): bool { return $this->rol === 'direccion'; }

    public function puedeUsarSede(int $sede): bool
    {
        if ($sede <= 0) return false;
        if ($this->esSuperadmin() && $this->empresaId !== null) {
            return self::sedeDeEmpresa($this->db, $sede, $this->empresaId);
        }
        if ($this->rol === 'direccion') return self::sedeDeEmpresa($this->db, $sede, $this->empresaId);
        return $this->sedeId === $sede;
    }

    public function seleccionarSede(?int $sede): bool
    {
        if (!in_array($this->rol, ['superadmin', 'direccion'], true)) return false;
        if ($sede === null || $sede === 0) {
            unset($_SESSION['gimnasio_activo']);
            $this->sedeId = null;
            return true;
        }
        if (!$this->puedeUsarSede($sede)) return false;
        $_SESSION['gimnasio_activo'] = $sede;
        $this->sedeId = $sede;
        return true;
    }
}
