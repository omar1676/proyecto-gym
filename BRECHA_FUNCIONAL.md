# Brecha funcional frente a lo observado en FitCloud

Comparación limitada exclusivamente a la lista aportada tras la visita. No se
ha navegado, consultado ni intentado acceder a FitCloud.

| FUNCIÓN | NUESTRA APP | FITCLOUD OBSERVADO | ¿NECESARIO PARA CLETO? | PRIORIDAD | DEPENDENCIAS | FASE PROPUESTA |
|---|---|---|---|---|---|---|
| Clientes/socios | Alta, edición, búsqueda, estado y tenant completos | Presente | Sí, piloto | P0 | Ninguna nueva | Fase 7 completada |
| Tarifas/cuotas | Tipos, precios, duración y suplementos | Presente | Sí, piloto | P0 | Validación con usuarios | Piloto funcional |
| Cobros | Efectivo, datáfono, transferencia y SEPA | Presente | Sí, piloto | P0 | Conciliación operativa | Piloto/pospiloto |
| Facturación | Ticket numerado, IVA y CSV; no factura legal/PDF | Presente | Determinar con dirección/gestoría | P1 | Requisitos fiscales y series | Fase fiscal independiente |
| Caja | Totales por periodo y método; sin arqueo | Presente | Probablemente sí para sustitución | P1 | Flujo real de cierre | Tras piloto |
| Ventas | Venta transaccional, anulación e idempotencia | Presente | Sí, piloto | P0 | Ninguna nueva | Fase 7 completada |
| Inventario/stock | Stock por sede, mínimos y concurrencia | Presente | Sí | P1 | Definir compras/ajustes | Tras feedback |
| Histórico | Auditoría y listados de ventas/membresías | Presente | Sí | P1 | Paginación/exportación adicional | Tras piloto |
| Accesos | No existe integración física | Presente | No para piloto paralelo; sí para sustitución | P0 posterior | Hardware, contrato y API documentada | Fase de acceso independiente |
| Histórico de accesos | No existe | Presente | Sí cuando se integre acceso | P1 | Proveedor y modelo de eventos | Fase de acceso |
| Entrada manual | No existe como evento de acceso | Presente | Validar con recepción | P1 | Política y auditoría de accesos | Fase de acceso |
| Horarios de puertas | No existe | Presente | Validar antes de sustituir sistema | P1 | Zonas, calendario y proveedor | Fase de acceso |
| Personas en recinto/aforo | No existe | Presente | Útil, no imprescindible para piloto | P2 | Calidad de eventos entrada/salida | Posterior a acceso |
| Configuración empresarial | Empresa, sedes y marca básicas | Presente | Sí | P1 | Completar datos reales | Antes de producción |
| Parámetros de email | Configuración técnica por entorno | Presente | Sí para avisos | P1 | SMTP y remitente por empresa | Antes de producción |
| Parámetros SMS | No existe | Presente | No demostrado | FUTURO | Proveedor, coste y consentimiento | Roadmap |
| Configuración de app | No existe app | Presente | No para piloto | FUTURO | Portal/app y API | Roadmap |
| Perfiles/estados de acceso | Solo estado de socio/membresía; no perfil físico | Presente | Sí al integrar puertas | P1 | Mapeo de políticas y proveedor | Fase de acceso |

## Conclusión

La brecha que bloquea el piloto funcional no está en socios, cuotas, ventas o
stock. Para sustituir completamente el sistema actual sí serán necesarias una
definición fiscal/caja acordada y una fase específica de control de acceso. La
existencia de una función en FitCloud no se considera por sí sola requisito.
