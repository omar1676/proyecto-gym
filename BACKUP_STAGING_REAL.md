# Backup real de staging

## Estado

- Backup local consistente: **VERIFICADO EN EL VPS** el 21/08/2026 14:03 UTC
  sobre la release `ecb39850cd3a223f21610302410f4f4cad54f7e8`.
- Backup físicamente externo: **PENDIENTE**. No hay proveedor, bucket ni credenciales autorizadas.
- Cifrado externo: **PREPARADO, NO CONFIGURADO**.
- Datos reales: **NO-GO** mientras falten copia externa y restore desde ella.

Evidencia del set local: DB SHA-256
`cd7474e9452019bd3a32764534aab11746c5c8490de2e7476c80850b864220e8`,
archivos `f68bd38819e94e29e8a5a6320d5bd514529e26ca6b73aa07f4e37178abfb5902`
y manifiesto global
`5a613a602ab2a4b50a8580bedcea766869e85c029086be12bb0e5d771ba10058`.
Todos quedaron `0640`; los timers DB/archivos están habilitados y su ejecución
manual terminó con `Result=success`.

## Contenido y consistencia

`cron/copia_seguridad.php` crea un dump MariaDB con
`--single-transaction`; la alternativa PHP usa `START TRANSACTION WITH
CONSISTENT SNAPSHOT`. `cron/copia_archivos.php` incluye uploads, versión y
configuración de ejemplo, nunca `.env`, sesiones o logs. Cada artefacto genera:

- nombre con timestamp UTC;
- SHA-256 en `.sha256`;
- manifiesto JSON con versión, release, migración, tamaño y hash;
- log de éxito o error sin credenciales.

`php cron/copia_global.php` ejecuta ambos y crea `backup_set_*.json`, que enlaza
los dos artefactos verificados. El almacenamiento local es
`/var/www/gimnasio/shared/backups/local`, fuera del document root.

## Copia externa propuesta

Preferencia: almacenamiento de objetos S3-compatible en una cuenta distinta
del VPS. Alternativa: servidor SFTP independiente. Una carpeta del mismo disco
no cuenta como externa.

La preparación usa `rclone` con un remote `crypt` superpuesto al proveedor. Las
credenciales y parámetros viven exclusivamente en `/etc/gimnera/rclone.conf`,
fuera de Git, con `root:www-data 0640`. Variables:

```dotenv
BACKUP_EXTERNAL_ENABLED=false
BACKUP_EXTERNAL_REMOTE=
BACKUP_EXTERNAL_CONFIG=/etc/gimnera/rclone.conf
BACKUP_EXTERNAL_ENCRYPTED=false
BACKUP_EXTERNAL_VERIFY_DIR=/var/www/gimnasio/shared/backups/verify
```

Solo después de elegir proveedor, crear el bucket, limitar la cuenta a ese
prefijo y configurar el remote cifrado se cambia a `true`. Entonces:

```bash
sudo -u www-data php ops/backup_external.php \
  --set=/var/www/gimnasio/shared/backups/local/backup_set_<UTC>.json
```

El comando sube, vuelve a descargar cada elemento y compara SHA-256. Sin todas
las precondiciones se detiene antes de transferir.

## Cifrado y custodia

No se ha generado ninguna clave. Al configurar `rclone crypt` se crearán una
contraseña y salt aleatorios. Una copia recuperable de ambos debe custodiarse
en el gestor de secretos del propietario y una segunda copia de emergencia
offline; nunca dentro del bucket ni del repositorio. El fichero automatizado
del VPS contiene material sensible y debe rotarse si el servidor se compromete.
Una rotación requiere mantener la clave anterior hasta revalidar restore.

## Retención

La rotación local existente conserva 7 puntos diarios, 4 semanales y 6
mensuales. Antes de borrar exige que el nuevo artefacto haya sido creado y
verificado. Para el almacenamiento de objetos se configurará una política de
ciclo de vida equivalente, con versionado/inmutabilidad cuando el proveedor lo
permita. No se activará borrado remoto desde PHP sin validar primero esa
política y un restore.

Frecuencia objetivo: DB cada 6 horas y archivos cada 24 horas. RPO objetivo:
6 h para DB y 24 h para uploads. Hasta observar timers y objeto externo, el RPO
ante pérdida total del VPS sigue **PENDIENTE**.

## Actualización Fase 17 — estado vigente (21/08/2026)

Esta sección sustituye el estado «PENDIENTE» anterior sin borrar la evidencia
histórica del documento.

- Proveedor: Cloudflare R2, almacenamiento de objetos independiente del VPS.
- Jurisdicción: Unión Europea.
- Credencial: token limitado al bucket con lectura/escritura; no es una clave
  maestra.
- Transporte: `rclone` 1.60.1 sobre S3 compatible.
- Cifrado: remote `crypt`, contenido y nombres cifrados.
- Configuración: `/etc/gimnera/rclone.conf`, `root:root 0600`, fuera de Git.
- Las claves `crypt` se custodian fuera del VPS; no aparecen aquí.

El set usado en disaster restore fue
`backup_set_2026-08-21_162153Z.json`, SHA-256
`6e43cdf7ffe06c3353ede803341accac6abc5fb8417b9ecd08172b17bd1b38dc`.
R2 devolvió sus 9 artefactos y todos coincidieron tras descarga. La prueba del
servicio programado verificó después `backup_set_2026-08-21_164928Z.json` sin
eliminar los backups DB/archivos locales existentes.

`gimnera-backup-external.timer` está habilitado y activo a las 00:20, 02:35,
06:20, 12:20 y 18:20 UTC, con hasta 5 minutos de retraso aleatorio. El servicio
corre como `www-data`; systemd le entrega la configuración sensible de forma
efímera mediante `LoadCredential`. Solo actualiza el monitor después de subir,
volver a descargar y comparar SHA-256. Un fallo externo conserva la copia local.

RPO configurado aproximado: 6 h 20 min para DB y 24 h 20 min para archivos.
La ejecución manual real del mismo servicio pasó; observar un ciclo natural
completo de 24 h continúa **PENDIENTE**.

Retención externa objetivo: 7 diarios, 4 semanales y 6 mensuales. Todavía no
hay borrado automático en R2: primero debe observarse el calendario con varias
copias. El pendiente implica crecimiento de coste, no eliminación anticipada.
