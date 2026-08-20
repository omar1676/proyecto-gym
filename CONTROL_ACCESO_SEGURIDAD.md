# Seguridad de la integración de control de acceso

## Frontera y confianza

- El navegador no decide empresa, sede, socio, rol, estado ni provider.
- `AccessEligibilityService` calcula el estado desde relaciones del servidor.
- `AccessControlRepository` comprueba empresa+sede+socio antes de mapear o
  encolar.
- Claves foráneas compuestas repiten esa garantía en MySQL.
- Un `external_identity_id` es opaco y nunca selecciona por sí solo el tenant.

## Permisos

| Permiso | Dirección | Admin sede | Recepción | Socio |
|---|---:|---:|---:|---:|
| `access.view` | Sí | Sí, su sede | No | No |
| `access.manage` | Sí | No | No | No |
| `access.sync` | Sí | No | No | No |
| `access.audit` | Sí | Sí, su sede | No | No |

`superadmin` conserva su permiso global, pero debe operar dentro de un tenant
seleccionado y auditado. No se ha creado UI administrativa en esta fase.

## Biometría

Fuera de alcance y prohibida en estas tablas y logs:

- imagen de huella;
- template/minutiae;
- rostro/iris;
- representación o hash biométrico.

El único vínculo previsto es:

```text
internal_member_id ↔ external_access_identity opaca
```

La custodia biométrica, base legal, consentimiento y retención corresponden al
sistema/proveedor autorizado y requieren revisión jurídica específica.

## Secretos y logs

Tokens, claves, contraseñas, cookies y configuración privada del provider deben
vivir fuera de Git y no se registran. La auditoría propia conserva actor/proceso,
empresa, sede, socio, provider, acción, decisión, reason code, resultado,
correlación y fecha.

## Amenazas cubiertas

- IDOR entre socios/empresas/sedes.
- Manipulación de external ID.
- Duplicados y reenvíos mediante idempotencia.
- Dos workers mediante bloqueo transaccional.
- Caídas mediante RETRY limitado y recuperación de lock obsoleto.
- Escalada de recepción mediante permisos centrales.
- Confusión entre decisión lógica, sincronización y evento físico.

## Riesgos abiertos

- Contrato, autenticación y seguridad del provider real: NO VERIFICADO.
- Conducta offline de controladoras: PENDIENTE DE POLÍTICA.
- Retención de eventos físicos: PENDIENTE.
- Red, VPN/agente local y hardening: PENDIENTE.
- RGPD biométrico y responsabilidades: PENDIENTE.

Ningún error técnico puede convertirse automáticamente en autorización física.

El análisis preventivo específico para la futura interfaz DORLET está en
`CONTROL_ACCESO_THREAT_MODEL_DORLET.md`. Continúa siendo un diseño: no existe
conexión ni adaptador real.
