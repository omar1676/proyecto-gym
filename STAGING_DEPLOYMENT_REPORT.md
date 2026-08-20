# Informe de despliegue de staging — Fase 13

Fecha: 20/08/2026.

## Resultado ejecutivo

- **VERIFICADO LOCAL:** instalación independiente con `APP_ENV=staging`, base
  `gimnasio_staging_f13_local`, esquema v26, datos sintéticos, logs, sesiones,
  importaciones y backups en rutas separadas.
- **VERIFICADO LOCAL:** `ACCESS_CONTROL_MODE` queda forzado a `disabled`, el
  correo queda bloqueado sin SMTP y allowlist exacta, el cron económico solo
  simula y migrar/desplegar exige `--confirm-staging`.
- **NO VERIFICADO:** servidor, proveedor, subdominio, TLS y SMTP reales.
- **PENDIENTE:** copia físicamente externa, release sin instalador, uploads
  independientes en el servidor y alertas con destinatario real.

La prueba local no se etiqueta como *staging real*.

## Evidencia local

| Control | Resultado | Evidencia |
|---|---|---|
| Release | VERIFICADO LOCAL | `VERSION=0.9.0-fase10`; checkpoint inicial `0af5163` |
| Base separada | VERIFICADO LOCAL | Nombre con `staging`, distinta de desarrollo y test |
| Migraciones | VERIFICADO LOCAL | v26; pendientes 0; checksums divergentes 0 |
| Datos | VERIFICADO LOCAL | Empresa, 2 sedes, roles y operaciones exclusivamente sintéticos |
| Almacenamientos | VERIFICADO LOCAL | Rutas distintas para logs/backups/imports/sessions |
| Document root | VERIFICADO LOCAL | `/public`; rutas sensibles devolvieron 404 |
| HTTPS simulado detrás de proxy | VERIFICADO LOCAL | HTTP 301; cookie Secure/HttpOnly/SameSite y cabeceras presentes |
| TLS real | NO VERIFICADO | No hay URL ni certificado accesible |
| Backup/restore | VERIFICADO LOCAL | DB + archivos; restore independiente y verificado |
| Backup externo | PENDIENTE | El monitor informa `external_backup=false` |
| SMTP | NO VERIFICADO | No hay credenciales; staging bloquea todos los destinatarios |
| Preflight | NO APTO | Falla correctamente por copia externa e instalador en el árbol local |

## Secuencia reproducible para un servidor autorizado

1. Crear usuario y base exclusivos de staging; no reutilizar secretos.
2. Desplegar una release cuyo document root sea exclusivamente `public/`.
3. Crear `.env` fuera de Git a partir de `.env.staging.example`.
4. Separar directorios persistentes de uploads, logs, sesiones, importaciones y
   backups respecto de producción; el árbol de staging debe ser distinto.
5. Configurar TLS y bloquear el acceso directo al backend que confía en la
   cabecera del proxy.
6. Configurar un destino externo físicamente separado y un canal humano para
   alertas.
7. Ejecutar `php ops/setup_directories.php` y `php ops/preflight.php`.
8. Migrar con backup previo y `php ops/migrate.php --confirm-staging`.
9. Cargar sintéticos con una clave efímera y
   `php ops/seed_staging.php --confirm-synthetic` solo sobre una base nueva.
10. Ejecutar backup, restore limpio, smoke, regresión y comprobación de
    seguridad; retirar `instalar.php` de la release expuesta.

No se ha realizado ninguno de estos pasos sobre infraestructura remota.
