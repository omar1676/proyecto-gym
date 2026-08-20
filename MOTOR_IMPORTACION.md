# Motor de importación — Fase 8

## Arquitectura

`CSV privado → inspección → mapeo → normalización → dry-run → informe → backup → lotes → verificación → auditoría`

El núcleo vive en `app/services/` y no depende de la vista. `MigrationService`
se construye con empresa, sede y usuario obtenidos del `TenantContext`. Las
columnas `empresa_id`, `sede_id` o `tenant_id` del archivo se detectan y se
ignoran como fuente de autoridad.

Componentes:

- `MigrationStorage`: staging con nombres aleatorios fuera de `public/`.
- `CsvImportReader`: MIME, extensión, UTF-8/BOM, delimitador, comillas, tamaño,
  filas máximas y lectura streaming.
- `ImportFieldMapper`: contrato interno, aliases genéricos y mapeo explícito.
- `ImportNormalizer`: validación conservadora de socios, productos y membresías.
- `MigrationService`: batches, deduplicación, dry-run, lotes, reanudación,
  external IDs, auditoría e idempotencia.
- `ImportBackupGuard`: impide confirmar sin backup reciente y SHA-256.
- `MigrationMaintenance`: caducidad de archivos, filas de staging e informes.

## Entidades

| Entidad | Estado | Escritura real | Observación |
|---|---|---:|---|
| Socios | COMPLETA | Sí | Nunca sobrescribe coincidencias con diferencias |
| Productos | COMPLETA | Sí | Precio DECIMAL, stock no negativo, sede obligatoria |
| Membresías | DRY-RUN | No | Valida socio mapeado, tarifa, fechas y precio histórico |
| Ventas históricas | SOLO DISEÑO | No | No deben descontar stock actual ni alterar numeración/caja |
| Cobros/facturas | SOLO DISEÑO | No | Pendiente de dirección, gestoría y formato autorizado |
| Biometría | EXCLUIDA | No | No se procesa ni almacena ningún dato biométrico |

## CSV

Se admiten coma o punto y coma, UTF-8 con/sin BOM, encabezados, comillas,
líneas vacías y caracteres españoles. Límites por defecto: 10 MiB y 10.000
filas. La lectura es streaming. Se rechaza contenido PHP, binario, encoding no
válido, extensión distinta de `.csv`, exceso de columnas o límites.

XLSX no se incorporó: el proyecto no tiene una dependencia mantenida para ello
y no se construirá un parser casero. JSON queda pendiente hasta existir un caso
real. Prioridad mantenida: CSV > XLSX > JSON.

## Mapeo y perfiles

El perfil actual es `generic`. Los aliases solo sugieren un mapeo; el operador
lo revisa antes del dry-run. `source_system` identifica la procedencia lógica,
pero no existe un perfil FitCloud porque todavía no hay exportación autorizada.

Un perfil futuro contendrá únicamente encabezados, formatos de fecha, estados y
reglas documentadas a partir de una muestra legítima. Nunca contendrá
credenciales ni conexiones al proveedor.

## Deduplicación

Orden de decisión para socios:

1. mapa `(empresa, fuente, entidad, external_id)`;
2. DNI/NIE y email dentro de la empresa;
3. teléfono normalizado como posible duplicado, nunca como fusión automática;
4. el nombre por sí solo nunca decide.

Clasificaciones: `NEW`, `SAFE_MATCH`, `POSSIBLE_DUPLICATE`, `CONFLICT` e
`INVALID`. Una coincidencia segura solo crea el mapa; no sobrescribe el socio.
Un valor distinto produce conflicto y bloquea la confirmación.

Productos reutilizan únicamente un external ID ya mapeado e idéntico. Un nombre
repetido queda para revisión. Las categorías solo se vinculan cuando existe una
coincidencia única; no se crean catálogos globales a ciegas.

## Idempotencia, lotes y reanudación

- El hash SHA-256 y `attempt_no` identifican el archivo.
- Repetir el mismo archivo devuelve su batch existente.
- El mapa externo tiene restricción única por tenant/fuente/entidad.
- Cada fila tiene estado propio e `internal_id`.
- La confirmación usa lotes de 250 por defecto, máximo 500.
- Cada lote ejecuta `BEGIN → datos → mapa → auditoría → COMMIT`.
- Un fallo revierte solo el lote actual; `last_committed_row` permite continuar.

La repetición explícita queda preparada mediante `attempt_no`, pero no se ofrece
en la UI para evitar reintentos accidentales.

## Backup y reversión

Confirmar exige un backup reciente con checksum. En producción el guard exige
repositorio externo configurado. En tests se utiliza una precondición sintética
explícita.

No existe “borrar todo el batch”. Si aún no se confirmó, se puede descartar el
staging. Después de convivir con actividad, la recuperación debe usar backup,
restauración temporal, comparación y corrección selectiva auditada. Las ventas
posteriores, stock, membresías y FKs impiden un borrado masivo seguro.

## Exportación futura por empresa

Las claves externas y el grafo tenant permiten diseñar una futura exportación
para portabilidad, baja de cliente, auditoría y backups parciales. No se ha
implementado un exportador en esta fase.
