# Perfilado y conciliación de la migración piloto

No autoriza una exportación ni una importación. Se completa con una copia
autorizada, cifrada y custodiada; nunca con datos reales en la base de test.

## Autorización y custodia del export

- [ ] Responsable de Cleto autoriza por escrito finalidad, tablas/campos y fecha.
- [ ] El proveedor/sistema origen permite la exportación por el canal utilizado.
- [ ] Se designan exportador, receptor, custodio y fecha de borrado.
- [ ] Se excluyen contraseñas, huellas/templates, tokens y secretos.
- [ ] El fichero se cifra en tránsito y reposo; la clave viaja por canal distinto.
- [ ] Se registra nombre, tamaño, SHA-256, zona horaria, encoding y momento de corte.
- [ ] Se trabaja en staging aislado; queda prohibido enviar el fichero por chat/correo abierto.

## Perfilado por conjunto

| Conjunto | Recuento origen | PK/clave estable | Nulos críticos | Duplicados | Formato/encoding | Rango fechas | Importable hoy | Responsable |
|---|---:|---|---:|---:|---|---|---|---|
| Empresas/sedes |  |  |  |  |  |  | Revisión manual |  |
| Socios |  |  |  |  |  |  | Sí, CSV + dry-run |  |
| Tarifas |  |  |  |  |  |  | Configuración controlada |  |
| Membresías vigentes |  |  |  |  |  |  | Solo dry-run actual |  |
| Productos/stock |  |  |  |  |  |  | Sí, CSV + dry-run |  |
| Ventas/caja |  |  |  |  |  |  | No implementado |  |
| Mandatos/remesas |  |  |  |  |  |  | No autorizado aún |  |
| Histórico económico |  |  |  |  |  |  | Diseño, no importador |  |
| Accesos/biometría |  |  |  |  |  |  | FUERA; no exportar biometría |  |

## Controles de calidad

- Identificadores vacíos, repetidos, reciclados o con ceros iniciales.
- DNI/NIE, email y teléfono: formato, duplicidad y uso real; no inventar valores.
- Fechas: zona horaria, formato, extremos imposibles y semántica inicio/fin.
- Dinero: separador decimal, signo, moneda, IVA y suma de líneas/cabecera.
- Estados: catálogo origen, significado y correspondencia explícita con destino.
- Relaciones: socio–sede, membresía–tarifa, venta–líneas, remesa–recibos.
- Texto: UTF-8, tildes, ñ, caracteres de control y fórmulas CSV.
- Stock: fecha/hora de corte, reservas/movimientos posteriores y recuento físico.

## Ensayo

1. Copia inmutable del export autorizado y hash.
2. Transformación reproducible sin editar el original.
3. Dry-run en staging, informe de válidos/errores/duplicados.
4. Decisión humana sobre cada categoría de error; no aplicar defaults silenciosos.
5. Importación en staging vacío o tenant piloto aislado.
6. Conciliación y muestreo manual.
7. Repetir desde cero para probar reproducibilidad.

## Reconciliación

| Control | Origen | Destino | Diferencia | Tolerancia aprobada | Resultado | Firma |
|---|---:|---:|---:|---:|---|---|
| Socios totales/activos/inactivos |  |  |  | 0 salvo exclusiones firmadas |  |  |
| Membresías vigentes/vencidas |  |  |  | 0 |  |  |
| Importe obligaciones/cobros por estado |  |  |  | 0,00 € |  |  |
| Productos y unidades por sede |  |  |  | Según corte firmado |  |  |
| Ventas por día/método/estado |  |  |  | 0 y 0,00 € si se migran |  |  |
| Mandatos/remesas/recibos |  |  |  | 0 si se migran |  |  |

## Muestreo manual mínimo

La muestra la define negocio después del perfilado; no se fija un número
arbitrario. Debe cubrir como mínimo extremos y riesgos: activo, vencido, baja,
duplicado, impago/devuelto, tarifa/sede distinta, carácter especial, importe
cero/alto, venta anulada y relación completa. Para cada caso se compara campo a
campo y se firma `correcto`, `diferencia aceptada` o `bloqueo`.

## Criterio de parada

Detener ante mezcla de tenants, pérdida de relaciones, importación no idempotente,
importe no conciliado, archivo sin autorización/hash o regla de transformación
sin propietario. Nunca corregir el origen directamente durante el ensayo.

