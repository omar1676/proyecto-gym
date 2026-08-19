# Plan de migración de datos — diseño

No se han importado datos reales. Las plantillas biométricas quedan fuera.

## Implementación disponible desde Fase 8

- CSV genérico UTF-8 para socios y productos: completo.
- Mapeo, dry-run, informe, SHA-256, batches, external IDs y reanudación: completo.
- Membresías: parser/dry-run; importación real bloqueada hasta acordar referencias,
  cobros y tratamiento del histórico.
- XLSX y JSON: pendientes; no se ha creado un parser casero.
- Ventas históricas, cobros y facturas: solo diseño.

Detalles técnicos y operación: `MOTOR_IMPORTACION.md` y
`PROCEDIMIENTO_MIGRACION_PILOTO.md`.

## Flujo obligatorio

`IMPORTAR → VALIDAR → NORMALIZAR → SIMULAR → INFORME → CONFIRMAR → IMPORTAR → VERIFICAR → AUDITAR`

1. Recibir únicamente una exportación autorizada en CSV, XLSX o JSON.
2. Copiarla a un staging cifrado y temporal, nunca al document root.
3. Registrar origen, fecha, checksum, empresa destino y responsable.
4. Leer a tablas de staging sin tocar las tablas operativas.
5. Validar formato, codificación UTF-8, columnas, tipos y límites.
6. Normalizar en staging teléfonos, emails, fechas, DNI/NIE, IBAN y dinero.
7. Resolver referencias mediante mapas de IDs externos; nunca reutilizar un ID
   externo como autorización ni asumir que coincide con el ID interno.
8. Ejecutar simulación completa y generar informe de altas, actualizaciones,
   duplicados, rechazos y relaciones huérfanas.
9. Obtener confirmación humana del informe.
10. Importar por empresa en una transacción o lotes atómicos reanudables.
11. Recontar, reconciliar importes y verificar muestras funcionales.
12. Registrar la importación y conservar el informe, no el archivo indefinidamente.

## Orden de dependencias

1. Empresa y configuración.
2. Sedes.
3. Empleados y roles.
4. Tarifas, suplementos y productos.
5. Socios.
6. Membresías y mandatos.
7. Stock inicial con fecha de corte.
8. Ventas, cobros, facturas y remesas.
9. Históricos y auditoría importada claramente identificada.

## Reglas de validación

- Toda fila lleva `empresa_destino` fijada por el proceso, no por el fichero.
- La sede externa debe mapearse a una sede de esa empresa.
- Emails, usuarios, DNI/NIE y referencias se contrastan con restricciones UNIQUE.
- Dinero se transforma a decimal/centavos, nunca a `float` como fuente.
- Fechas ambiguas se rechazan; no se adivina entre `dd/mm` y `mm/dd`.
- Una referencia ausente produce rechazo explicable, no una FK nula silenciosa.
- Los duplicados se clasifican: idéntico, actualizable o conflicto humano.
- Ningún campo desconocido se descarta sin aparecer en el informe.

## Simulación e informe

El informe debe incluir por entidad: leídas, válidas, nuevas, actualizables,
duplicadas, rechazadas, huérfanas y ejemplos anonimizados. Debe mostrar también
totales de stock, ventas, impuestos, cobros y membresías por estado antes y
después de la simulación.

## Confirmación e idempotencia

Cada lote tendrá `importacion_id`, checksum de origen y clave externa por fila.
Repetir el mismo lote no creará duplicados. Cambiar el archivo exige una nueva
simulación y confirmación.

## Verificación posterior

- Recuentos por entidad, empresa y sede.
- Socios activos/vencidos y fechas extremas.
- Stock total y productos negativos.
- Sumas de ventas, IVA y cobros por periodo.
- Mandatos/remesas y referencias únicas.
- Pruebas de aislamiento cruzado.
- Muestra visual con recepción y dirección.

## Rollback

Antes de importar se toma backup global y exportación lógica del tenant. Si el
lote aún no se ha activado, se revierte la transacción. Si ya convivió con
operaciones posteriores, no se borra en masa: se restaura a staging, se compara
y se prepara una corrección selectiva auditada.

## Fuera de alcance

No se migrarán huellas, plantillas biométricas ni secretos de proveedores. Los
eventos de acceso solo se estudiarán cuando exista una exportación autorizada y
un modelo de acceso aprobado.

Una venta histórica nunca se registrará mediante el flujo de venta normal: no
debe descontar stock actual, consumir numeración operativa ni alterar la caja
del día. Requiere un adaptador histórico y una conciliación independiente.
