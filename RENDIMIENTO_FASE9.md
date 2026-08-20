# Rendimiento medido — Fase 9

Medición local sobre MySQL/MariaDB de desarrollo el 20/08/2026, después de aplicar v25. No representa latencia de un servidor real; sirve para verificar plan e índices.

## EXPLAIN

| Consulta | Índice elegido | Tipo | Filas estimadas |
|---|---|---:|---:|
| obligaciones pendientes empresa/sede/fecha | `idx_obligacion_tenant_estado_fecha` | `ref` | 1 |
| último cobro socio/estado/fecha | `idx_cobro_socio_estado` | `ref` | 1 |
| caja abierta empresa/sede | `idx_caja_tenant_fecha` | `ref` | 1 |
| movimientos de una sesión | `idx_caja_mov_sesion_fecha` | `ref` | 1 |

Ninguna de estas consultas hizo barrido completo en la medición.

## Tiempo local

Media de 1.000 ejecuciones preparadas por consulta:

| Consulta | Media |
|---|---:|
| obligaciones | 0,4977 ms |
| cobros | 0,4523 ms |
| caja abierta | 0,5521 ms |
| movimientos | 0,5752 ms |

El resumen paginado de socios obtiene la economía en dos consultas por página (agregado + último cobro), no una consulta por socio. Queda pendiente repetir el benchmark económico con volumen realista en staging cuando existan datos del piloto; no se han usado datos reales para esta prueba.
