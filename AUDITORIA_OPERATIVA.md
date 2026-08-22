# Política operativa de auditoría

La escritura de auditoría tiene dos modos explícitos:

- `BEST_EFFORT`: autenticación fallida, navegación, consultas y exportaciones. Si la tabla de auditoría no está disponible, la operación puede continuar, pero se genera `audit_write_failed` en el log técnico.
- `REQUIRED`: ventas/anulaciones, caja, stock, membresías/cuotas/cobros, mandatos/remesas, datos bancarios, cambios de rol, offboarding y cambios de contraseña.

`REQUIRED` solo puede ejecutarse dentro de la misma transacción que el efecto de negocio. Lanzar un error de auditoría después de confirmar una venta o un cobro daría al usuario un falso fallo y permitiría repetir la operación. Por ello, `LogModel` soporta el modo y falla de forma explícita, pero los flujos existentes que auditan después del commit permanecen `BEST_EFFORT` hasta que su auditoría se mueva dentro de su unidad transaccional.

Antes de usar datos económicos reales debe completarse esa integración transaccional para todas las acciones clasificadas `REQUIRED`. Esta decisión no bloquea la alpha sintética, pero sí el GO de datos reales.
