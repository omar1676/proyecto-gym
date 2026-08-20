# ADR — Autorización lógica interna de acceso

## Estado

Aceptado para Fase 9. Integración física: pendiente y fuera de alcance.

## Decisión

`AccessEligibilityService` calcula uno de estos resultados explicables:

- `PERMITIDO`: socio activo, membresía/prueba vigente y sin incidencia económica conocida.
- `BLOQUEADO`: socio inactivo/suspendido o sin membresía vigente.
- `REVISAR`: deuda o recibo devuelto sin política comercial explícita de bloqueo.

Cada respuesta incluye un motivo legible. El servicio obtiene socio, empresa, sede y estado económico desde relaciones de servidor; no acepta `empresa_id`, `sede_id` ni rol del navegador.

## Política

Los valores opcionales viven dentro de `empresa.configuracion.access_policy`; no se añade una tabla ni editor hasta acordar reglas con Cleto. Por defecto:

- pruebas vigentes: permitidas;
- membresía vencida: bloqueada;
- impago/devolución: revisar;
- bloqueo por impago: desactivado.

## Límites de seguridad

El resultado es exclusivamente lógico. El código:

- no abre puertas;
- no llama SDK ni API de DORLET;
- no conoce IPs de controladoras;
- no captura ni almacena huellas/biometría;
- no concede acceso físico por sí mismo.

Una futura integración deberá consumir este resultado mediante un adaptador separado, autenticar el dispositivo, registrar la decisión aplicada y contemplar indisponibilidad. Esa fase requiere información real del hardware y política aprobada.
