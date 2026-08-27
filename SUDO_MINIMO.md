# Reducción de sudo para operación Gimnera

Estado: **NEEDS_REDESIGN / NO APLICAR TODAVÍA**.

La cuenta operativa conserva temporalmente `NOPASSWD:ALL`. No se retirará hasta
disponer de una clave nominal verificada, una segunda vía de recuperación y
wrappers root-owned que no permitan convertir argumentos en una shell root.

## Operaciones necesarias

- validar configuración Nginx;
- consultar estado de Nginx, PHP-FPM, MariaDB y timers Gimnera;
- recargar Nginx/PHP-FPM solo después de una validación correcta;
- verificar health, migraciones y backup;
- instalar una release previamente validada en un directorio inmutable;
- cambiar `current` a una release permitida;
- rollback compatible a una release permitida;
- iniciar y verificar los servicios de backup;
- restaurar exclusivamente a una DB temporal con nombre generado y validado.

## Requisitos de los wrappers

1. Propietario `root:root`, modo `0750`, directorio no escribible por el
   operador.
2. Sin `eval`, `bash -c`, `sh -c`, editores, intérpretes, comandos arbitrarios,
   expansión de globs ni paths proporcionados sin validar.
3. Release identificada por versión y commit con formato cerrado; `realpath`
   debe permanecer bajo `/var/www/gimnasio/releases/`.
4. Restore limitado a prefijos temporales, nunca `gimnasio_staging`.
5. `nginx -t` obligatorio antes de reload.
6. Log de acción, actor y resultado sin secretos.
7. Tests hostiles de traversal, symlink, argumentos vacíos, newline y opción
   inesperada.

## Gate de aplicación

Aplicar el sudoers mínimo solo después de probar en dos sesiones independientes:

1. health y diagnóstico;
2. build/verificación de release;
3. deploy dry-run;
4. rollback dry-run;
5. backup y verificación;
6. restore temporal;
7. consola de recuperación del proveedor.

Hasta entonces, el P1 permanece abierto. Sustituir `ALL` por otra wildcard no
se considera mejora.
