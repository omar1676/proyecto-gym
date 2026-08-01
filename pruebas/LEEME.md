# Pruebas

Scripts de comprobación manual. No hay framework: se ejecutan con el PHP de línea de
comandos y sacan por pantalla qué pasa y qué falla.

**Se ejecutan contra la base de datos configurada en `.env`.** No los lances contra
producción: `negocio.php` y `suplementos.php` crean y borran registros del socio de
pruebas (id 3) y dejan una venta anulada.

## Cómo lanzarlos

```bash
php pruebas/negocio.php       # ventas, stock, transacciones y membresías
php pruebas/suplementos.php   # cuota base + plus de artes marciales
php pruebas/render.php        # renderiza las 7 pantallas del panel
```

En Windows con XAMPP: `C:\xampp\php\php.exe pruebas\negocio.php`

`render.php` acepta el nombre de un método para probar una sola pantalla:

```bash
php pruebas/render.php mostrarVentas
```

> Ejecútalo así, una pantalla por proceso, si quieres pasarlas todas. En una sola
> ejecución solo funciona la primera: cada pantalla incluye `_header_admin.php`, que
> declara funciones a nivel global y no se puede incluir dos veces en la misma petición.
> En la aplicación real no ocurre porque cada petición carga una única vista.

## Qué cubren

| Script | Comprobaciones |
|---|---|
| `negocio.php` | Venta con descuento de stock, precios congelados, rollback por stock insuficiente, anulación con devolución, contratación y renovación encadenada |
| `suplementos.php` | Cuota base 40 €, plus 25 €/mes, total 65 €, plus multiplicado por meses en trimestral, suma en reportes |
| `render.php` | Que las 7 pantallas rendericen sin errores ni avisos y cierren el HTML |

Son repetibles: limpian el estado del socio de pruebas antes de empezar.
