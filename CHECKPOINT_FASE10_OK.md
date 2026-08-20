# CHECKPOINT FASE 10 OK

Fecha de cierre: 2026-08-20 (Europe/Madrid)

## Alcance cerrado

La Fase 10 deja preparada una frontera genérica y segura para una futura
integración de control de acceso. No conecta DORLET, DASS/DASSnet, IDEMIA,
lectores, controladoras ni biometría, y no incluye ninguna orden para abrir
puertas.

Estado de la interfaz física: **INTERFAZ REAL NO VERIFICADA**.

## Punto de partida

- Rama inicial: `main`.
- Commit y etiqueta inicial: `7b4c5212f8ad46b2a34f05e93cc2eef87abe5613`
  (`v0.8.0-fase9`).
- Copia previa externa:
  `checkpoint_pre_fase10_2026-08-20_130229.zip`.
- SHA-256 de la copia previa:
  `1a8090db3a0f933e4b7dbe1482d86addc33f2f2ed7f71f8c3685d52f56e1e538`.
- Backup MySQL previo a v26: `backup_db_2026-08-20_131659.sql.gz`.
- SHA-256 del backup MySQL:
  `c2f4ad71399e87e360492474274a0c7806d7c48e85e95dc13a300bcfd7a9c52a`.

## Implementación verificada

- `AccessDecision` formal con `PERMITIDO`, `BLOQUEADO` y `REVISAR`.
- Contexto obligatorio de empresa, sede y socio, con motivo estable,
  correlación, versión lógica e idempotencia.
- Puerto `AccessControlProvider` sin operación `openDoor`.
- Provider mock aislado, sin red ni hardware.
- Modos `disabled`, `shadow` y `active`; `active` requiere confirmación doble y
  el cron se niega a ejecutarlo mientras la interfaz real no esté verificada.
- Outbox MySQL persistente con bloqueo transaccional, reintentos exponenciales,
  máximo de intentos y recuperación de trabajos huérfanos.
- Mapeo de identidad externo opaco; no almacena huella ni plantilla biométrica.
- Auditoría y métricas por empresa y sede.
- Autorización central para consulta, administración, sincronización y auditoría.
- Migración no destructiva `migracion_v26.sql` aplicada en desarrollo y pruebas.

## Evidencia de pruebas

- `php tests/run.php`: 4 suites, 36 scripts, 448 comprobaciones, 0 fallos.
- `php pruebas/acceso.php`: 37 correctas, 0 fallos.
- `php pruebas/render.php`: 12 pantallas correctas.
- `php ops/smoke.php http://127.0.0.1:18898`: 11 correctas, 0 fallos,
  usando `public` como document root y `public/router.php`.
- `php ops/migrate.php --status`: v26 aplicada, sin pendientes ni diferencias
  de checksum.
- `php -l`: 143 archivos PHP, 0 errores de sintaxis.
- Cron `disabled`: 0 operaciones.
- Cron `shadow`: 0 operaciones externas y salida correcta.
- Cron `active` confirmado: rechazo seguro con código 2 por interfaz real no
  verificada.

## Límites conscientes

- No se ha hecho escaneo de red, inspección de puertos, captura de tráfico ni
  acceso a configuraciones de terceros.
- No se ha probado hardware real ni comportamiento offline de controladoras.
- No se conoce todavía la API, SDK, base intermediaria, autenticación,
  reintentos, acknowledgements ni capacidades de eventos del proveedor real.
- El modo operativo debe permanecer en `disabled` hasta completar el inventario
  físico y todos los criterios de activación del runbook.

## Criterio de cierre

Fase 10 lista para commit y etiqueta local `v0.9.0-fase10`, sin push. La
integración física permanece deliberadamente pendiente y no puede activarse de
forma accidental con la configuración por defecto.
