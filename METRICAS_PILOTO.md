# Métricas del piloto

No hay umbrales prefijados sin línea base. Cada objetivo se aprueba tras medir
la operativa actual y debe indicar fuente, periodo y responsable.

| Métrica | Definición | Fuente | Línea base | Objetivo aprobado | Resultado | Responsable |
|---|---|---|---:|---:|---:|---|
| Tiempo buscar socio | Inicio de búsqueda → ficha correcta | Observación | PENDIENTE | PENDIENTE |  | Recepción |
| Tiempo renovar | Solicitud → confirmación conciliada | Observación/log | PENDIENTE | PENDIENTE |  | Recepción |
| Tiempo venta | Selección → ticket/stock/caja coherentes | Observación/log | PENDIENTE | PENDIENTE |  | Recepción |
| Ayuda por tarea | Tareas que requieren intervención / tareas observadas | Observación | PENDIENTE | PENDIENTE |  | QA |
| Error operativo | Tareas con error o vuelta atrás / total | Feedback | PENDIENTE | PENDIENTE |  | QA |
| Diferencia ventas | Nº e importe nuevo − actual por corte | Conciliación | 0 esperado | 0 sin justificar |  | Dirección |
| Diferencia caja | Declarado − esperado por cierre | Caja | PENDIENTE | Política pendiente |  | Responsable caja |
| Diferencia stock | Unidades nuevo − recuento/fuente vigente | Conciliación | PENDIENTE | PENDIENTE |  | Dirección |
| Incidencias P0/P1 | Abiertas/cerradas y tiempo de recuperación | Registro soporte | 0 | P0 abierto = bloqueo |  | Soporte |
| Disponibilidad observada | Ventanas operativas sin caída / observadas | Monitor | PENDIENTE | PENDIENTE |  | Operaciones |
| Backup recuperable | Último backup con restore probado y antigüedad | Logs/restore | PENDIENTE servidor | Según RPO aprobado |  | Operaciones |
| Satisfacción por flujo | Fácil/neutro/difícil + motivo, no promedio opaco | Entrevista | PENDIENTE | PENDIENTE |  | Product |
| Tareas completadas | Completadas sin ayuda / tareas intentadas, por flujo | Observación | PENDIENTE | PENDIENTE |  | QA |
| Diferencia de socios | Recuento/estado nuevo − fuente oficial | Conciliación | 0 esperado | 0 sin justificar |  | Dirección |
| Horas de soporte/gimnasio | Tiempo activo de soporte atribuible al tenant | Registro soporte | PENDIENTE | PENDIENTE |  | Soporte |
| Intención de pago | Sí/no/condicionada + condición y decisor | Entrevista comercial | PENDIENTE | PENDIENTE |  | Product |

## Reglas

- Registrar también tamaño de muestra y operaciones omitidas.
- No mezclar tiempos del sistema con decisión humana/espera externa.
- Una mediana puede resumir uso habitual; conservar máximos e incidentes.
- Una cifra sin fuente, periodo y responsable se marca `NO VERIFICADO`.
- Nunca usar datos personales en paneles o evidencias del piloto.
