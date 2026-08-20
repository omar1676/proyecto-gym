# Política de estados económicos

## Obligación de pago

Estados persistidos mínimos:

- `pendiente`: emitida y aún no cubierta; no ha vencido.
- `pagada`: la suma de cobros confirmados cubre el importe.
- `vencida`: sigue pendiente después del vencimiento o un cobro fue devuelto.
- `cancelada`: obligación anulada de forma explícita; no suma deuda.
- `exenta`: prueba o importe cero; no suma deuda.
- `revisar`: inconsistencia que requiere intervención.

Transiciones permitidas por los flujos actuales:

```text
pendiente → pagada
pendiente → vencida
vencida   → pagada
pagada    → vencida     (si el cobro confirmado es devuelto)
*         → cancelada   (futura corrección autorizada y auditada)
```

No existe una pantalla genérica para forzar estados arbitrarios.

## Cobro

- `presentado`: intento enviado/preparado para el banco.
- `confirmado`: dinero confirmado como recibido.
- `devuelto`: cobro presentado o confirmado que vuelve impagado.
- `anulado`: reservado para una corrección explícita futura.

Transiciones implementadas:

```text
presentado → confirmado
presentado → devuelto
confirmado → devuelto
```

Una segunda devolución se rechaza por condición de estado e idempotencia del movimiento compensatorio.

## Estado derivado del socio

- `AL_CORRIENTE`: deuda igual a cero.
- `PENDIENTE`: hay importe pendiente no vencido o cobro presentado.
- `IMPAGADO`: existe deuda vencida sin devolución registrada.
- `DEVUELTO`: existe devolución y deuda todavía abierta.
- `EXENTO`: prueba vigente sin obligación cobrable.
- `REVISAR`: reservado para datos incoherentes o política no resuelta.

Una membresía vigente y un pago correcto son conceptos independientes. Puede haber acceso temporal vigente con una incidencia económica.

## Política comercial pendiente

Una deuda o devolución **no bloquea automáticamente**. Hasta que Cleto Reyes defina días de gracia y tratamiento comercial, el acceso lógico pasa a `REVISAR`. La empresa puede guardar en `empresa.configuracion.access_policy`:

```json
{
  "permitir_pruebas": true,
  "bloquear_impagos": false,
  "dias_gracia_impago": 0
}
```

No se ha creado una pantalla para modificar esta política: requiere validación previa con dirección.
