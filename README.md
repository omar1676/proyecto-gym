# Proyecto Gym — Centro Deportivo Cleto Reyes

Versión actual: **0.12.0-fase21**. Esta versión añade controles operativos y de privacidad: backups sin colisión, compatibilidad forward-only de esquema, fotos personales privadas, correlation IDs, auditoría común y offboarding con invalidación de sesiones. No conecta DORLET, lectores, controladoras ni biometría.

Panel de gestión para un gimnasio multisede: socios y membresías, venta de productos,
empleados, remesas SEPA y control de acceso desde el panel.

Aplicación PHP con arquitectura MVC: front controller en `public/index.php`, modelos
con PDO y vistas PHP con Tailwind.

## Puesta en marcha

Requiere PHP 8.1+ con PDO MySQL. La regresión F21 se ejecuta localmente con PHP
8.2.12/MariaDB 10.4 y se repite en staging con PHP 8.3/MariaDB 10.11 antes de
activar una release.

```bash
cp .env.example .env      # y ajusta credenciales, APP_URL, APP_NOMBRE, SMTP…
INSTALL_ADMIN_PASSWORD='secreto-local-de-12+-caracteres' php instalar.php
php -S localhost:8080 -t public
```

El valor anterior es únicamente un marcador: usa un secreto local nuevo y no
lo copies a Git, documentación ni historial de terminal compartido.

`instalar.php` es una ayuda local histórica y no se incluye en el paquete
productivo. En producción se sigue `DESPLIEGUE.md` y se aplican migraciones con
`ops/migrate.php`.

## Estructura

| Carpeta        | Contenido                                             |
| -------------- | ----------------------------------------------------- |
| `app/`         | Controladores, modelos, vistas, helpers y migraciones |
| `public/`      | Raíz web: front controller y assets                   |
| `pruebas/`     | Suites de pruebas (`php pruebas/negocio.php`, …)      |
| `cron/`        | Tareas programadas (avisos, remesas)                  |
| `recursos/`    | Activos de origen cuya procedencia debe documentarse  |

## Pruebas

```bash
php tests/run.php --p0-gate  # gate técnico; excluye y declara los P1 conocidos
php tests/run.php            # suite completa, incluido trial económico
```

`EXIT 0` significa que todos los scripts ejecutados han pasado realmente. La
suite completa seguirá devolviendo error mientras exista un P1 conocido que
falle; no se convierte en verde por interpretar únicamente su texto.

Las suites que modifican datos usan exclusivamente `DB_NAME_PRUEBAS` y abortan
si coincide con `DB_NAME` o si `APP_ENV=production`. Prepara esa base con
`php pruebas/preparar_base.php` antes de ejecutarlas.

## Documentación

`DESPLIEGUE.md` cubre la configuración completa, el despliegue en alojamiento
compartido, el correo saliente y las copias de seguridad.

`RELEASE_MANIFEST.md`, `CODE_PROVENANCE.md` y
`SANEAMIENTO_REPOSITORIO.md` documentan la composición de la release, la
procedencia y las rotaciones de secretos pendientes. Los archivos históricos,
dumps y configuraciones reales no forman parte del repositorio ni de la
release.
