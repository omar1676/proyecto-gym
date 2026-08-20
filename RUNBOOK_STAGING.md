# Runbook de staging

Este runbook empieza únicamente cuando existe infraestructura y autorización.
Los valores entre `<...>` son datos externos pendientes, nunca credenciales que
deban escribirse en este documento.

## 1. Registro previo

- Cambio autorizado por: `<responsable>`.
- Responsable técnico: `<persona>`.
- Responsable STOP/rollback: `<persona>`.
- Ventana: `<inicio-fin>`.
- Commit: `<sha completo>`.
- URL: `https://staging.<dominio>`.

Si falta un responsable o una credencial se detiene el procedimiento.

## 2. Servidor y permisos

1. Crear usuario de servicio sin root y sin login interactivo innecesario.
2. Crear `/var/www/gimnasio-staging/releases`, `current` y `shared`.
3. Dar escritura solo en uploads, logs, sesiones, imports y backup temporal.
4. Mantener código y configuración no secreta en solo lectura; nunca `777`.
5. Configurar document root en `current/public`.

## 3. Release limpia

Construir desde un commit inmutable. Antes de transferir, comprobar que no
existen `.git`, `.env`, `tests`, `pruebas`, `storage`, `copias`, `backups`,
`releases`, `instalar.php`, dumps, logs, claves o ZIP históricos. Una sola
coincidencia detiene el despliegue.

La release contiene únicamente runtime/documentación operativa necesaria. El
`.env` se enlaza desde `shared` por canal seguro.

## 4. DNS y TLS

1. Crear registro DNS, anotar tipo, TTL, IP esperada y hora.
2. Verificar resolución desde al menos una red externa.
3. Emitir certificado para el hostname exacto y verificar cadena/expiración.
4. Verificar HTTP→HTTPS, cookies Secure, CSP, headers y ausencia de contenido
   mixto. Restringir el acceso directo que pueda falsificar cabeceras del proxy.

## 5. Base y aplicación

1. Crear DB y usuario exclusivos, sin privilegios globales ni root.
2. Completar `.env` staging desde `.env.staging.example`.
3. Mantener `ACCESS_CONTROL_MODE=disabled` y allowlist SMTP vacía inicialmente.
4. Ejecutar:

```bash
php ops/setup_directories.php
php ops/preflight.php
php ops/migrate.php --confirm-staging
php ops/status.php
```

Exigir v26, `pending=[]` y `checksum_mismatch=[]`.

## 6. Backup, cron y alertas

Configurar destino externo y cifrado antes de instalar cron:

```cron
0 */6 * * * php /var/www/gimnasio-staging/current/cron/copia_seguridad.php
20 2 * * * php /var/www/gimnasio-staging/current/cron/copia_archivos.php
40 2 * * * php /var/www/gimnasio-staging/current/cron/mantenimiento.php
0 6 * * * php /var/www/gimnasio-staging/current/cron/tareas.php
*/5 * * * * php /var/www/gimnasio-staging/current/cron/monitor.php
```

En staging `tareas.php` debe declarar simulación forzada. `control_acceso.php`
debe responder `disabled`. Probar una alerta fallida de forma segura y confirmar
su recepción humana.

## 7. Restore y validación externa

Descargar backups desde el destino externo a un entorno limpio, verificar hash,
restaurar DB/archivos y ejecutar `verify_restore`, migrate status y smoke.
Medir desde el inicio de descarga hasta el último smoke correcto.

Desde fuera del servidor ejecutar `/health`, smoke y el quick check no
destructivo: tenant A/B, CSRF, IDOR básico, archivos sensibles, errores y
directory listing.

## 8. Cierre y rollback

Revisar logs web/PHP/SECURITY y alertas durante 30 minutos. Registrar resultado,
commit y esquema. Si falla un control obligatorio, mantener sistema actual como
fuente oficial, desactivar staging público y volver a la release anterior. Una
restauración de DB requiere decisión explícita por posible pérdida desde el
último backup.
