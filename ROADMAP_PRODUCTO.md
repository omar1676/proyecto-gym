# Roadmap de producto — candidatos

Las prioridades deben confirmarse con Pedro, Dani y dirección. Ninguna de estas
funciones se ha implementado automáticamente en Fase 7.

| CANDIDATO | VALOR | COMPLEJIDAD | DEPENDENCIAS | RIESGO | PRIORIDAD | FASE PROPUESTA |
|---|---|---|---|---|---|---|
| Ampliar migración a histórico económico | Evita alta manual del histórico | Alta | Export autorizado, mapeo, staging, conciliación | Duplicados y datos ambiguos | P0 solo si se exige para sustitución | Migración controlada |
| Política operativa de caja | Alinear la caja ya implementada con turnos reales | Baja técnica; decisión de negocio | Definir obligatoriedad, relevos y diferencias | Operar fuera de sesión o cuadrar mal | P0 de decisión | Etapa 0 del piloto |
| Factura legal/PDF | Necesidad fiscal potencial | Alta | Requisitos de gestoría y normativa | Cumplimiento fiscal | P1 por validar | Fase fiscal |
| Histórico de accesos | Soporte y trazabilidad de entradas | Alta | Proveedor y eventos normalizados | Privacidad y duplicados | P1 tras integración | Fase acceso |
| Entrada manual auditada | Resolver incidencias de acceso | Media | Políticas y autorización | Abuso interno | P1 | Fase acceso |
| Horarios/perfiles de puertas | Automatiza permisos físicos | Alta | Zonas, calendario y proveedor | Bloqueo o apertura incorrecta | P1 | Fase acceso |
| Aforo | Visibilidad de ocupación | Media/alta | Eventos fiables de entrada/salida | Conteo incorrecto | P2 | Después de acceso |
| Avisos de vencimiento | Reduce bajas involuntarias | Baja/media | SMTP y consentimiento | Entrega/reputación | P1 | Piloto posterior |
| Avisos de impago | Mejora recobro | Media | Estados de cobro conciliados | Mensaje incorrecto | P1 | Tras conciliación |
| Email por empresa | Comunicación con marca propia | Media | SMTP/remitente/plantillas | Spam y secretos | P1 | Antes de producción |
| SMS | Canal inmediato | Media | Proveedor, coste, consentimiento | Coste y privacidad | P3 | Futuro |
| Portal del socio | Autoservicio | Alta | Auth socio, `canOwn`, API segura | Exposición horizontal | P2 | Post-piloto |
| App móvil | Canal recurrente | Muy alta | API y portal maduros | Mantenimiento doble | FUTURO | Posterior |
| QR | Credencial sin biometría | Alta | Abstracción de acceso y revocación | Reutilización/capturas | P2 | Fase acceso posterior |
| NFC | Credencial rápida | Alta | Hardware y abstracción | Ciclo de credenciales | FUTURO | Fase acceso posterior |
| Credencial móvil | Mejor experiencia | Muy alta | App, proveedor, seguridad dispositivo | Pérdida/robo | FUTURO | Posterior |
| Reservas | Gestión de aforo/clases | Alta | Actividades, calendario, reglas | Sobreventa/no-show | P2 por validar | Roadmap |
| Estadísticas operativas | Apoyo a dirección | Media | Datos consistentes y KPIs acordados | Métricas engañosas | P2 | Tras piloto |
| Dashboards configurables | Adaptación por rol | Alta | Métricas y permisos | Complejidad/consultas | P3 | Futuro |
| Automatizaciones | Menos tareas repetitivas | Media/alta | Eventos, idempotencia, cron/colas | Acciones duplicadas | P2 | Incremental |
| Integraciones/API | Ecosistema y migraciones | Alta | Autenticación servicio, cuotas y auditoría | Ampliar superficie de ataque | P2 | Tras estabilización |
| IA para soporte/análisis | Potencial asistencia | Alta | Casos reales, minimización y evaluación | Privacidad/alucinaciones | FUTURO | Solo con caso validado |

## Orden recomendado

1. Completar piloto y cerrar P0/P1 observados por usuarios.
2. Acordar política de caja, fiscalidad y estrategia de migración.
3. Ejecutar fase independiente de acceso con proveedor documentado.
4. Añadir comunicación y autoservicio solo cuando los datos y procesos sean estables.
