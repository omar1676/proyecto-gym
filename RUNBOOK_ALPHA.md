# Runbook de la alpha privada

## Apertura diaria

1. Confirmar `https://staging.gimnera.es/health` y certificado válido.
2. Revisar `systemctl list-timers 'gimnera-*'` y último resultado de backups.
3. Ejecutar `sudo -u www-data php /var/www/gimnasio/current/cron/monitor.php`.
4. Confirmar que `/heartbeat` tiene menos de 300 segundos y que el watchdog
   externo está OK cuando haya sido configurado.
5. Si hay cualquier CRITICAL, no iniciar pruebas.
6. Mantener `ACCESS_CONTROL_MODE=disabled` y usar solo datos sintéticos o
   controlados autorizados.

## Operación

- El sistema anterior sigue siendo la fuente oficial.
- Cada empleado usa cuenta nominal y anota incidencia, hora, flujo y sede.
- No se importan FitCloud, socios reales, IBAN, biometría ni datos de acceso.
- No se habilitan remesas, correos a socios, `cron/tareas.php` o control físico.

## Incidente

Ante mezcla de tenant, pérdida, operación económica incoherente o exposición de
datos: P0, detener la alpha, conservar logs y no intentar corregir datos a mano.
Para caída web/DB, disco, backup o migración se sigue `INCIDENTES.md`.

## Cierre diario

1. Revisar eventos SECURITY/ERROR y feedback de usuarios.
2. Confirmar último backup DB y archivos con SHA/manifiesto.
3. Registrar cualquier WARNING/CRITICAL y responsable.
4. Desactivar cuentas temporales que ya no deban acceder.

## Gates

- Empleados con datos sintéticos/controlados: GO solo con health, aislamiento,
  permisos, timers y backup local verificado, sin P0.
- Primer dato real: exige backup externo cifrado, restore descargado y medido,
  canal de alerta, responsables, soporte, política de datos y autorización
  formal. Si falta uno, NO-GO.

La clave SSH temporal no se retira dentro de este runbook; primero debe quedar
verificado el acceso fiable con la clave personal y un procedimiento de
recuperación fuera de banda.

## Actualización Fase 17

En la apertura diaria debe comprobarse también `backup_external=OK` y su
timestamp. Kill switch:

- R2 falla: conservar todas las copias locales, no ejecutar retención externa y
  mantener el monitor en CRITICAL.
- Token comprometido: revocarlo, conservar las claves `crypt`, emitir un token
  nuevo limitado al bucket y repetir backup/restore.
- Restore falla: conservar descarga y logs, probar otro set en otra DB; nunca
  usar `--fresh` ni restaurar sobre staging.
- Alerta no llega: alertas humanas continúan NO-GO aunque el monitor local esté
  OK.

Gates actuales: GO técnico para alpha sintética, backup externo y disaster
restore; NO-GO para alertas humanas y para cualquier dato real. La clave SSH
temporal no se retira porque la personal aún no ha demostrado una segunda sesión
fiable.

## Rollback

Antes de apuntar `current` a otra release, ejecutar su `ops/schema_gate.php
--mode=runtime`. Si el esquema queda fuera del rango declarado, STOP; no se
ejecuta el migrador antiguo. Un restore se ensaya primero en DB independiente.
