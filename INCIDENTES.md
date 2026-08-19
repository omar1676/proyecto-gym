# Respuesta inicial a incidentes

Primero: anotar hora, versión (`php ops/status.php`), alcance y responsable. No
borrar logs ni improvisar migraciones. Si hay corrupción o riesgo de agravarla,
activar mantenimiento y detener cron de negocio; mantener backups y monitor.

## Web caída

1. Comprobar `/health`, servidor web, PHP-FPM y espacio en disco.
2. Revisar logs web, `storage/logs` y último despliegue.
3. Si empezó tras release, volver `current` a la anterior y ejecutar smoke.
4. No restaurar MySQL salvo evidencia de pérdida/corrupción.

## Base de datos caída

1. Detener cron de negocio y activar mantenimiento.
2. Comprobar servicio, conexiones, disco y logs de MySQL.
3. Reiniciar solo si se conoce la causa y existe backup reciente verificado.
4. Si hay corrupción, restaurar en una base nueva, verificar y después conmutar.

## Disco lleno

1. Detener escrituras no esenciales y cron.
2. Identificar consumo; no borrar la copia válida más reciente.
3. Rotar/comprimir logs y mover backups ya verificados externamente.
4. Ampliar disco y verificar DB antes de reabrir.

## Backup fallido

1. El monitor debe alertar; conservar el último artefacto válido.
2. Revisar permisos, espacio, conexión DB y destino externo.
3. Ejecutar manualmente ambos scripts y validar SHA-256.
4. No desplegar migraciones hasta recuperar backups válidos.

## Migración fallida

1. Mantener mantenimiento y detener despliegue.
2. Guardar error y comprobar qué DDL llegó a aplicarse; MySQL puede autocommitirlo.
3. No relanzar a ciegas. Corregir con una migración nueva o restaurar predeploy.
4. Ejecutar status, smoke y conciliación antes de abrir.

## Error después de actualización

1. Mantenimiento, logs y versión.
2. Volver a la release anterior si el esquema sigue siendo compatible.
3. Si no es compatible, decidir restauración considerando pérdida desde el backup.
4. Comparar ventas/stock antes de reabrir.

## Credencial comprometida

1. Revocar/rotar inmediatamente DB, SMTP o cuenta afectada.
2. Cerrar sesiones, revisar logs SECURITY y accesos del proveedor.
3. Actualizar el secreto seguro, nunca Git; redeplegar y verificar.
4. Valorar notificación legal si hubo datos personales expuestos.

## Pérdida accidental de datos

1. Detener escrituras y preservar DB/logs actuales.
2. Restaurar el backup en base independiente, nunca encima de origen.
3. Determinar RPO y recuperar selectivamente si es seguro.
4. Verificar integridad, aprobación responsable y documentar lo perdido.
