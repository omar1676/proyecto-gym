# Auditoría: quién tocó qué

## A. Ya cubierto

`log_actividad` puede conservar usuario actor, fecha/hora, empresa, sede,
acción, entidad, ID afectado, usuario afectado, IP, detalle y valores
anterior/nuevo opcionales. Los listados filtran por empresa y sede. Ventas,
stock, caja, productos, cuotas, sedes, SEPA, exportaciones y varias operaciones
de socio generan eventos desde el controlador. El subsistema de acceso, aunque
está deshabilitado, tiene una auditoría separada con resultado y correlación.

## B. Parcialmente cubierto

- `entidad` e `id_entidad` son opcionales y muchas llamadas genéricas no los
  informan.
- Los valores anterior/nuevo se usan en algunos cambios de socio, no de forma
  uniforme en todos los cambios sensibles.
- El actor técnico de tareas se representa a veces como usuario nulo/cero.
- Se registra el intento exitoso de varias operaciones, pero no hay un campo
  estructurado de resultado para distinguir éxito, denegación y fallo.
- La cobertura está concentrada en `AdminController`; debe verificarse por flujo
  antes de afirmar exhaustividad.

## C. Falta

- `correlation_id` o `request_id` general;
- origen/canal (`web`, `cron`, futura API/móvil);
- resultado y código de motivo estructurados;
- identidad de proceso para acciones técnicas;
- política formal de retención/inmutabilidad y alerta ante fallo del propio log;
- cobertura consistente de denegaciones y fallos de negocio.

## Diseño propuesto, no aplicado

Una migración aditiva futura podría incorporar campos indexados y opcionales:

```text
correlation_id CHAR(36) NULL
origen VARCHAR(16) NOT NULL DEFAULT 'web'
resultado VARCHAR(16) NOT NULL DEFAULT 'SUCCESS'
reason_code VARCHAR(64) NULL
actor_process VARCHAR(64) NULL
```

No se recomienda guardar payloads completos. Antes/después debe limitarse a
campos realmente necesarios y con tamaño acotado. Se excluyen contraseñas,
tokens, cookies, CSRF, IBAN completo, secretos y biometría. Esta fase no cambia
el esquema: primero se aprobarán semántica, retención, volumen y cobertura.
