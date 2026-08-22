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
php ops/schema_gate.php --mode=migrate
php ops/migrate.php --confirm-staging
php ops/status.php
```

Exigir v28, `pending=[]`, `checksum_mismatch=[]` y compatibilidad declarada en
`SCHEMA_COMPATIBILITY.json`.

## 6. Backup, cron y alertas

Configurar destino externo y cifrado antes de considerar datos reales. Para la
alpha controlada se instalan las unidades versionadas de `ops/systemd/`:

```bash
sudo cp ops/systemd/gimnera-* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now gimnera-backup-db.timer gimnera-backup-files.timer
sudo systemctl enable --now gimnera-maintenance.timer gimnera-monitor.timer
```

Las tres unidades de backup comparten
`/var/www/gimnasio/shared/.backup-operation.lock`: una ejecución concurrente
espera hasta 30 minutos en vez de mezclar artefactos de dos sets. La retención
R2 se observa primero con `php ops/backup_external_retention.php`; solo se
aplica en staging con `--apply --confirm-staging` y nunca elimina un set cuya
fecha o estructura no puedan verificarse.

No se habilitan `tareas.php`, remesas, correo a socios ni `control_acceso.php`.
Este último debe seguir respondiendo `disabled`. Probar una alerta fallida de
forma segura solo cuando exista un canal humano autorizado.

## 7. Restore y validación externa

Descargar backups desde el destino externo a un entorno limpio, verificar hash,
restaurar DB/archivos y ejecutar `verify_restore`, migrate status y smoke.
Medir desde el inicio de descarga hasta el último smoke correcto.

El archivo de ficheros restaura las fotos personales bajo `private/fotos/`.
Después de verificar su manifiesto y hashes, esa carpeta se copia al
`PRIVATE_PHOTO_DIR` compartido, nunca a `public/assets/fotos`.

Desde fuera del servidor ejecutar `/health`, smoke y el quick check no
destructivo: tenant A/B, CSRF, IDOR básico, archivos sensibles, errores y
directory listing.

## 8. Cierre y rollback

Revisar logs web/PHP/SECURITY y alertas durante 30 minutos. Registrar resultado,
commit y esquema. Si falla un control obligatorio, mantener sistema actual como
fuente oficial, desactivar staging público y volver a la release anterior. Una
restauración de DB requiere decisión explícita por posible pérdida desde el
último backup.

### Rollback forward-only

`ROLLBACK DE CÓDIGO` no equivale a `ROLLBACK DE ESQUEMA`.

1. Ejecutar en la release candidata a rollback `php ops/schema_gate.php
   --mode=runtime --release=/ruta/release-anterior` contra el esquema actual.
2. Si acepta, cambiar `current` atómicamente, comprobar health/smoke y no
   ejecutar el migrador antiguo.
3. Si rechaza, STOP: no activar la release anterior. Volver a la release nueva
   o restaurar en una DB independiente el backup previo.
4. Nunca ejecutar un migrador antiguo ante una migración desconocida. El gate
   `--mode=migrate` debe rechazarla explícitamente.
5. Registrar releases, esquema, responsable, motivo y resultado.
