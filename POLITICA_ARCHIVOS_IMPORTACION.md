# Política operativa de archivos de importación

Documento técnico orientativo; no sustituye asesoramiento jurídico/RGPD.

## Ciclo de vida

1. Recibir el archivo por un canal autorizado.
2. Calcular SHA-256 y registrar solo el nombre lógico.
3. Guardarlo con nombre aleatorio en `storage/imports`, fuera de `public/`.
4. Restringir acceso al proceso PHP/operación; nunca exponer enlaces directos.
5. Conservarlo durante análisis y dry-run, máximo 7 días por defecto.
6. Eliminarlo inmediatamente después de una importación completada.
7. El cron elimina archivos, staging normalizado e informes por fila caducados.
8. Conservar únicamente metadatos mínimos del batch, recuentos, hash y auditoría.

## Datos y logs

- No registrar el contenido completo del archivo.
- Los problemas por fila conservan solo un extracto limitado y escapado.
- No registrar contraseñas, tokens, IBAN completos ni biometría.
- Los datos sintéticos de `pruebas/fixtures/importaciones` usan `example.invalid`
  y no proceden de personas reales.

## Backups

El staging temporal no debe conservarse indefinidamente en backups. Si entra en
una ventana de backup, su retención debe estar ligada al batch y al propósito.
Las copias con datos importados siguen la política global y las obligaciones de
conservación/supresión aplicables a los datos de negocio.

## Incidentes y derechos

Una filtración, archivo enviado al tenant incorrecto o petición de supresión se
eleva al responsable de privacidad. Antes de borrar se comprueban obligaciones
legales, actividad posterior y backups. Las decisiones y verificaciones deben
quedar auditadas.
