# Informe de restore externo — Fase 14

Estado: **NO EJECUTADO / NO-GO**.

No existe un destino externo desde el que descargar. La restauración local de
Fase 13 demuestra el procedimiento, pero no satisface el restore externo
obligatorio de Fase 14.

## Ensayo que debe ejecutarse

| Paso | Evidencia | Estado |
|---|---|---|
| Generar DB y archivos | Nombre, tamaño, fecha y SHA-256 | PENDIENTE |
| Transferir | Identificador del objeto remoto, sin credenciales | PENDIENTE |
| Eliminar copia temporal de ensayo | Evidencia de que se descargó del remoto | PENDIENTE |
| Descargar | Fecha, duración y tamaño | PENDIENTE |
| Verificar SHA-256 | Local original = remoto descargado | PENDIENTE |
| Restaurar DB independiente | Base con `restore`, nunca staging activo | PENDIENTE |
| Restaurar archivos | Destino vacío y manifiesto válido | PENDIENTE |
| Migraciones | `pending=[]`, `checksum_mismatch=[]` | PENDIENTE |
| Smoke externo | Health, login y rutas sensibles | PENDIENTE |
| Limpieza autorizada | Identificación exacta del entorno temporal | PENDIENTE |

## RTO/RPO

- RTO real de staging: **PENDIENTE**. Objetivo documentado: 4 horas.
- RPO DB real: **PENDIENTE**. Objetivo documentado: 6 horas.
- RPO archivos real: **PENDIENTE**. Objetivo documentado: 24 horas.

Los objetivos no se etiquetarán como reales hasta observar cron y restore en la
infraestructura final.
