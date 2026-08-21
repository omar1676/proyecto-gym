# Monitorización de staging

## Comprobaciones

`cron/monitor.php` devuelve JSON y código `0` (OK), `1` (WARNING) o `2`
(CRITICAL). Comprueba:

| Control | WARNING | CRITICAL |
|---|---|---|
| HTTPS | — | health no devuelve 200/ok |
| TLS | menos de 30 días | menos de 14 días o cadena inválida |
| Nginx/PHP-FPM/MariaDB/cron | — | servicio inactivo |
| Disco | menos del umbral configurado | menos del 5 % |
| Memoria | menos del 15 % disponible | menos del 8 % |
| Backup DB | más de 8 h | más de 12 h, hash/manifiesto inválido o ausente |
| Backup archivos | más de 36 h | más de 72 h, hash/manifiesto inválido o ausente |
| Backup externo | más de 26 h | más de 36 h, no cifrado/no configurado/no verificado |
| Errores | supera umbral | supera dos veces el umbral |
| Trabajos | algún fallo en 24 h | — |
| Migraciones | — | pendientes o checksum mismatch |
| Timers | — | deshabilitado, inactivo o último resultado fallido |

El estado se guarda fuera del document root. Una misma incidencia solo vuelve a
pedir notificación al cambiar o tras el cooldown; la recuperación también se
registra. No se guarda el destinatario en la salida.

Estado verificado el 21/08/2026: servicios, HTTPS, TLS (89 días), disco, memoria,
backup local, cuatro timers, errores, trabajos y migraciones en OK. El backup
externo aparece CRITICAL por diseño; la segunda ejecución quedó suprimida por el
cooldown. No existe todavía canal humano, por lo que una alerta recibida sigue
NO VERIFICADA.

## Automatización prevista

Las unidades versionadas en `ops/systemd/` ejecutan exclusivamente:

- DB: 00:05, 06:05, 12:05 y 18:05 UTC;
- archivos: 02:20 UTC;
- mantenimiento técnico: 02:40 UTC;
- monitor: cada 5 minutos.

No se instala `cron/tareas.php`, remesas, correos a socios ni control de acceso.
Los servicios corren como `www-data`, con `ProtectSystem=strict`, sin privilegios
nuevos y escritura limitada a `shared`.

## Alertas

Canal real: **PENDIENTE**. Faltan destinatario autorizado y proveedor SMTP o
canal de monitorización. `MONITOR_ALERT_EMAIL` permanece vacío. Cuando se elija,
se probará caída controlada, recuperación y cooldown sin usar datos personales.
Un CRITICAL sin canal sigue quedando en log/journal, pero no se considerará una
alerta humana verificada.

## Actualización Fase 17 — estado vigente

El monitor devuelve `OK`. `backup_external=OK` solo se escribe después de
validar el artefacto local, transferirlo con `rclone crypt`, descargar cada
objeto de R2 y comparar SHA-256. La evidencia queda fuera del document root con
`www-data:www-data 0640`; si no puede actualizarse, la unidad falla.

El quinto timer, `gimnera-backup-external`, está habilitado y activo. Ejecuta a
las 00:20, 02:35, 06:20, 12:20 y 18:20 UTC. El monitor marca WARNING a las 26 h
y CRITICAL a las 36 h desde la última verificación externa.

Alertas humanas siguen **PENDIENTE / NO VERIFICADO**: SMTP, allowlist y
`MONITOR_ALERT_EMAIL` están vacíos. Fingerprint y cooldown permanecen a 60
minutos, pero aún falta recibir una prueba `[GIMNERA STAGING TEST]` en un buzón
técnico autorizado.
