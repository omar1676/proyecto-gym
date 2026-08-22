# Manifiesto de release

## Identidad

- Versión declarada por la aplicación: `0.13.1-fase22.1`.
- Migración más reciente: `migracion_v29.sql`.
- Último tag histórico de release anotado: `v0.9.0-fase10`.
- El commit exacto del tag se obtiene con
  `git rev-parse v0.9.0-fase10^{commit}`. Fases 11–12 añaden preparación y una
  corrección neutral de mensajes; no se ha creado un tag nuevo.
- Fecha de saneamiento: 22/08/2026 (Europe/Madrid).

F22.1 cierra el ciclo de vida de tenants y operadores de plataforma, y evita
que excepciones crudas filtren datos en logs. No añade esquema ni funcionalidad
de negocio. Mantiene el alta SaaS y aislamiento de catálogos de F22. Cada artefacto se genera desde un commit
limpio mediante `php ops/build_release.php --output-dir=<directorio>` y se
acompaña de un manifiesto determinista de hashes por archivo.

## Requisitos

- PHP `8.1+`; verificado localmente con PHP `8.2.12`; la verificación final
  de esta release se ejecuta también con PHP 8.3 en staging.
- Extensiones: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl`, `dom`,
  `simplexml` y `zlib`.
- Base verificada localmente: MariaDB `10.4.32`; los tests económicos críticos
  se repiten en MariaDB `10.11` sobre bases temporales.
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

## Evidencia local y contrato de F22

- La evidencia de fases anteriores es histórica y no se reutiliza para declarar
  F21.1 aprobada.
- La cifra final de suite, PHP 8.3 y MariaDB 10.11 se registra en el informe
  operativo de F21.1 a partir de ejecuciones reales; este manifiesto no congela
  una cifra que pueda quedar desactualizada al añadir un test de gate.
- Carrera económica intermitente reproducida y corregida: 20/20 repeticiones
  concurrentes correctas después del bloqueo estable por sede.
- El total y resultado del lint del árbol final se registran en el informe de
  F21.1; no se conserva aquí una cifra que cambie al añadir un gate.
- HTTP, smoke, PHP 8.3 y MariaDB 10.11 se vuelven a ejecutar contra la release
  inmutable antes de activarla; su resultado no se anticipa en este archivo.
- Migraciones: objetivo `pending=[]`, `checksum_mismatch=[]`, `structural_mismatch=[]`, última v29.
- El total real de suite, Atlas y provisioning de 100 tenants se registra tras
  ejecutar los gates finales; no se anticipa una cifra en este manifiesto.

El gate P0 se ejecuta con `php tests/run.php --p0-gate`; el contrato del runner
es `exit 0 = PASS real`. `--inject-failure` debe provocar una salida distinta de
cero. La suite completa incluye trial, atomicidad, concurrencia económica,
aislamiento, seguridad, caja, ventas, stock, mandatos y remesas.

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
- Configurar y recibir una alerta humana por SMTP técnico autorizado.
- Instalar y probar un watchdog fuera del VPS.
- Designar y probar un segundo operador nominal; verificar MFA de proveedores.
- Aclarar licencia y derechos de todos los colaboradores y assets.
- DORLET, IDEMIA y biometría no están implementados ni forman parte de esta
  release.
