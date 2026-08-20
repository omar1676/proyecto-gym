# Modelo económico operativo — Fase 9

## Situación anterior

El sistema conservaba correctamente el precio histórico de `socio_membresia`, las líneas de venta y los recibos SEPA. Sin embargo, `socio_membresia` representaba a la vez contrato, cuota y pago mediante `estado_pago`. Una contratación domiciliada nacía como pagada aunque el banco aún no hubiese confirmado el recibo. Los informes sumaban contratos creados como si fueran dinero cobrado y no existía caja por turno.

## Modelo resultante

```text
SOCIO
  └─ socio_membresia       contrato y periodo de acceso
       └─ obligacion_pago  importe que corresponde cobrar
            └─ cobro       intento/confirmación/devolución del dinero

VENTA ── venta_linea       operación comercial y precio congelado
  └─ caja_movimiento       reflejo operativo (no borra la venta)

REMESA ── remesa_recibo    agrupación bancaria y resultado por recibo
               └─ cobro    intento domiciliado trazable

CAJA_SESION (empresa + sede + turno)
  └─ caja_movimiento       efectivo y métodos no físicos diferenciados
```

`socio_membresia.estado_pago` se conserva como sombra de compatibilidad. Las decisiones nuevas se calculan desde `obligacion_pago` y `cobro`; el campo heredado se sincroniza, pero ya no es la fuente de verdad.

## Reglas de dinero

- MySQL conserva importes con `DECIMAL(12,2)`.
- PHP convierte importes a céntimos mediante `Money` para sumar, comparar y calcular deuda.
- No se usan datos de precio enviados por el navegador.
- El contrato, la obligación, el cobro y las líneas de venta congelan el precio de su momento.
- Una actualización del catálogo no reescribe el histórico.

## Métodos normalizados

| Método operativo | Valor económico |
|---|---|
| `efectivo` | `efectivo` |
| `datafono` heredado | `tarjeta` |
| `transferencia` en membresías | `domiciliacion` al crear el recibo SEPA |
| transferencia ajena a SEPA | `transferencia` |
| no clasificable | `otro` |

El valor heredado `transferencia` se mantiene en `socio_membresia` para no romper v1–v24, pero la interfaz de membresías lo presenta como “Domiciliación (SEPA)”.

## Deuda

Por cada obligación:

```text
pendiente = max(importe_obligacion - suma(cobros_confirmados), 0)
deuda_socio = suma(pendiente)
```

Un cobro `devuelto` deja de sumar como confirmado. El cobro original no se borra. Un pago posterior puede volver a dejar la deuda en cero sin eliminar la devolución del histórico.

## Compatibilidad histórica de v25

- Cada membresía previa recibe una obligación por su importe congelado.
- Efectivo/datáfono previamente marcados como pagados reciben un cobro confirmado.
- Las domiciliaciones se derivan de `remesa_recibo`; no se fía su pago al valor heredado por defecto.
- Cada recibo previo recibe un cobro presentado, confirmado o devuelto según su estado.
- Las ventas previas reciben movimiento operativo sin inventar una sesión de caja histórica.
- Las ventas anuladas reciben además su compensación negativa.

La migración es aditiva y no borra ventas, contratos, recibos ni remesas.
