# Rendimiento del motor de importación — Fase 8

Base independiente `portal_de_cursos_pruebas`; datos 100 % sintéticos.

Volumen: 5.000 socios, CSV de 656.623 bytes y lotes de 250.

| Fase | Resultado medido |
|---|---:|
| Parsing streaming, 3 muestras | p50 31,93 ms; máximo 36,23 ms |
| Dry-run | 25.016,49 ms; 5.000 válidas; 0 errores |
| Cambios de negocio durante dry-run | 0 |
| Importación | 25.994,31 ms; 5.000 creados |
| Verificación tenant | 5.000 socios en empresa/sede objetivo |
| Memoria PHP máxima | 4 MiB |

Es una medición local, no de producción. El tiempo incluye validación,
deduplicación, staging por fila, 20 transacciones, mapas externos y auditoría.
El script elimina al finalizar el tenant sintético utilizado.
