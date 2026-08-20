# Manifiesto de release

## Identidad

- Versión de aplicación: `0.8.0-fase9`.
- Migración más reciente: `migracion_v25.sql`.
- Tag de release anotado: `v0.8.0-fase9`.
- Commit de release: la referencia autoritativa es el commit al que apunta el
  tag anotado `v0.8.0-fase9`; se obtiene con
  `git rev-parse v0.8.0-fase9^{commit}`.
- Fecha de saneamiento: 20/08/2026 (Europe/Madrid).

La Fase 9.5 no cambia la versión ni el esquema porque solo sanea Git,
secretos, procedencia y empaquetado.

## Requisitos

- PHP `8.1+`; verificado localmente con PHP `8.2.12`.
- Extensiones: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl`, `dom`,
  `simplexml` y `zlib`.
- Base verificada localmente: MariaDB `10.4.32`.
- Servidor web con document root en `public/` y HTTPS en producción.
- Tareas técnicas configuradas desde `cron/`.

La compatibilidad completa de todas las migraciones con otras versiones de
MySQL/MariaDB debe verificarse en staging antes de producción.

## Configuración requerida

Copiar `.env.example` a un `.env` no versionado y proporcionar, como mínimo:

- conexión independiente para desarrollo/test/producción;
- `APP_ENV`, `APP_URL`, nombre, zona horaria y sesiones;
- SMTP cuando se habilite correo;
- directorios privados de logs, importaciones y copias;
- réplica externa de backups y parámetros de monitorización.

No se distribuyen contraseñas, tokens, cookies, dumps ni configuraciones
reales dentro de la release.

## Componentes runtime principales

- `public/`: único document root y front controller.
- `app/`: configuración, controladores, helpers, modelos, servicios, vistas y
  migraciones.
- `cron/`: backups, mantenimiento, monitor y tareas existentes.
- `ops/`: migraciones, preflight, smoke, despliegue y restauración.
- `.env.example`, `VERSION`, `README.md` y documentación operativa.

`instalar.php` se conserva en el repositorio como herramienta CLI histórica,
pero se excluye de la release productiva. La instalación productiva debe usar
el procedimiento de `DESPLIEGUE.md` y `ops/migrate.php`.

## Evidencia final de Fase 9.5

- Tests: 4 suites, 32 scripts, 379 comprobaciones y 0 fallos.
- HTTP: 37 correctas, 0 fallidas.
- Render: 12 pantallas correctas.
- Smoke: 11 comprobaciones correctas.
- Lint: 130 archivos PHP, 0 fallos.
- Migraciones: `pending=[]`, `checksum_mismatch=[]`, última v25.

La misma regresión pasó antes y después del saneamiento. Las cifras no son una
promesa sobre otro servidor.

## Exclusiones obligatorias

La release no contiene `.git`, `.env`, `.git.bak`, `*.bak`, ZIP históricos,
dumps, `copias/`, `storage/`, `restauraciones/`, `tests/`, `pruebas/`, logs,
sesiones, imports, datos personales ni secretos.

## Pendientes conocidos

- Rotar cualquier credencial que haya estado en `.git.bak`,
  `.env.produccion.bak` o `recursos/inscripciones.zip`.
- Decidir con el propietario si se reescribe el historial remoto; retirar el
  archivo en un commit nuevo no lo elimina del commit antiguo.
- Verificar dominio, TLS, SMTP, backup externo y restauración en el proveedor.
- Aclarar licencia y derechos de todos los colaboradores y assets.
- DORLET, IDEMIA y biometría no están implementados ni forman parte de esta
  release.
