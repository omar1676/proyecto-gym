# Retention Engine V1

## Alcance

Retention V1 detecta una caída significativa de asistencia respecto al patrón
individual del socio. El resultado se presenta como “podría necesitar
atención”; no predice una baja. No envía mensajes y no conoce DORLET, FitCloud,
lectores, huellas ni biometría.

## Definición de visita

Una visita es un día local del tenant con uno o más eventos `ATTENDANCE`. Varias
lecturas el mismo día cuentan una vez. El evento conserva instante UTC y fecha
local calculada por el servidor con el timezone del tenant. Las fuentes
permitidas son `MANUAL`, `IMPORT`, `ACCESS_PROVIDER` y `API`; F24 no expone una
ruta web de ingesta ni implementa adaptadores.

## Reglas V1

- Evaluación: fecha local, inclusiva.
- Ventana reciente: 14 días.
- Baseline: los 56 días inmediatamente anteriores.
- Histórico mínimo: 28 días, 4 visitas y actividad en 4 semanas distintas.
- Frecuencia habitual mínima: 0,75 visitas/semana.
- `ATTENTION`: caída igual o superior al 50 %.
- `HIGH_ATTENTION`: cero visitas recientes y caída igual o superior al 75 %.
- El resto es `NORMAL` o `INSUFFICIENT_DATA`.

Los umbrales y plantillas están en `retention_config`, separados por empresa.
Las familias `GYM`, `BOXEO` y `TATAMI` se resuelven desde
`retention_activity_mapping`; si existen varias disciplinas o no hay evidencia
suficiente se usa `GENERAL`. Nunca se infiere actividad por precio.

## Elegibilidad y lifecycle

Solo se evalúan socios activos, no anonimizados y con una membresía vigente en
la fecha. Una membresía futura o finalizada no entra. Un trial nuevo queda sin
histórico suficiente y un trial terminado no entra. Tenants `CANCELLED`,
`CONFIGURING` o con `estado=inactiva` no pueden ingerir eventos ni ejecutar el
job normal.

## Job

Ejecución manual segura:

```bash
php cron/retention.php --company=123
php cron/retention.php --company=123 --date=2026-08-20
```

El job no se ejecuta al cargar páginas, no envía comunicaciones y no se activa
automáticamente en cron durante F24. Un lock por tenant evita dos cálculos
simultáneos; la unicidad empresa/fecha/versión hace idempotente el reintento.

## Permisos y privacidad

`direccion` puede ver todas sus sedes; `admin` únicamente la suya. Ambos pueden
revisar, descartar, posponer o marcar un contacto manual. Recepción y socio no
reciben acceso en V1. La bandeja muestra nombre, sede, familia, frecuencias,
última asistencia, explicación, nivel y preview. No muestra DNI, IBAN ni datos
económicos. El preview dice expresamente “NO ENVIADO”.

## Auditoría y medición

Se registran `RETENTION_DETECTED`, `RETENTION_REVIEWED`,
`RETENTION_DISMISSED`, `RETENTION_POSTPONED`,
`RETENTION_CONTACTED_MANUAL` y `RETENTION_RETURNED`, sin contenido del mensaje
ni PII innecesaria. Las métricas son recuentos operativos; no atribuyen ingresos
ni dinero recuperado.

## Rollback

La migración v31 es forward-only. F23 declara como máximo schema v30 y debe
rechazar un runtime con v31. El rollback de código a F23 no es compatible tras
aplicar v31; se requiere restaurar el backup predeploy o una release que declare
compatibilidad con v31. Nunca ejecutar el migrador antiguo contra schema v31.
