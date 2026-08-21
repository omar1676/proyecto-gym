# Disaster recovery de Gimnera

## Objetivo

Recuperar la alpha desde Cloudflare R2 aunque el VPS original desaparezca. RTO
objetivo: 4 horas. Los secretos necesarios no están en Git, en la release ni en
el bucket.

## Material custodiado fuera del VPS

- acceso a Cloudflare y capacidad de emitir un token limitado al bucket;
- contraseña y salt de `rclone crypt`;
- credenciales para la base del servidor nuevo;
- clave SSH administrativa;
- release limpia identificada.

Nunca copiar estos valores a este documento, tickets, logs o shell history.

## Recuperación desde cero

1. Crear un Ubuntu compatible y endurecer SSH/firewall.
2. Instalar Nginx, PHP 8.3, MariaDB 10.11 y rclone.
3. Desplegar la release y crear un `.env` nuevo fuera de Git.
4. Reconstruir `/etc/gimnera/rclone.conf` con `root:root 0600`.
5. Descargar un set desde el remote cifrado a una ruta limpia.
6. Validar sidecars SHA-256 y manifiestos.
7. Crear `gimnasio_restore_<UTC>` con permisos solo sobre esa base.
8. Restaurar con `ops/restore.php --existing-empty`.
9. Ejecutar `ops/verify_restore.php` y `ops/migrate.php --status`.
10. Levantar un vhost aislado y ejecutar health y los 11 smoke tests.
11. Comparar tablas, migraciones, usuarios, sedes, auditoría y uploads.
12. Registrar RTO y obtener aprobación antes de activar el restore.

## Incidentes

- Sin la clave `crypt` no existe bypass: escalar al custodio externo.
- Si el token se compromete, revocarlo; no rotar `crypt` hasta recuperar o
  revalidar todas las copias antiguas necesarias.
- Si un hash falla, no importar; descargar de nuevo o elegir otro set.
- Si hay migraciones pendientes/mismatch, detener; nunca usar `--fresh`.
- Si solo queda un set válido, no aplicar retención ni limpieza.

## Evidencia

El 21/08/2026 se restauró un set descargado desde R2: 28 tablas, 27 migraciones
exactas, 8 archivos verificados, health OK y smoke 11/11. RTO total observado:
2,231 s para el volumen sintético actual.
