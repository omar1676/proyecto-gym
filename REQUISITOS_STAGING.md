# Requisitos de staging para el piloto

Staging debe parecerse a producción sin contener datos reales no autorizados ni
compartir secretos, base, sesiones, uploads, correo o backups con producción.

| Control | Evidencia exigida | Estado |
|---|---|---|
| Release exacta y commit identificables | `VERSION`, commit y migración | VERIFICADO LOCAL: 0.9.0-fase10, checkpoint `0af5163`, v26 |
| `APP_ENV` y base exclusivos | Nombre DB test/staging y bloqueo destructivo | VERIFICADO LOCAL: `gimnasio_staging_f13_local`; confirmación explícita |
| Configuración separada | `.env` fuera de Git, secretos propios | VERIFICADO LOCAL mediante `.env.staging.example`; PENDIENTE en servidor |
| HTTPS/cookies/cabeceras | Prueba desde URL real de staging | NO VERIFICADO |
| Document root `public/` | `.env`, app, tests, backups y logs inaccesibles | VERIFICADO LOCAL; PENDIENTE en servidor real |
| Datos | Sintéticos o export autorizado/pseudonimizado según finalidad | VERIFICADO LOCAL: escenario F13 exclusivamente sintético |
| Multiempresa | Tenant Cleto staging + tenant sintético aislado | PENDIENTE |
| Roles/sedes | Cuentas nominales de dirección/admin/recepción | VERIFICADO LOCAL con identidades ficticias; no crear reales sin autorización |
| Migraciones | v26, checksums y cero pendientes | VERIFICADO LOCAL y sobre restore |
| Regresión | Suite completa de la release | VERIFICADO LOCAL: 470/470; HTTP 37/37; render 12/12; smoke 11/11; lint 148/148 |
| Smoke | Health, login, DB, rutas seguras | VERIFICADO LOCAL sobre restore; PENDIENTE real |
| Backups | Destino externo y restore de staging | Restore VERIFICADO LOCAL; copia externa PENDIENTE |
| SMTP | Buzón sink/sintético; nunca socios reales | NO VERIFICADO |
| Cron/monitor/logs | Tareas instaladas, alertas y rotación | Salvaguardas VERIFICADAS LOCAL; instalación/alerta real PENDIENTE |
| Acceso físico | `ACCESS_CONTROL_MODE=disabled`; sin credenciales DORLET | VERIFICADO LOCAL; forzado por configuración |
| Fallback | Sistema actual disponible y rollback ensayado | PENDIENTE |

No se promueve a operación paralela por “funcionar en local”. Todos los P0 del
checklist deben tener evidencia del servidor de staging o quedar NO-GO.
