# Procedimiento de migración piloto futura

Solo se utilizará una exportación legítima entregada por Cleto o su proveedor.
No autoriza acceso, scraping ni conexión a FitCloud.

| Paso | Acción | Evidencia requerida |
|---:|---|---|
| 1 | Recibir exportación autorizada | Responsable, alcance y fecha |
| 2 | Preservar copia original inmutable | Ubicación privada y permisos |
| 3 | Calcular SHA-256 | Hash registrado |
| 4 | Copiar a staging aislado | Fuera de `public/` |
| 5 | Identificar formato y columnas | Diccionario del proveedor |
| 6 | Definir perfil/mapeo | Aprobación de campos y fechas |
| 7 | Ejecutar dry-run | Cero cambios de negocio |
| 8 | Revisar informe | Errores, warnings y duplicados resueltos |
| 9 | Importar primero en staging | Batch, recuentos y logs |
| 10 | Recontar entidades | Origen frente a destino |
| 11 | Muestreo manual | Recepción y dirección |
| 12 | Conciliar | Socios, fechas, importes y estados |
| 13 | Crear backup de producción | Reciente, externo y SHA-256 correcto |
| 14 | Abrir ventana de migración | Responsable y plan de parada |
| 15 | Confirmar importación | Usuario nominal y tenant revisado |
| 16 | Validar aplicación | Health, smoke, aislamiento y muestras |
| 17 | Obtener aprobación | Dirección y responsable técnico |

## Criterios de parada

- Tenant o sede incorrectos.
- Hash distinto al analizado.
- Errores de dry-run sin resolver.
- Referencias huérfanas o duplicados ambiguos.
- Backup inexistente/no verificable.
- Recuentos o sumas que no concilian.
- Formato fiscal o de cobro no acordado.

## FitCloud futuro

Cuando exista un archivo autorizado se archivará una muestra anonimizada, se
documentarán sus encabezados y se creará un perfil versionado. Hasta entonces
solo existe el perfil genérico; no se afirma compatibilidad FitCloud.

## Exclusiones

Huellas, templates, rostros, iris, DORLET, puertas, ventas históricas completas,
cobros y facturación legal quedan fuera de este procedimiento inicial.
