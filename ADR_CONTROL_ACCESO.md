# ADR: abstracción futura de control de acceso

- Estado: **PROPUESTO — SOLO DISEÑO**.
- No hay SDK, red, hardware, huellas ni llamadas DORLET implementadas.

## Contexto

El dominio del gimnasio no debe depender de comandos, IDs o estados propios de
un fabricante. En el futuro pueden coexistir DORLET, otro proveedor, QR, NFC o
credencial móvil.

## Decisión

Usar una arquitectura de puerto/adaptador. El núcleo emite intenciones de
acceso y consume estados/eventos normalizados. Cada proveedor implementa el
puerto fuera del dominio.

Interfaz conceptual:

```text
AccessControlProvider
  createIdentity(localIdentity, policy)
  updateIdentity(externalIdentity, changes)
  enableAccess(externalIdentity, policy)
  blockAccess(externalIdentity, reason)
  getStatus(externalIdentity)
  pullEvents(cursor)
  syncPermissions(externalIdentity, policy)
```

No se crea esta interfaz en PHP todavía para evitar código muerto hasta conocer
el contrato real del proveedor.

## Modelo futuro mínimo

- `access_provider`: proveedor habilitado por empresa/sede y configuración no secreta.
- `access_identity_map`: usuario local ↔ identidad externa por proveedor.
- `access_policy`: zonas/horarios derivados de membresía y reglas aprobadas.
- `access_sync_job`: outbox idempotente con estado, intentos y próximo reintento.
- `access_event`: evento normalizado, proveedor, sede, identidad, dirección,
  instante, resultado e ID externo único.
- Secretos: gestor externo o variables de entorno; nunca tablas legibles ni Git.

Todas las tablas llevarán `empresa_id` o una relación inequívoca a sede. Un
evento recibido debe resolver proveedor + empresa + sede antes de asociarse a
una persona. Un identificador externo jamás decide el tenant por sí solo.

## Consistencia y fallos

- Patrón outbox: confirmar primero el cambio local y sincronizar después.
- Clave idempotente por operación/proveedor.
- Reintentos con backoff y estado visible para soporte.
- Un fallo del proveedor no revierte ventas ni membresías ya confirmadas.
- El bloqueo urgente requiere prioridad, auditoría y confirmación posterior.
- Eventos duplicados se rechazan mediante ID externo único.
- La pérdida de conexión no debe abrir puertas por defecto; el comportamiento
  offline dependerá del hardware y de una decisión de seguridad específica.

## Autorización y privacidad

- Solo backend y tareas técnicas llaman al adaptador.
- `TenantContext` y `Authorization` se comprueban antes de crear intenciones.
- Auditoría: actor, empresa, sede, persona, acción, resultado y correlación.
- No guardar plantillas biométricas en el SaaS.
- Minimizar histórico y definir retención antes de almacenar eventos reales.

## Consecuencias

Ventaja: sustituir proveedor no cambia socios, membresías o ventas. Coste: hace
falta un mapeo explícito y gestionar sincronización eventual. La decisión se
revisará cuando exista documentación contractual y técnica autorizada.
