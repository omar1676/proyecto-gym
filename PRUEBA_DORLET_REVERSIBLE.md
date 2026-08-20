# Prueba DORLET reversible — procedimiento futuro

Estado: **DISEÑADA, NO AUTORIZADA Y NO EJECUTADA**.

Este documento no autoriza llamadas, escritura, apertura ni prueba física. Se
utilizará únicamente después de recibir contrato oficial y cumplir todos los
criterios previos.

## Condiciones obligatorias

- [ ] Producto, versión, controladora y lector identificados.
- [ ] SDK/API oficial documentado y compatible.
- [ ] Licencia y soporte confirmados por DORLET/instalador.
- [ ] Autorización escrita del gimnasio.
- [ ] Backup del proveedor verificado y restauración documentada.
- [ ] Cuenta técnica dedicada de mínimo privilegio.
- [ ] Read-only probado sin hardware real.
- [ ] Shadow real probado sin modificaciones.
- [ ] Un único usuario voluntario y autorizado de prueba.
- [ ] Mapping confirmado por dos personas; nunca por coincidencia de nombre.
- [ ] Ventana, responsables y contacto del mantenedor presentes.
- [ ] Sistema actual funcionando en paralelo.
- [ ] Criterios de parada y rollback aprobados.

Si falta una condición, detener y no realizar la prueba.

## Alcance

- Una empresa.
- Una sede.
- Una puerta previamente acordada.
- Una identidad externa confirmada.
- Un único permiso reversible.
- Sin captura, lectura ni transferencia de biometría.
- Sin altas/bajas masivas, horarios globales ni aperturas remotas.

## Secuencia A–J

### A. Capturar estado

- Registrar fecha, responsables, versión y configuración no secreta.
- Obtener snapshot/backup oficial del proveedor.
- Guardar estado inicial del usuario, permiso, horario y puerta.
- Confirmar funcionamiento del sistema anterior.

### B. Leer identidad

- Usar la operación read-only oficial.
- Consultar únicamente el `external_identity_id` acordado.
- No listar usuarios ni solicitar biometría.

### C. Confirmar mapping

- Confirmación explícita por responsable del gimnasio y técnico.
- Verificar empresa, sede y usuario interno.
- Registrar correlación; no registrar secretos.

### D. Comparar shadow

- Calcular `AccessDecision` en el SaaS.
- Leer estado normalizado del proveedor.
- Registrar `MATCH`, `MISMATCH` o `UNKNOWN`.
- Un `MISMATCH` o `UNKNOWN` detiene la prueba.

### E. Cambiar un único permiso

- Solo con write habilitado expresamente, contrato y ventana activa.
- Enviar una operación idempotente y reversible.
- No abrir puerta y no tocar biometría.

### F. Verificar provider

- Leer de nuevo el mismo usuario.
- Confirmar acknowledgement, versión y propagación.
- Medir latencia sin asumir efecto instantáneo.

### G. Probar físicamente

- El propio usuario autorizado realiza la prueba en presencia del responsable.
- No usar comandos de apertura remota.
- Registrar exclusivamente resultado y hora necesarios.

### H. Restaurar permiso

- Restaurar exactamente el snapshot del usuario mediante operación oficial.
- No usar SQL directo ni edición manual de archivos.

### I. Verificar nuevamente

- Leer el estado restaurado.
- Confirmar funcionamiento del sistema anterior y de la puerta.
- Comparar con la captura de A.

### J. Revisar histórico

- Correlacionar intención SaaS, respuesta del provider y evento confirmado.
- Verificar que no existen reintentos o trabajos pendientes.
- Cerrar acta con resultado y anomalías.

## Criterios de parada inmediata

- Identidad dudosa o mapping inconsistente.
- Respuesta malformada o estado no documentado.
- Timeout repetido, rate limit o error de autenticación.
- Cambio sobre otro usuario, puerta, horario o sede.
- Diferencia entre lectura y estado visible en el sistema actual.
- Pérdida de comunicación con proveedor o controladora.
- Imposibilidad de restaurar inmediatamente.

## Rollback

1. Deshabilitar escritura y volver el SaaS a `disabled`.
2. Detener el worker sin borrar outbox ni auditoría.
3. Restaurar el permiso del único usuario por la operación oficial.
4. Verificar la restauración desde el proveedor.
5. Si no coincide, aplicar el procedimiento de backup del mantenedor.
6. Mantener el sistema anterior en operación.
7. No reintentar hasta análisis conjunto y nueva autorización.

## Evidencia de cierre

- [ ] Estado inicial y final equivalentes.
- [ ] Sistema anterior operativo.
- [ ] Ninguna otra identidad afectada.
- [ ] Cola sin trabajos pendientes del ensayo.
- [ ] Auditoría completa, sin secretos ni biometría.
- [ ] Incidencias y tiempos documentados.
