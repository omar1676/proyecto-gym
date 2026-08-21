# Roadmap de aplicación móvil

## Decisión

No se construye app móvil en esta fase. La futura arquitectura será:

```text
MOBILE -> API GIMNERA -> SERVICES -> DB / AUDITORÍA / ACCESS CONTROL
```

La API reutilizará TenantContext, Authorization, validación, idempotencia,
transacciones y auditoría. La app nunca accederá directamente a MariaDB, DORLET
o puertas, ni almacenará o enrolará biometría.

## Alcance candidato por etapas

1. Sesión segura, cierre/remoto, empresa/sede y permisos.
2. Búsqueda y ficha mínima de socio, membresía y deuda según rol.
3. Auditoría: último acceso confirmado y quién modificó qué.
4. Ventas, caja y stock solo tras diseñar confirmaciones e idempotencia móvil.
5. Incidencias y notificaciones sin datos sensibles en pantalla bloqueada.

Antes de desarrollar hacen falta contrato API versionado, autenticación de
dispositivo, rate limiting, revocación, threat model, política offline y pruebas
de aislamiento por empresa/sede.

## PWA como primer paso

Viabilidad: **MEDIA**.

- A favor: viewport ya presente, diseño Tailwind con breakpoints, tablas con
  scroll horizontal y formularios etiquetados en las pantallas principales.
- Brechas: no hay manifest ni service worker; navegación y tablas densas deben
  probarse con touch; el panel no tiene estrategia de instalación/standalone.
- Riesgo principal: un service worker no debe cachear HTML autenticado, fichas,
  pagos, auditoría ni respuestas de API. Solo serían cacheables assets
  versionados y una pantalla offline neutra.
- Sesión/logout: al salir se deben purgar cachés de aplicación; no se conservarán
  tokens en almacenamiento accesible a JavaScript.

Primera validación futura: auditoría responsive real y prototipo instalable sin
modo offline de datos. No se ha implementado PWA, manifest ni service worker.
