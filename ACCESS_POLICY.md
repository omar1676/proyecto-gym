# Gimnera Access Policy v1

## Alcance y límite

Este dominio decide si Gimnera considera permitido un acceso. No abre puertas,
no confirma entradas físicas y no almacena huellas, plantillas biométricas ni
credenciales de un fabricante. `ACCESS_CONTROL_MODE=disabled` continúa siendo
el valor seguro y obligatorio de staging.

La decisión se evalúa en cada petición. El job de caducidad mejora la
observabilidad y prepara la sincronización futura, pero no es el mecanismo de
seguridad: un temporal vencido se deniega aunque cron esté detenido.

## Estados

| Estado | Significado | Acceso lógico |
|---|---|---|
| `ALLOWED` | No hay excepción manual que bloquee; se consulta membresía/economía | Depende de elegibilidad base |
| `TEMPORARY` | Excepción acotada por `starts_at_utc` y `expires_at_utc` | Permitido solo en `[inicio, caducidad)` |
| `SUSPENDED` | Bloqueo reversible que exige revisión humana | Denegado, también después de `suspended_until_utc` hasta restauración |
| `DENIED` | Denegación administrativa | Denegado |
| `PERMANENT_BLOCK` | Bloqueo explícito de máxima precedencia | Denegado hasta restauración expresa de dirección |

La ausencia de fila equivale a consultar exclusivamente la elegibilidad base.
Un `DENIED/TEMPORARY_EXPIRED` no corta una membresía pagada posteriormente: la
decisión en tiempo real devuelve `MEMBERSHIP_CONVERTED` y el job materializa
`ALLOWED`.

## Precedencia

1. Empresa no operativa o socio inactivo: denegación.
2. `PERMANENT_BLOCK`: denegación dominante.
3. `SUSPENDED`: denegación hasta restauración explícita.
4. `DENIED`: denegación, salvo la conversión técnica tras temporal caducado.
5. `TEMPORARY`: permite únicamente antes de la caducidad UTC.
6. `ALLOWED` o ausencia de política: membresía/economía determinan el resultado.

Un bloqueo permanente iniciado concurrentemente por dirección prevalece sobre
una concesión temporal. Las demás ediciones usan `version` como compare-and-set
y rechazan sobrescrituras silenciosas.

## Tiempo

- Persistencia: `DATETIME` UTC.
- Límite: caduca exactamente cuando `now >= expires_at_utc`.
- UI: interpreta `datetime-local` como `Europe/Madrid` y lo convierte a UTC.
- Recepción: máximo configurable por tenant mediante
  `configuracion.access_policy.recepcion_max_temporary_days`, seguro por defecto
  en 3 días.
- Admin de sede: máximo 90 días.
- Dirección: máximo 366 días.

Los cambios de horario de verano no alteran el instante almacenado. Una fecha
local inexistente o ambigua debe validarse antes de llegar al servicio.

## Motivos controlados

Los códigos aceptados son una allowlist operativa (`TEMPORARY_VISIT`, `TRIAL`,
`MANUAL_EXCEPTION`, `INCIDENT_REVIEW`, `POLICY_REVIEW`,
`ADMINISTRATIVE_REVIEW`, `MEMBERSHIP_REQUIRED`, `PAYMENT_REVIEW`,
`POLICY_DENIED`, `SAFETY_BLOCK`, `FRAUD_BLOCK`, `ADMINISTRATIVE_BLOCK`,
`MANUAL_RESTORE`, `MEMBERSHIP_CONVERTED`, `TEMPORARY_EXPIRED`).

No se modelan diagnósticos, patologías ni decisiones médicas. La nota libre es
operativa, opcional, limitada a 255 caracteres y no debe contener historia
clínica.

## Roles

| Capacidad | Dirección | Admin sede | Recepción | Socio | Superadmin global |
|---|---:|---:|---:|---:|---:|
| Ver estado | Sí | Sí, su sede | Sí, su sede | No hay portal | Solo con contexto tenant válido |
| Temporal | Sí | Sí, su sede | Sí, máximo tenant | No | Solo con contexto tenant válido |
| Suspender / denegar | Sí | Sí, su sede | No | No | Solo con contexto tenant válido |
| Bloqueo permanente | Sí | No | No | No | Solo con contexto tenant válido |
| Restaurar | Sí | Sí salvo permanente | No | No | Solo con contexto tenant válido |
| Auditoría/dashboard | Sí | Sí, su sede | No | No | Solo con contexto tenant válido |

Empresa y sede proceden de `TenantContext`; el controlador no acepta
`id_empresa` ni `id_gimnasio` del navegador. Todos los cambios son POST + CSRF.

## Persistencia y auditoría

- `access_policy`: estado actual único por empresa/sede/socio.
- `access_policy_event`: historial inmutable e idempotente por tenant.
- `log_actividad`: auditoría común con actor, empresa, sede, entidad, resultado,
  motivo y correlation ID.
- `access_sync_job` / `access_control_audit`: frontera ya existente con providers.

La frontera del provider recibe únicamente empresa, sede, identificador interno
del socio, decisión permitida/denegada y versión. Los motivos internos y las
notas no se exportan: se reducen a `ACCESS_POLICY_ALLOWED` o
`ACCESS_POLICY_DENIED`. La traducción a una identidad externa opaca pertenece
al adaptador futuro.

Una mutación confirma en una única transacción la política, el evento, la
auditoría común y la intención de integración. Con modo `disabled` no se crea
cola física y queda auditado `DISABLED`; con `shadow` solo se simula. No existe
provider DORLET real.

## Caducidad y recuperación

`cron/access_policy_expire.php`:

- utiliza un lock global de job y locks de lifecycle por tenant;
- revalida la fila bajo `FOR UPDATE`;
- convierte a `ALLOWED` si ya existe membresía válida;
- materializa `DENIED/TEMPORARY_EXPIRED` en otro caso;
- es idempotente mediante una clave derivada de política, versión y caducidad;
- devuelve código distinto de cero en fallo.

Si el job se detiene, `canAccess()` sigue denegando en tiempo real. Al
recuperarse, el job procesa el atraso sin duplicar eventos.

El dashboard calcula por zona `Europe/Madrid` los temporales que caducan hoy y
mañana, conservando UTC en la persistencia. También muestra la ventana de 72
horas y los fallos de la cola, sin presentarlos como aperturas físicas.

## Prohibiciones vigentes

- Sin DORLET, huella, biometría, apertura remota ni conexión a hardware.
- Sin decisiones de salud.
- Sin confiar en IDs o roles enviados por el cliente.
- Sin activar un provider real hasta contrato, sandbox, runbook y autorización
  explícita de una fase futura.
