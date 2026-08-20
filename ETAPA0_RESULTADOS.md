# Resultados de Etapa 0 — datos sintéticos

Fecha: 20/08/2026. Clasificación general: **VERIFICADO LOCAL**. No equivale a
una prueba de usuario ni a staging real.

## Escenario cargado

- 1 empresa sintética y 2 sedes sintéticas.
- 1 dirección, 1 administración, 2 recepciones y 4 socios ficticios.
- 3 productos, 3 membresías, 1 caja abierta, 2 ventas (1 anulada).
- 1 obligación pagada, 1 pendiente/devuelta y 1 vencida.
- 1 remesa marcada explícitamente como sintética y nunca enviada al banco.
- DORLET deshabilitado y correo bloqueado.

## Flujos

| Flujo | Nivel | Resultado | Tiempo medido | Error/observación |
|---|---|---|---:|---|
| Login de sede | HTTP local | VERIFICADO | Incluido en arnés HTTP de 3,0 s | 302 esperado |
| Login recepción/dirección | HTTP local | VERIFICADO | Incluido en arnés HTTP de 3,0 s | Cuentas nominales sintéticas |
| Búsqueda de socio | HTTP local | VERIFICADO | NO MEDIDO de forma aislada | Resultado `Ada` visible |
| Alta y edición de socio | Modelo productivo | VERIFICADO | Bloque usuarios 1.994,91 ms; búsqueda/edición/informe 14,15 ms | Datos ficticios |
| Membresía y cobro sintético | Modelo productivo | VERIFICADO | 61,91 ms | Sin dinero real |
| Caja | Modelo + HTTP local | VERIFICADO | 11,83 ms para apertura | Recepción accede; datos sintéticos |
| Venta y stock | Modelo + HTTP local | VERIFICADO | 79,72 ms junto con anulación | 1 venta activa |
| Anulación | Modelo productivo | VERIFICADO | Incluida en 79,72 ms | Stock devuelto; motivo sintético |
| Remesa simulada y devolución | Modelo productivo | VERIFICADO | 98,96 ms | `sent_to_bank=false` |
| Informes | HTTP/modelo local | VERIFICADO | Incluido en 14,15 ms para consulta de totales | Dirección 200; recepción 403 |
| Logout | HTTP local | VERIFICADO | Incluido en arnés HTTP de 3,0 s | Panel vuelve a requerir login |

Sembrado completo: **2.659,81 ms**. Los tiempos son técnicos del proceso local,
no tiempos humanos ni una métrica de UX.

## Incidencias del ensayo

La primera versión del arnés HTTP esperaba redirecciones para dos denegaciones
que la aplicación devuelve correctamente como `403`, y buscaba una palabra no
contractual en informes. Dio 3 fallos del arnés. Se corrigieron las expectativas
a la matriz real y la repetición terminó con 16/16 comprobaciones correctas.
No se cambió autorización para hacer pasar la prueba.

## Pendiente

- Repetir en staging real y medir cada flujo de usuario.
- Ejecución observada por Pedro/Dani.
- Resolución de reglas de negocio de caja, impagos, anulaciones y stock inicial.
