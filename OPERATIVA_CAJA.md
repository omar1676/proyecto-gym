# Operativa de caja por sede

## Alcance

La caja representa efectivo físico por sede y turno. También conserva movimientos de tarjeta, transferencia y domiciliación para cuadrar la operación, pero solo `afecta_efectivo = 1` participa en el arqueo físico.

## Apertura

1. Elegir una sede concreta.
2. Ir a **Caja**.
3. Introducir el saldo inicial contado.
4. Confirmar.

La base de datos garantiza una única sesión `abierta` por sede mediante una columna generada e índice único. Dos aperturas concurrentes no pueden crear dos cajas.

## Movimientos automáticos

- venta: importe positivo;
- anulación: importe negativo compensatorio;
- cobro de membresía: positivo;
- devolución: negativo;
- tarjeta/SEPA/transferencia: visibles, pero no aumentan efectivo.

Si no existe sesión abierta, la venta/cobro no se pierde ni se bloquea: se registra con `id_sesion_caja = NULL`. Es una decisión de compatibilidad para el piloto y debe revisarse con dirección si quieren hacer obligatoria la apertura.

## Ajustes manuales

Dirección y administración pueden registrar entrada/salida de efectivo. El importe debe ser positivo; el sistema aplica el signo según el tipo. El motivo es obligatorio. Recepción no dispone de este permiso.

No se permite borrar movimientos desde la aplicación.

## Cierre

```text
esperado = saldo_inicial + suma(movimientos que afectan efectivo)
diferencia = saldo_declarado - esperado
```

El responsable cuenta e introduce el saldo declarado. El cierre congela esperado, declarado, diferencia, fecha, usuario y observación. Una diferencia nunca se oculta. Dos cierres concurrentes: solo uno puede transicionar la sesión de abierta a cerrada.

## Histórico y permisos

- dirección/admin/recepción: consultar y abrir/cerrar;
- dirección/admin: ajustes manuales;
- cada usuario solo opera dentro de empresa/sede calculadas por `TenantContext`;
- las sesiones cerradas son de solo lectura en la interfaz.
