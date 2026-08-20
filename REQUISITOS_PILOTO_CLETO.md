# Requisitos del piloto Cleto Reyes

Documento de decisión para dirección, Pedro y Dani. No convierte inferencias en
compromisos. Estados: `CONFIRMADO` (existe evidencia explícita), `INFERIDO`
(hipótesis a validar) y `PENDIENTE` (falta decisión o verificación externa).

## Matriz trazable

| ID | Área | Requisito | Fuente | Estado | Prioridad | Responsable de confirmar | Dependencia | Criterio de aceptación |
|---|---|---|---|---|---|---|---|---|
| PIL-001 | Alcance | El sistema actual y el nuevo convivirán durante el piloto | Encargo F12, `PILOTO_CLETO_REYES.md` | CONFIRMADO | P0 | Dirección | Matriz de fuente de verdad | Ningún proceso crítico se retira sin GO formal |
| PIL-002 | Acceso | DORLET/biometría permanece desconectado | Encargo F12, Fases 10–11 | CONFIRMADO | P0 | Dirección + técnico | `ACCESS_CONTROL_MODE=disabled` | No hay adaptador real ni órdenes a hardware |
| PIL-003 | Tenancy | Cleto solo accede a su empresa y sedes autorizadas | Tests multiempresa/multisede | CONFIRMADO | P0 | QA | Staging equivalente | Pruebas cruzadas denegadas sin fuga de datos |
| PIL-004 | Recepción | Buscar e identificar un socio durante la atención | `PRUEBA_USUARIOS_GIMNASIO.md` | CONFIRMADO | P0 | Pedro/Dani | Datos piloto | Usuario completa el flujo y registra tiempo/error |
| PIL-005 | Socios | Alta y edición conservan datos mínimos acordados | Inventario funcional | INFERIDO | P0 | Dirección + recepción | Definir campos obligatorios | Muestra aprobada sin campos ambiguos ni duplicados |
| PIL-006 | Membresías | Contratar/renovar una tarifa vigente | Inventario y pruebas actuales | CONFIRMADO | P0 | Pedro/Dani | Tarifas reales validadas | Operación controlada concilia socio, fechas e importe |
| PIL-007 | Impagos | Decidir efecto de deuda, devolución y días de gracia | `POLITICA_ESTADOS_ECONOMICOS.md` | PENDIENTE | P0 | Dirección | Criterio comercial | Regla escrita, aprobada y probada antes de automatizar bloqueo |
| PIL-008 | Caja | Decidir si venta/cobro exige caja abierta | `OPERATIVA_CAJA.md` | PENDIENTE | P0 | Dirección + recepción | Observación de turno | Regla y excepción operativa documentadas |
| PIL-009 | Caja | Apertura, movimientos y cierre cuadran por sede | Modelo v25 y tests | CONFIRMADO | P0 | QA + recepción | Datos controlados | Saldo esperado/declarado/diferencia coincide con recuento |
| PIL-010 | Ventas | Venta descuenta stock una sola vez | Tests de transacción, idempotencia y concurrencia | CONFIRMADO | P0 | QA | Staging | Ticket, línea, caja y stock concilian |
| PIL-011 | Ventas | Definir quién puede anular y con qué autorización interna | Matriz de permisos + visita pendiente | INFERIDO | P1 | Dirección | Procedimiento de caja | Cada anulación tiene motivo, usuario y conciliación |
| PIL-012 | Stock | Acordar stock inicial y tratamiento de diferencias | Plan de migración | PENDIENTE | P1 | Dirección + recepción | Export/inventario físico | Corte firmado y diferencias justificadas |
| PIL-013 | Remesas | No enviar remesa real sin doble revisión | Plan piloto anterior | CONFIRMADO | P0 | Dirección | Cuenta acreedora y banco | Fichero de prueba revisado; envío real fuera del piloto inicial |
| PIL-014 | Fiscal | Determinar suficiencia legal de ticket/series/IVA | `ANALISIS_FACTURACION.md` | PENDIENTE | P0 antes de sustitución | Gestoría + dirección | Respuestas documentadas | Dictamen operativo escrito; no se infiere desde código |
| PIL-015 | Migración | Solo usar exportación autorizada y trazable | Encargo F12 | CONFIRMADO | P0 | Dirección + responsable datos | Autorización y perfilado | Archivo, hash, fecha, alcance y custodio registrados |
| PIL-016 | Migración | Socios y productos pasan por dry-run antes de importar | Procedimiento actual | CONFIRMADO | P0 | Técnico + negocio | Staging | 0 errores P0; duplicados resueltos; recuentos conciliados |
| PIL-017 | Migración | Histórico económico no se importa hasta definir mapeo | `PLAN_MIGRACION_DATOS.md` | CONFIRMADO | P0 | Dirección + técnico | Modelo de origen | No se ejecuta importación económica destructiva o ambigua |
| PIL-018 | Operación | Cuentas nominales, nunca compartidas | Roadmap seguridad | PENDIENTE | P0 | Dirección | Usuarios reales | Cada acción sensible tiene actor identificable |
| PIL-019 | Producción | HTTPS, backups externos, restore y alertas operativos | Checklist producción | PENDIENTE | P0 | Operaciones | Staging/servidor | Evidencias del proveedor y restore medido |
| PIL-020 | SMTP | Recuperación de contraseña se valida sin datos reales | Checklist producción | PENDIENTE | P1 | Operaciones | Credenciales SMTP | Envío sintético y error controlado verificados |
| PIL-021 | Soporte | Existe responsable, canal, horario y escalado | Encargo F12 | PENDIENTE | P0 | Dirección + proveedor | `GO_NO_GO_PILOTO.md` | Contactos y tiempos acordados antes de usuarios reales |
| PIL-022 | Éxito | Decisión GO/NO-GO usa métricas y conciliación, no sensaciones | Encargo F12 | CONFIRMADO | P0 | Comité piloto | `METRICAS_PILOTO.md` | Acta con evidencias y pendientes aceptados |
| PIL-023 | Onboarding | Un nuevo gimnasio se provisiona de forma repetible | Test F12 | PENDIENTE | P1 | Técnico | Empresa/dirección aún por SQL | Segunda empresa supera prueba y brecha queda registrada |
| PIL-024 | Datos | No se copian datos reales a test | Política de pruebas | CONFIRMADO | P0 | QA | Base test aislada | Fixtures sintéticas y limpieza automática |
| PIL-025 | Empleados | Altas, roles y sedes reflejan responsabilidad real | Inventario funcional | INFERIDO | P1 | Dirección | Cuentas nominales | Muestra de usuarios/roles aprobada y prueba de no escalada |
| PIL-026 | Sedes | Dirección ve su empresa; recepción solo su sede | Tests multisede | CONFIRMADO | P0 | QA + dirección | Configuración real | Acceso permitido/denegado coincide con matriz aprobada |
| PIL-027 | Informes | Identificar qué reportes son operativos y quién los usa | Inventario funcional | PENDIENTE | P1 | Dirección | Observación real | Lista de informe, periodo, decisión y responsable |
| PIL-028 | Backups | Backup diario recuperable fuera del servidor | Estrategia/checklist | PENDIENTE | P0 | Operaciones | Proveedor real | Copia externa y restore medido con evidencia |

## Preguntas prioritarias para Pedro, Dani y dirección

Máximo deliberado: 24. Registrar respuesta, responsable y fecha; una respuesta
oral no se considera política aprobada hasta quedar escrita.

1. ¿Qué tres tareas de recepción deben poder terminarse con mayor rapidez?
2. ¿Qué dato usan de verdad para localizar a un socio cuando hay homónimos?
3. ¿Qué campos son imprescindibles al dar de alta y cuáles pueden completarse después?
4. ¿Debe abrirse caja obligatoriamente antes de vender/cobrar y existe una caja por turno, puesto o sede?
5. ¿Qué roles/personas abren y cierran caja; cómo se gestiona el relevo?
6. ¿Qué debe hacerse, aprobarse y registrarse ante una diferencia de caja?
7. ¿Cuántos días de gracia tiene un impago y desde qué fecha se cuentan?
8. ¿Una devolución bloquea acceso; quién puede desbloquear manualmente y con qué evidencia?
9. ¿Cómo regulariza el sistema un pago posterior, parcial o reintento de un recibo devuelto?
10. ¿En qué instante exacto se considera vencida una membresía?
11. ¿Existen congelaciones, bonificaciones, exenciones, cortesías o pruebas; quién las autoriza?
12. ¿Una renovación anticipada empieza hoy o al acabar la vigente?
13. ¿Qué métodos de pago se usan realmente para cuotas y ventas?
14. ¿Quién puede anular y qué autorización/motivo requiere; hay devoluciones parciales?
15. ¿Qué comprobante necesita socio, recepción, dirección y gestoría para cada operación?
16. ¿Qué cifra de stock inicia el piloto: sistema actual, recuento físico o conciliación?
17. ¿Qué informes usan de verdad a diario, semanalmente y al cierre de mes?
18. ¿Quién genera, revisa y presenta remesas; qué doble control existe?
19. ¿Qué histórico se debe migrar o conservar en consulta y durante cuántos años?
20. ¿Qué datos puede exportar el sistema actual (socios, cuotas, cobros, ventas, stock, remesas), en qué formato y con qué autorización?
21. ¿Qué sistema será fuente oficial de socios, cuotas, caja, ventas, stock, SEPA y acceso en cada etapa?
22. ¿Qué diferencias obligan a detener el piloto ese mismo día?
23. ¿Quién decide GO/NO-GO, atiende soporte y puede ordenar rollback fuera de horario?
24. Respecto a DORLET: ¿quién lo instaló/mantiene, quién es propietario del hardware y qué contrato/licencia existe?
25. ¿Autoriza Cleto una futura prueba DORLET aislada, reversible y coordinada con el mantenedor?
