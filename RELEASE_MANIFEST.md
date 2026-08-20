# Manifiesto de release

## Identidad

- Versión declarada por la aplicación: `0.9.0-fase10`.
- Migración más reciente: `migracion_v26.sql`.
- Último tag de release anotado: `v0.9.0-fase10`.
- El commit exacto del tag se obtiene con
  `git rev-parse v0.9.0-fase10^{commit}`. Fases 11–12 añaden preparación y una
  corrección neutral de mensajes; no se ha creado un tag nuevo.
- Fecha de saneamiento: 20/08/2026 (Europe/Madrid).

La Fase 12 no cambia la versión ni añade funciones de negocio. El despliegue
piloto debe identificar además el commit exacto, no solo el contenido de
`VERSION`.

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

## Evidencia local final de Fase 12

- Tests: 4 suites, 37 scripts, 464 comprobaciones y 0 fallos.
- HTTP: 37 correctas, 0 fallidas.
- Render: 12 pantallas correctas.
- Smoke: 11 comprobaciones correctas.
- Lint: 145 archivos PHP, 0 fallos.
- Migraciones: `pending=[]`, `checksum_mismatch=[]`, última v26.
- Segundo gimnasio sintético: 16 comprobaciones, 0 fallos; fixture eliminada.

Las cifras corresponden a la ejecución local del 20/08/2026 y no son una
promesa sobre staging o producción.

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
