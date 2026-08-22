<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/models/UserModel.php';
require_once dirname(__DIR__) . '/models/MembresiaModel.php';
require_once dirname(__DIR__) . '/models/LogModel.php';
require_once dirname(__DIR__) . '/helpers/TenantLifecyclePolicy.php';

/** Alta atómica de socio y, opcionalmente, su primera membresía. */
final class SocioRegistrationService
{
    private PDO $db;
    private int $empresaId;
    private int $sedeId;
    private UserModel $usuarios;
    private MembresiaModel $membresias;

    public function __construct(int $empresaId, int $sedeId)
    {
        if ($empresaId <= 0 || $sedeId <= 0) throw new InvalidArgumentException('El alta exige empresa y sede.');
        $this->db = Database::getInstance()->getConnection();
        $this->empresaId = $empresaId;
        $this->sedeId = $sedeId;
        $this->usuarios = new UserModel($sedeId, $empresaId);
        $this->membresias = new MembresiaModel($sedeId, $empresaId);
    }

    public function registrar(
        array $socio,
        ?int $idTipo,
        string $metodoPago,
        ?int $idSuplemento,
        ?int $actorId,
        ?string $idempotencyKey,
        string &$error
    ): ?array {
        $tenantLifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->empresaId);
        try {
            $this->db->beginTransaction();
            $creado = $this->usuarios->crear(
                $socio['nombre'], $socio['apellidos'], $socio['dni'], $socio['telefono'],
                $socio['email'], $socio['usuario'], $socio['contrasena'], $socio['iban']
            );
            if (!$creado) throw new RuntimeException('No se pudo crear el socio.');
            $nuevo = $this->usuarios->buscarPorCorreo($socio['email']);
            $idSocio = (int) ($nuevo['id_usuario'] ?? 0);
            if ($idSocio <= 0) throw new RuntimeException('No se pudo identificar el socio creado.');

            $idMembresia = null;
            if (($idTipo ?? 0) > 0) {
                $errorMembresia = '';
                $idMembresia = $this->membresias->contratar(
                    $idSocio, (int) $idTipo, $metodoPago, $errorMembresia, $idSuplemento,
                    'mostrador', $idempotencyKey, $actorId, $socio['iban']
                );
                if ($idMembresia === null) {
                    throw new DomainException($errorMembresia ?: 'No se pudo registrar la membresía inicial.');
                }
            }

            (new LogModel($this->empresaId))->registrarCambio(
                $actorId, 'Alta de socio', trim($socio['nombre'] . ' ' . $socio['apellidos']),
                $idSocio, 'socio', $idSocio, null, $idMembresia ? 'socio+membresía' : 'socio',
                $this->sedeId, 'exito'
            );
            if ($idMembresia !== null) {
                (new LogModel($this->empresaId))->registrarCambio(
                    $actorId, 'Alta de membresía inicial', 'Alta conjunta con el socio',
                    $idSocio, 'socio_membresia', $idMembresia, null, 'activa',
                    $this->sedeId, 'exito'
                );
            }
            $this->db->commit();
            return ['id_socio' => $idSocio, 'id_membresia' => $idMembresia];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $error = $e instanceof DomainException ? $e->getMessage() : 'No se pudo dar de alta al socio.';
            (new LogModel($this->empresaId))->registrarCambio(
                $actorId, 'Alta socio/membresía rechazada', $error,
                null, 'socio', null, null, null, $this->sedeId, 'fallo'
            );
            return null;
        }
    }
}
