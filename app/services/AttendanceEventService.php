<?php

require_once dirname(__DIR__) . '/helpers/RequestContext.php';
require_once dirname(__DIR__) . '/helpers/TenantLifecyclePolicy.php';

/** Ingesta genérica e idempotente de presencia. No conoce hardware ni biometría. */
final class AttendanceEventService
{
    private const SOURCES = ['MANUAL', 'IMPORT', 'ACCESS_PROVIDER', 'API'];
    private const FAMILIES = ['GYM', 'BOXEO', 'TATAMI'];

    public function __construct(
        private PDO $db,
        private int $companyId,
        private DateTimeZone $timezone
    ) {
        if ($companyId <= 0) throw new InvalidArgumentException('La asistencia exige una empresa válida.');
    }

    /** @return array{id:int,event_id:string,created:bool,local_date:string} */
    public function record(
        int $siteId,
        int $memberId,
        DateTimeImmutable $occurredAt,
        string $source,
        ?string $externalReference = null,
        ?string $eventId = null,
        ?string $activityFamily = null
    ): array {
        $source = strtoupper(trim($source));
        if (!in_array($source, self::SOURCES, true)) throw new InvalidArgumentException('Origen de asistencia no válido.');
        if ($siteId <= 0 || $memberId <= 0) throw new InvalidArgumentException('Sede o socio no válido.');
        if ($activityFamily !== null) {
            $activityFamily = strtoupper(trim($activityFamily));
            if (!in_array($activityFamily, self::FAMILIES, true)) throw new InvalidArgumentException('Actividad no válida.');
        }
        $eventId ??= RequestContext::newId();
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $eventId)) {
            throw new InvalidArgumentException('Event ID no válido.');
        }
        $externalReference = $externalReference !== null ? trim($externalReference) : null;
        if ($externalReference === '') $externalReference = null;
        if ($externalReference !== null && (mb_strlen($externalReference) > 190 || preg_match('/[\x00-\x1F\x7F]/', $externalReference))) {
            throw new InvalidArgumentException('Referencia externa no válida.');
        }
        $utc = $occurredAt->setTimezone(new DateTimeZone('UTC'));
        if ($utc > new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC'))) {
            throw new InvalidArgumentException('La asistencia no puede estar en el futuro.');
        }
        $localDate = $utc->setTimezone($this->timezone)->format('Y-m-d');
        $idempotencyKey = hash('sha256', $this->companyId . '|' . $source . '|'
            . ($externalReference !== null ? 'external:' . $externalReference : 'event:' . strtolower($eventId)));

        $lifecycle = TenantLifecyclePolicy::acquireBusinessWrite($this->db, $this->companyId);
        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) $this->db->beginTransaction();
            $member = $this->db->prepare(
                "SELECT id_usuario FROM usuario
                  WHERE id_usuario=:member AND id_empresa=:company AND id_gimnasio=:site
                    AND rol='socio' AND activo=1 AND anonimizado_en IS NULL
                  FOR UPDATE"
            );
            $member->execute([':member'=>$memberId, ':company'=>$this->companyId, ':site'=>$siteId]);
            if (!$member->fetchColumn()) throw new DomainException('El socio no pertenece al contexto de asistencia autorizado.');

            try {
                $insert = $this->db->prepare(
                    'INSERT INTO attendance_event
                     (event_id,id_empresa,id_gimnasio,id_socio,occurred_at_utc,local_date,source,external_reference,idempotency_key,activity_family)
                     VALUES (:event,:company,:site,:member,:occurred,:local_date,:source,:external,:idempotency,:family)'
                );
                $insert->execute([
                    ':event'=>$eventId, ':company'=>$this->companyId, ':site'=>$siteId, ':member'=>$memberId,
                    ':occurred'=>$utc->format('Y-m-d H:i:s'), ':local_date'=>$localDate, ':source'=>$source,
                    ':external'=>$externalReference, ':idempotency'=>$idempotencyKey, ':family'=>$activityFamily,
                ]);
                $result = ['id'=>(int)$this->db->lastInsertId(), 'event_id'=>$eventId, 'created'=>true, 'local_date'=>$localDate];
            } catch (PDOException $error) {
                if ((string) $error->getCode() !== '23000') throw $error;
                $existing = $this->findExisting($eventId, $idempotencyKey, $externalReference, $source);
                if (!$existing || (int)$existing['id_gimnasio'] !== $siteId || (int)$existing['id_socio'] !== $memberId
                    || (string)$existing['occurred_at_utc'] !== $utc->format('Y-m-d H:i:s')) {
                    throw new DomainException('La clave de asistencia ya existe con un contenido diferente.');
                }
                $result = ['id'=>(int)$existing['id_attendance_event'], 'event_id'=>(string)$existing['event_id'], 'created'=>false, 'local_date'=>(string)$existing['local_date']];
            }
            if ($ownTransaction) $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($ownTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        } finally {
            $lifecycle->release();
        }
    }

    private function findExisting(string $eventId, string $idempotencyKey, ?string $externalReference, string $source): ?array
    {
        $sql = 'SELECT * FROM attendance_event WHERE id_empresa=:company AND (event_id=:event OR idempotency_key=:key';
        $params = [':company'=>$this->companyId, ':event'=>$eventId, ':key'=>$idempotencyKey];
        if ($externalReference !== null) {
            $sql .= ' OR (source=:source AND external_reference=:external)';
            $params[':source'] = $source;
            $params[':external'] = $externalReference;
        }
        $sql .= ') LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
