# Runbook de control de acceso — preparación y shadow

## Alcance actual

Solo existen cálculo lógico, provider genérico, provider mock, outbox MySQL y
modo shadow. No existe adaptador DORLET ni acción de apertura.

## Operación segura

Configuración inicial obligatoria:

```dotenv
ACCESS_CONTROL_MODE=disabled
ACCESS_CONTROL_PROVIDER=mock
ACCESS_CONTROL_ACTIVE_CONFIRM=false
```

Para una observación controlada puede usarse `shadow`. El cron técnico es:

```text
php cron/control_acceso.php
```

En `disabled` termina sin procesar. En `shadow` procesa exclusivamente
simulaciones. En `active` se detiene porque la interfaz real no está verificada.

## Estados de cola

- `PENDING`: intención confirmada localmente.
- `PROCESSING`: reclamada por un worker.
- `SYNCED`: resultado exitoso o simulación shadow.
- `RETRY`: fallo temporal con próximo intento.
- `FAILED`: agotó intentos; requiere revisión.

Métricas disponibles en el repositorio: trabajos por estado, número de
auditorías y latencia media. No se ha creado dashboard.

## Fallos y conducta

| Situación | Conducta del SaaS | Decisión física |
|---|---|---|
| SaaS caído | No genera nuevas intenciones | REQUIERE POLÍTICA OPERATIVA |
| Provider caído | RETRY limitado y auditoría | REQUIERE POLÍTICA OPERATIVA |
| Timeout/red caída | RETRY con backoff | Nunca asumir autorización |
| Base de datos caída | No confirmar trabajo | Nunca asumir autorización |
| Respuesta desconocida | FAILED/RETRY según contrato futuro | Nunca asumir autorización |
| Estado REVISAR | Conservar `REVISAR` | No convertir en permiso |
| Conflicto identidad | Rechazar y auditar | No remapear automáticamente |

## Criterios obligatorios antes de hardware

- [ ] Fabricante identificado.
- [ ] Lector y controladora identificados.
- [ ] Arquitectura y decisión offline entendidas.
- [ ] Interfaz oficial/documentada y autorizada.
- [ ] Backup de proveedor verificado.
- [ ] Rollback acordado con mantenedor.
- [ ] Autorización del gimnasio.
- [ ] Un único usuario de prueba autorizado.
- [ ] Ventana controlada.
- [ ] Sistema actual funcionando en paralelo.

Si falta uno, **NO REALIZAR PRUEBA FÍSICA**.

## Procedimiento futuro de primera prueba — NO EJECUTAR AHORA

1. Confirmar autorización y ventana.
2. Capturar estado inicial y backup del proveedor.
3. Seleccionar un único usuario responsable de la prueba.
4. Localizar su identidad externa sin biometría.
5. Leer su estado mediante interfaz oficial.
6. Compararlo con `AccessDecision` en shadow.
7. Sincronizar un cambio reversible y autorizado.
8. Leer de nuevo y verificar antes de probar físicamente.
9. Restaurar el estado original.
10. Confirmar que el sistema anterior sigue funcionando.

## Rollback

- Configuración SaaS: volver inmediatamente a `disabled`.
- Cola: detener cron; no borrar trabajos, conservar auditoría.
- Identidad: restaurar mediante operación oficial documentada.
- Permisos: restaurar snapshot previo del único usuario de prueba.
- Código: volver al tag anterior `v0.8.0-fase9`.
- Base SaaS: las tablas v26 son aditivas; conservarlas y dejar modo disabled.
- Base/configuración del proveedor: restauración exclusiva con procedimiento
  oficial, sin escrituras SQL directas.

El rollback debe dejar operativo el sistema anterior sin depender del SaaS.
