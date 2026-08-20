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

## Incidentes durante el piloto paralelo

### Diferencia entre sistema actual y nuevo

1. No sobrescribir uno con el otro. Identificar el dominio y la fuente de verdad vigente.
2. Detener nuevas operaciones de ese dominio en el sistema nuevo.
3. Preservar IDs, horas, actor, sede y versión; evitar datos personales en el ticket.
4. Conciliar desde la última operación común y decidir corrección/rollback con negocio.

### Caja, venta o stock no conciliados

1. Detener ventas piloto del producto/caja afectados; continuar en el sistema actual.
2. No “cuadrar” con un ajuste sin motivo/aprobación.
3. Comparar venta, líneas, movimiento de caja, stock y posible reintento/idempotencia.
4. Clasificar P0 si hay pérdida, doble cobro/venta o inconsistencia sin workaround seguro.

### Cobro duplicado

1. No devolver ni repetir automáticamente; localizar obligación, cobros, recibo y clave idempotente.
2. Detener nuevas acciones sobre esa deuda y confirmar en la fuente oficial/banco.
3. Preservar ambos registros y autorizar cualquier devolución/compensación con dirección.
4. Conciliar caja/remesa y registrar causa antes de reabrir el flujo.

### Socio desaparecido o estado incoherente

1. Buscar por claves autorizadas en ambos sistemas sin crear un duplicado.
2. Confirmar tenant, sede, baja/anonimización e importación; preservar auditoría.
3. Mantener como fuente el sistema vigente y escalar como P0 si hubo pérdida o mezcla.
4. Restaurar primero en una base independiente; no insertar manualmente sobre producción.

### Importación fallida

1. Detener el lote; conservar original, hash, dry-run e informe.
2. No editar la exportación autorizada ni relanzar sobre datos parcialmente importados.
3. Restaurar/recrear el tenant de staging y repetir desde cero tras corregir la regla.
4. Si alcanzó datos reales, activar el procedimiento de pérdida/corrección de datos.

### Posible mezcla de empresas o sedes

1. P0: detener inmediatamente el nuevo sistema y preservar evidencias.
2. Revocar sesiones afectadas; no explorar otros registros para “confirmar”.
3. Determinar alcance con logs SECURITY y notificar al responsable de privacidad.
4. No reabrir hasta corrección, pruebas cruzadas y aprobación formal.

### DORLET o acceso físico

El piloto F12 no está conectado. Si se observa una llamada, credencial o cambio
de hardware, detener el proceso, fijar `ACCESS_CONTROL_MODE=disabled`, preservar
logs y escalar: es una desviación crítica de alcance.
