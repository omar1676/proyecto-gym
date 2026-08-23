<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/helpers/AuditPolicy.php';
require_once dirname(__DIR__) . '/helpers/Iban.php';
require_once dirname(__DIR__) . '/helpers/InputValidator.php';
require_once dirname(__DIR__) . '/helpers/TenantLifecyclePolicy.php';
require_once dirname(__DIR__) . '/models/LogModel.php';

/** Edición atómica y optimista del perfil operativo de un socio. */
final class SocioProfileService
{
    public function __construct(
        private int $companyId,
        private ?int $siteId,
        private ?int $actorId,
        private ?PDO $db = null
    ) {
        if ($companyId <= 0) throw new InvalidArgumentException('Empresa no válida.');
        $this->db = $db ?: Database::getInstance()->getConnection();
    }

    /** @return array{status:string,version?:int,changed?:array}|null */
    public function update(int $memberId, int $expectedVersion, array $values, string &$error): ?array
    {
        if ($memberId <= 0 || $expectedVersion <= 0) {
            $error = 'La ficha enviada ya no es válida. Vuelve a abrirla.';
            return null;
        }

        $lease = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->companyId);
        try {
            $this->db->beginTransaction();
            $sql = "SELECT id_usuario,nombre,apellidos,dni,telefono,email,iban,profile_version
                      FROM usuario
                     WHERE id_usuario=:id AND id_empresa=:empresa AND rol='socio'";
            $params = [':id' => $memberId, ':empresa' => $this->companyId];
            if ($this->siteId !== null) {
                $sql .= ' AND id_gimnasio=:sede';
                $params[':sede'] = $this->siteId;
            }
            $sql .= ' FOR UPDATE';
            $select = $this->db->prepare($sql);
            $select->execute($params);
            $before = $select->fetch(PDO::FETCH_ASSOC);
            if (!$before) throw new DomainException('El socio no existe en la sede autorizada.');
            if ((int) $before['profile_version'] !== $expectedVersion) {
                $this->db->rollBack();
                $error = 'Otra persona ha actualizado esta ficha. Vuelve a abrirla antes de guardar.';
                return ['status' => 'conflict'];
            }

            $fields = ['nombre','apellidos','dni','telefono','email','iban'];
            $changed = [];
            foreach ($fields as $field) {
                if (($before[$field] ?? null) !== ($values[$field] ?? null)) $changed[] = $field;
            }
            if ($changed === []) {
                $this->db->rollBack();
                return ['status' => 'unchanged', 'version' => $expectedVersion, 'changed' => []];
            }

            $update = $this->db->prepare(
                'UPDATE usuario SET nombre=:nombre,apellidos=:apellidos,dni=:dni,telefono=:telefono,
                        email=:email,iban=:iban,profile_version=profile_version+1
                  WHERE id_usuario=:id AND id_empresa=:empresa AND profile_version=:version'
                . ($this->siteId !== null ? ' AND id_gimnasio=:sede' : '')
            );
            $updateParams = [
                ':nombre'=>$values['nombre'], ':apellidos'=>$values['apellidos'], ':dni'=>$values['dni'],
                ':telefono'=>$values['telefono'], ':email'=>$values['email'], ':iban'=>$values['iban'],
                ':id'=>$memberId, ':empresa'=>$this->companyId, ':version'=>$expectedVersion,
            ];
            if ($this->siteId !== null) $updateParams[':sede'] = $this->siteId;
            $update->execute($updateParams);
            if ($update->rowCount() !== 1) throw new RuntimeException('La ficha cambió durante la actualización.');

            $log = new LogModel($this->companyId, $this->db);
            $log->registrarCambio(
                $this->actorId, 'Edición de socio', 'Campos modificados: ' . implode(', ', $changed),
                $memberId, 'socio', $memberId, null, null, $this->siteId, 'exito',
                'SOCIO_PROFILE_UPDATED', ['changed_fields' => implode(',', $changed)],
                'usuario', null, AuditPolicy::REQUIRED
            );
            if (in_array('dni', $changed, true)) {
                $log->registrarCambio(
                    $this->actorId, 'Cambio de DNI/NIE', 'Identificador del socio actualizado',
                    $memberId, 'socio', $memberId,
                    InputValidator::maskDniNie($before['dni']), InputValidator::maskDniNie($values['dni']),
                    $this->siteId, 'exito', 'SOCIO_DNI_UPDATED', [], 'usuario', null, AuditPolicy::REQUIRED
                );
            }
            if (in_array('iban', $changed, true)) {
                $log->registrarCambio(
                    $this->actorId, 'Cambio de IBAN', 'Cuenta de domiciliación del socio actualizada',
                    $memberId, 'socio', $memberId,
                    $before['iban'] ? Iban::enmascarar($before['iban']) : 'sin IBAN',
                    $values['iban'] ? Iban::enmascarar($values['iban']) : 'sin IBAN',
                    $this->siteId, 'exito', 'SOCIO_IBAN_UPDATED', [], 'usuario', null, AuditPolicy::REQUIRED
                );
            }
            $this->db->commit();
            return ['status' => 'updated', 'version' => $expectedVersion + 1, 'changed' => $changed];
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ((string) $exception->getCode() === '23000') {
                $error = 'El DNI/NIE o el email ya pertenecen a otra cuenta de esta empresa.';
            } else {
                $error = 'No se pudieron guardar los cambios.';
            }
            return null;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $error = $exception instanceof DomainException
                ? $exception->getMessage()
                : 'No se pudieron guardar los cambios.';
            return null;
        } finally {
            $lease->release();
        }
    }
}
