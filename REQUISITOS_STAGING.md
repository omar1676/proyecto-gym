# Requisitos de staging para el piloto

Staging debe parecerse a producción sin contener datos reales no autorizados ni
compartir secretos, base, sesiones, uploads, correo o backups con producción.

| Control | Evidencia exigida | Estado |
|---|---|---|
| Release exacta y commit identificables | `VERSION`, commit y migración | PENDIENTE |
| `APP_ENV` y base exclusivos | Nombre DB test/staging y bloqueo destructivo | PENDIENTE |
| Configuración separada | `.env` fuera de Git, secretos propios | PENDIENTE |
| HTTPS/cookies/cabeceras | Prueba desde URL real de staging | NO VERIFICADO |
| Document root `public/` | `.env`, app, tests, backups y logs inaccesibles | PENDIENTE |
| Datos | Sintéticos o export autorizado/pseudonimizado según finalidad | PENDIENTE |
| Multiempresa | Tenant Cleto staging + tenant sintético aislado | PENDIENTE |
| Roles/sedes | Cuentas nominales de dirección/admin/recepción | PENDIENTE |
| Migraciones | v26, checksums y cero pendientes | PENDIENTE |
| Regresión | Suite completa de la release | PENDIENTE |
| Smoke | Health, login, DB, rutas seguras | PENDIENTE |
| Backups | Destino externo y restore de staging | PENDIENTE |
| SMTP | Buzón sink/sintético; nunca socios reales | NO VERIFICADO |
| Cron/monitor/logs | Tareas instaladas, alertas y rotación | PENDIENTE |
| Acceso físico | `ACCESS_CONTROL_MODE=disabled`; sin credenciales DORLET | PENDIENTE |
| Fallback | Sistema actual disponible y rollback ensayado | PENDIENTE |

No se promueve a operación paralela por “funcionar en local”. Todos los P0 del
checklist deben tener evidencia del servidor de staging o quedar NO-GO.

