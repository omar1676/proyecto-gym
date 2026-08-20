# ADR — Frontera de provider de control de acceso

- Estado: **ACEPTADO PARA MOCK Y SHADOW**.
- Interfaz física: **NO VERIFICADA / NO IMPLEMENTADA**.
- Fecha: 20/08/2026.

## Revisión Fase 11

La investigación oficial confirma que DORLET comercializa un **SDK
Integración módulo de accesos**, referencia `D9110400`, pero no se dispone de
su contrato técnico, licencia, compatibilidad ni documentación de
autenticación. Tampoco se ha verificado que Cleto Reyes use DASSnet ni su
versión.

Por tanto, no se crea `DorletAccessControlProvider`: un nombre de producto no
es un contrato ejecutable. La decisión, evidencia y preguntas pendientes están
en `DESCUBRIMIENTO_DORLET_FASE11.md` y `SOLICITUD_TECNICA_DORLET.md`.

## Contexto

El SaaS conoce socios, empresa, sede, membresías y política comercial. No debe
conocer protocolos propietarios, comandos de apertura, controladoras, lectores
ni plantillas biométricas. `AccessEligibilityService` ya calcula una decisión
lógica explicable; Fase 10 formaliza la frontera que podrá consumirla.

## Decisión

```text
Socio + estado de negocio
        ↓
AccessEligibilityService
        ↓
AccessDecision (PERMITIDO/BLOQUEADO/REVISAR)
        ↓
access_sync_job (outbox MySQL)
        ↓
AccessControlProvider
        ↓
MockAccessControlProvider              Proveedor real: NO IMPLEMENTADO
```

`AccessDecision` contiene exclusivamente empresa, sede, socio interno, estado,
`reason_code`, instante, versión lógica y `correlation_id`. No contiene deuda,
IBAN, contraseña, token, huella ni identificador de controladora.

El contrato `AccessControlProvider` expone:

- `healthCheck()`;
- `findCredential()`;
- `syncAccessDecision()`;
- `getLastEvents()`.

No existe ni se permite `openDoor()` en esta fase.

## Modos

- `disabled`: valor por defecto; solo puede auditar una evaluación solicitada y
  no crea trabajo de sincronización.
- `shadow`: registra lo que se sincronizaría y marca el trabajo como simulado;
  nunca invoca al provider.
- `active`: requiere `ACCESS_CONTROL_MODE=active` y
  `ACCESS_CONTROL_ACTIVE_CONFIRM=true`. Solo es ejercitable con el mock en
  pruebas. El cron se niega a ejecutarlo mientras no exista un provider real
  autorizado y documentado.

Un valor desconocido o un `active` sin confirmación se degrada a `disabled`.

## Persistencia e idempotencia

MySQL es suficiente para el piloto:

- `access_identity_map`: vínculo opaco socio ↔ identidad externa;
- `access_sync_job`: outbox `PENDING/PROCESSING/SYNCED/FAILED/RETRY`;
- `access_control_audit`: evidencia estructurada sin secretos.

La clave idempotente deriva de provider, tenant, sede, socio, estado,
`reason_code` y versión lógica. Una restricción única en base impide duplicados.
Los reintentos son limitados, con backoff exponencial y recuperación de workers
interrumpidos.

## Eventos

Los eventos normalizados futuros serán `ACCESS_GRANTED`, `ACCESS_DENIED`,
`ACCESS_REVIEW` y `PROVIDER_ERROR`. Deben distinguir siempre:

- decisión del SaaS: «el SaaS autorizó»;
- resultado de sincronización: «el provider aceptó la actualización»;
- evento físico: «el hardware registró un paso».

Los dos primeros nunca demuestran que una persona haya entrado.

## Fail-safe

Un timeout, error, dato desconocido o estado `REVISAR` nunca se convierte
silenciosamente en una autorización física. La conducta offline de puertas y
controladoras **REQUIERE POLÍTICA OPERATIVA Y DOCUMENTACIÓN DEL PROVEEDOR**.

## Consecuencias

El dominio queda desacoplado del fabricante y puede probarse sin hardware. La
sincronización es eventual y necesita observación operativa. No se podrá crear
un adaptador físico hasta cumplir todos los criterios de
`CONTROL_ACCESO_RUNBOOK.md`.
