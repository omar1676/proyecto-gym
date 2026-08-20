# Threat model reducido — futura integración DORLET

Estado: diseño preventivo. No existe adaptador ni conexión real.

| Amenaza | Impacto | Mitigación | Detección | Rollback |
|---|---|---|---|---|
| Credencial técnica comprometida | Lectura o modificación no autorizada del control de acceso | Cuenta dedicada, mínimo privilegio, secreto externo a Git, rotación, restricción de origen | Errores de autenticación, operaciones fuera de ventana, auditoría del proveedor | Revocar/rotar credencial, `disabled`, revisar operaciones |
| Usuario equivocado | Se modifica el permiso de otra persona | Un único usuario de prueba, scope tenant+sede, confirmación humana | Comparación de identidad interna/externa y correlación | Restaurar snapshot del afectado y revisar mapping |
| Mapping equivocado | Acceso indebido o bloqueo legítimo | Nunca vincular por nombre/email parcial; doble confirmación | Reconciliación y revisión de `INVALID_MAPPING` | Desactivar vínculo sin borrar evidencia; restaurar proveedor |
| Retry repetido | Reaplicación o tormenta de solicitudes | Idempotencia, máximo de intentos, backoff y rate limit | Métricas de intentos, duplicados y cola | Detener worker; conservar outbox para análisis |
| Replay | Reejecución de una orden antigua | Clave idempotente, correlación, versión lógica, timestamp/nonce si el contrato lo soporta | Duplicado, versión obsoleta o timestamp fuera de ventana | Rechazar; no cambiar estado; investigar credencial |
| Acceso indebidamente permitido | Riesgo físico y de privacidad | Fail-closed ante desconocido, read-only inicial, shadow, aprobación explícita | MISMATCH, evento de acceso, alerta operativa | Bloqueo oficial reversible y sistema actual paralelo |
| Acceso legítimo bloqueado | Interrupción del servicio y atención al socio | `REVISAR` no se traduce automáticamente, reglas aprobadas, alternativa operativa | MISMATCH, reclamación, evento denegado | Restaurar permiso previo mediante sistema actual |
| Proveedor caído | Cola acumulada y estado desactualizado | Timeouts, circuit breaker futuro, retries limitados, health normalizado | `UNAVAILABLE`, latencia y trabajos pendientes | `disabled`; operación con sistema anterior según política |
| SaaS caído | No se producen nuevas decisiones | Controladora/proveedor mantienen política offline documentada; sistema paralelo | Health SaaS y ausencia de workers | Continuar sistema anterior; restaurar SaaS sin tocar proveedor |

## Límites de confianza

- El navegador nunca selecciona tenant, sede, usuario, provider ni estado.
- Un external ID no determina por sí solo la empresa.
- Aceptar una sincronización no equivale a confirmar una entrada física.
- Un evento físico solo existe si el proveedor lo confirma con identidad,
  puerta/sede, timestamp e ID externo suficientes.
- Un fallo o dato desconocido nunca se transforma en permiso.

## Secretos

Nunca registrar ni versionar tokens, contraseñas, cookies, certificados privados
o respuestas completas que puedan contenerlos. Los errores visibles se
normalizan; el detalle técnico saneado queda restringido a soporte.

## Privacidad

El adaptador no debe solicitar, transportar ni persistir imágenes de huellas,
templates, minucias o hashes biométricos. Si el contrato mezcla identidad y
biometría en una misma respuesta, se exigirá una operación/campo minimizado o
se descartará esa vía hasta revisión legal y técnica.
