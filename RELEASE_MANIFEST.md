# Manifiesto de release

## Identidad

- Versión declarada por la aplicación: `0.10.0-fase19`.
- Migración más reciente: `migracion_v26.sql`.
- Último tag histórico de release anotado: `v0.9.0-fase10`.
- El commit exacto del tag se obtiene con
  `git rev-parse v0.9.0-fase10^{commit}`. Fases 11–12 añaden preparación y una
  corrección neutral de mensajes; no se ha creado un tag nuevo.
- Fecha de saneamiento: 21/08/2026 (Europe/Madrid).

F19 no añade funciones de negocio. Cada artefacto se genera desde un commit
limpio mediante `php ops/build_release.php --output-dir=<directorio>` y se
acompaña de un manifiesto determinista de hashes por archivo.

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

## Evidencia anterior y contrato de F19

- La evidencia de Fase 12 (4 suites, 37 scripts, 464 comprobaciones) es
  histórica y no se reutiliza para declarar F19 aprobada.
- HTTP: 37 correctas, 0 fallidas.
- Render: 12 pantallas correctas.
- Smoke: 11 comprobaciones correctas.
- Lint: 145 archivos PHP, 0 fallos.
- Migraciones: `pending=[]`, `checksum_mismatch=[]`, última v26.
- Segundo gimnasio sintético: 16 comprobaciones, 0 fallos; fixture eliminada.

El gate P0 se ejecuta con `php tests/run.php --p0-gate`; el contrato del runner
es `exit 0 = PASS real`. `--inject-failure` debe provocar una salida distinta de
cero. La suite completa, sin el flag P0, mantiene visibles los fallos económicos
de trial que pertenecen al saneamiento P1.

## Exclusiones obligatorias

La release no contiene `.git`, `.env`, `.git.bak`, `*.bak`, ZIP históricos,
dumps, `copias/`, `storage/`, `restauraciones/`, `tests/`, `pruebas/`, logs,
sesiones, imports, datos personales ni secretos.

## Pendientes conocidos

- Mantener revocada cualquier credencial histórica que haya estado en
  `.git.bak`, `.env.produccion.bak` o `recursos/inscripciones.zip`; su valor no
  se distribuye ni se documenta.
- Decidir con el propietario si se reescribe el historial remoto; retirar el
  archivo en un commit nuevo no lo elimina del commit antiguo.
- Verificar dominio, TLS, SMTP, backup externo y restauración en el proveedor.
- Aclarar licencia y derechos de todos los colaboradores y assets.
- DORLET, IDEMIA y biometría no están implementados ni forman parte de esta
  release.
