# Proyecto Gym — Centro Deportivo Cleto Reyes

Panel de gestión para un gimnasio multisede: socios y membresías, venta de productos,
empleados, remesas SEPA y control de acceso desde el panel.

Aplicación PHP con arquitectura MVC: front controller en `public/index.php`, modelos
con PDO y vistas PHP con Tailwind.

## Puesta en marcha

Requiere PHP 7.4+ con PDO MySQL y MySQL 5.7+ / MariaDB 10.2+.

```bash
cp .env.example .env      # y ajusta credenciales, APP_URL, APP_NOMBRE, SMTP…
php instalar.php          # solo CLI; crea las tablas y usuarios iniciales
php -S localhost:8080 -t public
```

## Estructura

| Carpeta        | Contenido                                             |
| -------------- | ----------------------------------------------------- |
| `app/`         | Controladores, modelos, vistas, helpers y migraciones |
| `public/`      | Raíz web: front controller y assets                   |
| `pruebas/`     | Suites de pruebas (`php pruebas/negocio.php`, …)      |
| `cron/`        | Tareas programadas (avisos, remesas)                  |
| `recursos/`    | Material de partida: logo y volcado de inscripciones  |

## Pruebas

```bash
php pruebas/negocio.php
php pruebas/acceso.php     # necesita el servidor levantado en el 8080
```

Las suites que modifican datos usan exclusivamente `DB_NAME_PRUEBAS` y abortan
si coincide con `DB_NAME` o si `APP_ENV=production`. Prepara esa base con
`php pruebas/preparar_base.php` antes de ejecutarlas.

## Documentación

`DESPLIEGUE.md` cubre la configuración completa, el despliegue en alojamiento
compartido, el correo saliente y las copias de seguridad.
