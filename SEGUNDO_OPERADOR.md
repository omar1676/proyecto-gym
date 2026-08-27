# Procedimiento del segundo operador

Estado actual: **NO VERIFICADO**; no se ha designado una segunda persona.

La persona designada tendrá cuenta nominal y clave SSH propia, nunca la clave
del propietario. Necesita acceso mínimo a OVH, DNS/Cloudflare, Git y al material
de recuperación de R2 según su función, con MFA de proveedor.

Prueba sin ayuda verbal:

1. Consultar health, heartbeat, timers, migraciones y release activa.
2. Construir/verificar una release desde commit conocido.
3. Ejecutar backup previo y comprobar marcador R2.
4. Desplegar release compatible mediante directorio inmutable y `current`
   atómico; ejecutar health/smoke.
5. Hacer rollback compatible sin migrador antiguo.
6. Descargar una copia R2 y restaurarla en DB temporal; verificar esquema y
   smoke; eliminar solo la DB temporal.

Registrar tiempo, pasos bloqueados y errores. La prueba solo es VERIFICADA si
otra persona la completa siguiendo documentación, sin instrucciones verbales.

## Alta nominal

1. Identidad personal y responsable autorizante registrados fuera de Git.
2. Cuenta Linux individual y clave pública propia; nunca copiar claves privadas.
3. Sudo limitado a wrappers root-owned ya probados.
4. Cuenta individual y MFA en cada proveedor estrictamente necesario.
5. Acceso al material de recuperación según función, no por comodidad.
6. Prueba de health, diagnóstico, backup, rollback dry-run, localización del
   restore y respuesta a una alerta sintética.

## Offboarding

1. Deshabilitar primero las sesiones y cuentas individuales del operador.
2. Retirar por fingerprint únicamente su clave SSH y probar acceso alternativo.
3. Retirar su sudoers específico y validar `visudo -cf` antes de cerrar sesión.
4. Revocar GitHub, Cloudflare y OVH; revisar sesiones/tokens emitidos para esa
   identidad y rotar solo lo compartido que no pueda revocarse individualmente.
5. Desactivar su cuenta Gimnera conservando la auditoría histórica.
6. Verificar que no puede iniciar una sesión nueva ni usar una anterior.
7. Registrar fecha, autorizante y evidencias sin conservar secretos.
