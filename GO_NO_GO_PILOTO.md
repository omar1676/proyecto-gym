# Plan por etapas y decisión GO/NO-GO

No contiene duraciones inventadas. Cada etapa termina cuando su evidencia y
responsable están completos.

## Prerrequisitos

Requisitos, fuente de verdad, soporte, feature freeze y staging deben estar
aprobados antes de la etapa 0; no se convierten en una operación con socios.

## Etapas 0–5

| Etapa | Objetivo | Entrada | Evidencia de salida | Vuelve atrás si… |
|---|---|---|---|---|
| 0. Datos sintéticos | Probar release e infraestructura sin datos reales | Staging y fixtures | Migraciones, regresión, restore, smoke, aislamiento y flujos sintéticos | Falla P0/P1 técnico |
| 1. Lectura/comparación | Observar y comparar sin escritura económica real | Guion y sistema actual disponible | Diferencias documentadas; ninguna operación duplicada | No se identifica fuente oficial |
| 2. Operaciones controladas | Personal completa casos acordados y reversibles | Formación y fallback | Tiempos, errores, ayuda y conciliación por caso | Flujo crítico imposible o riesgo de doble operación |
| 3. Subconjunto real | Validar datos autorizados y alcance limitado | Export perfilado + aprobación | Dry-run repetible, conciliación y muestreo firmados | Mezcla, pérdida o ambigüedad económica |
| 4. Paralelo ampliado | Comparar volumen acordado manteniendo fallback | Etapa 3 aprobada | Métricas, conciliación y soporte estables según umbrales aprobados | Diferencia de caja/stock/datos no explicada |
| 5. Decisión GO/NO-GO | Aprobar, limitar, repetir o retirar | Evidencias 0–4 | Acta con alcance, riesgos y fuente de verdad futura | Evidencia insuficiente |

## Criterios objetivos

### Bloquean GO

- Cualquier mezcla de empresa/sede o acceso no autorizado reproducible.
- Pérdida/corrupción, stock negativo, venta/cobro parcialmente persistido.
- Caja o importes no conciliados sin causa y aprobación.
- Backup externo/restore del entorno objetivo no probado.
- HTTPS, cuentas nominales o canal de soporte ausentes.
- Política crítica (impagos, caja, fuente de verdad, fiscal si aplica) sin dueño.
- P0 abierto o P1 sin workaround explícito aceptado.

### Permiten GO limitado

- Todos los controles anteriores superados.
- Regresión y smoke de la release exacta pasan.
- Usuarios completan flujos acordados sin ayuda bloqueante.
- Toda diferencia de paralelo está conciliada y explicada.
- Métricas tienen fuente, periodo, responsable y umbral aprobado.
- Rollback/fallback ensayado; sistema actual continúa disponible.

P2/P3/IDEA no bloquean por definición, salvo que dirección documente impacto
operativo diferente. No se exige “cero incidencias”; se exige riesgo conocido y
aceptado con evidencia.

DORLET puede quedar fuera de un GO limitado del back-office si dirección acepta
mantener el sistema actual de accesos. No puede declararse sustitución completa
de FitCloud mientras una operación imprescindible o el acceso físico dependan de
él; esa dependencia debe confirmarse durante la observación.

## Clasificación objetiva de incidencias

| Nivel | Regla |
|---|---|
| P0 | Impide un flujo crítico sin workaround seguro; pierde/corrompe/mezcla datos; cobro/venta inconsistente; brecha de seguridad crítica |
| P1 | Flujo termina con alto riesgo, incumple una regla aprobada o requiere intervención técnica; existe workaround controlado |
| P2 | Fricción frecuente y medible; operación termina correctamente con alternativa razonable |
| P3 | Comodidad, texto o estética sin riesgo material ni bloqueo |
| IDEA | Capacidad nueva; no es defecto del alcance congelado |

## Rollback del piloto

1. Responsable declara STOP y registra hora/alcance/última operación segura.
2. Detener nuevas escrituras en el nuevo sistema; no apagar el sistema actual.
3. Preservar logs, versión, base y evidencias; ejecutar backup si no agrava el incidente.
4. Reanudar exclusivamente en la fuente de verdad vigente.
5. Conciliar operaciones del intervalo para evitar doble cobro/venta/renovación.
6. Si el incidente es release, aplicar rollback técnico documentado; si es dato,
   restaurar primero en base independiente y aprobar alcance.
7. Reabrir el piloto solo tras causa, corrección, regresión y nueva aprobación.

## Soporte

Antes de etapa 2 completar:

| Función | Nombre/contacto | Horario | Sustituto | Autoridad |
|---|---|---|---|---|
| Responsable de negocio | PENDIENTE | PENDIENTE | PENDIENTE | Decide operación/fuente de verdad |
| Referente recepción | PENDIENTE | PENDIENTE | PENDIENTE | Confirma flujo real |
| Soporte técnico | PENDIENTE | PENDIENTE | PENDIENTE | Diagnóstico/rollback técnico |
| Operaciones/servidor | PENDIENTE | PENDIENTE | PENDIENTE | Backups, despliegue, monitor |
| Gestoría | PENDIENTE | PENDIENTE | — | Criterio fiscal |

Canal único P0/P1: PENDIENTE. El ticket debe incluir hora, rol/sede, flujo,
resultado esperado/observado, versión y correlación, nunca credenciales ni datos
personales innecesarios.

| ID | Gimnasio | Usuario/rol | Severidad | Inicio | Primera respuesta | Resolución | Tiempo soporte | Causa | Solución/workaround | Estado |
|---|---|---|---|---|---|---|---:|---|---|---|
|  |  |  |  |  |  |  |  |  |  |  |

## Acta

DECISIÓN: ☐ GO LIMITADO ☐ GO ☐ NO-GO ☐ REPETIR ETAPA

ALCANCE APROBADO: ________________________________________________________

EVIDENCIAS: ______________________________________________________________

PENDIENTES ACEPTADOS/RESPONSABLE: _______________________________________

FIRMAS Y FECHA: __________________________________________________________
