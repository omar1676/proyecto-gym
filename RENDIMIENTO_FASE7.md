# Evidencia de rendimiento — Fase 7

Datos exclusivamente sintéticos en `portal_de_cursos_pruebas`.

## Volumen

- 5 empresas originales de carga.
- 9.000 socios totales.
- 5.000 socios en la empresa/tenant medido.
- 7.500 membresías, 6.000 ventas y 12.000 eventos de auditoría.
- 9 muestras más calentamiento para p50/p95.

## Antes

- Fase 6, empresa con 1.000 socios sin paginar: unos 4.093 KB de HTML,
  mediana HTTP 1.558 ms y p95 1.607 ms.
- Muestra controlada Fase 7 del SQL histórico con 5.000 socios:
  102.528,15 ms, 5.000 filas y 2.313.315 bytes en JSON.
- No se repitió nueve veces: una única muestra superó 100 segundos.

## Después

Medición final con índices de `migracion_v23.sql`:

| ESCENARIO | FILAS ENVIADAS | P50 | P95 | TAMAÑO/MEMORIA |
|---|---:|---:|---:|---:|
| SQL página sobre 5.000 | 50 | 70,07 ms | 75,80 ms | 23.840 B |
| SQL búsqueda 50 resultados | 50 | 96,99 ms | 105,46 ms | 22.851 B |
| SQL búsqueda 500 resultados | 50 | 92,99 ms | 99,83 ms | 22.851 B |
| SQL búsqueda exacta | 1 | 94,42 ms | 104,40 ms | 458 B |
| SQL búsqueda amplia | 50 | 90,21 ms | 109,36 ms | 23.841 B |
| SQL teléfono normalizado | 50 | 99,76 ms | 104,91 ms | 22.851 B |
| Render PHP completo, 5.000 | 50 | 167,11 ms | 180,52 ms | 270.392 B / 4 MB |
| Render PHP, búsqueda amplia | 50 | 199,33 ms | 212,69 ms | 270.727 B / 4 MB |
| HTTP completo, 5.000 | 50 | 205,17 ms | 235,98 ms | 270.394 B |
| HTTP búsqueda 50 resultados | 50 | 300,89 ms | 351,91 ms | 305.833 B |
| HTTP búsqueda 500 resultados | 50 | 299,35 ms | 313,58 ms | 307.170 B |
| HTTP búsqueda exacta | 1 | 240,26 ms | 266,11 ms | 65.079 B |
| HTTP búsqueda amplia | 50 | 223,35 ms | 252,46 ms | 270.729 B |

El listado de 5.000 socios transmite solo 50 y aproximadamente 270 KB. Frente
al listado previo de 1.000 filas y unos 4 MB, la reducción de HTML supera el 93 %.

## EXPLAIN

Antes de v23:

- listado derivado: `idx_usuario_empresa`, unas 5.005 filas, `Using filesort`;
- última membresía: `idx_socio_membresia_socio`, `Using filesort`;
- conteo: índice simple de empresa.

Después de v23:

- listado: `idx_usuario_empresa_rol_orden`, sin filesort dentro de la página;
- última membresía: `idx_sm_socio_fin`, `Using index`, sin filesort;
- conteo: índice compuesto y cubierto (`Using index`);
- queda un filesort exterior sobre solo 50 filas, de coste acotado.

La búsqueda amplia conserva un recorrido de las filas del tenant porque usa
coincidencias parciales con comodín inicial. Con 5.000 socios queda por debajo
de los tiempos anteriores; no se añadió full-text sin una necesidad demostrada.
