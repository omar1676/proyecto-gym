# Gimnera Restaurants — R02 Menu & Catalog Foundation

Estado: **release candidate local, sin rutas HTTP y sin datos reales**.

## Implemented

- Límite de catálogo `empresa → restaurant_account → brand → catalog`.
- Varios catálogos por marca, con estados `DRAFT`, `ACTIVE` y `ARCHIVED`.
- Asignación explícita de catálogos a locales de la misma marca.
- Categorías ordenadas por catálogo y relación producto–categoría M:N. Un producto no se duplica para aparecer en más de una categoría o carta.
- Producto Restaurant propio, independiente de `producto` Gym, con estados `DRAFT`, `ACTIVE`, `INACTIVE` y `ARCHIVED`.
- Variantes opcionales con identidad estable. Un producto simple sin variantes es válido.
- Grupos de modificadores a nivel de producto, modificadores ordenados y límites coherentes `min/max/required`.
- Importes exactos en minor units (`BIGINT`), moneda controlada y cero permitido. No se usan `FLOAT`/`DOUBLE`.
- Precios por ámbito `BRAND`, `LOCATION`, `CHANNEL` y `LOCATION_CHANNEL`, con historial inmutable de cada cambio.
- Disponibilidad separada del estado del producto y susceptible de ámbito de marca, local y canal.
- Canales contractuales iniciales: `IN_STORE`, `QR`, `TAKEAWAY`, `WEB`, `DELIVERY`. Declararlos no los activa ni integra.
- Declaraciones de alérgenos introducidas por una persona, con actor y fecha. El sistema no genera, infiere ni certifica alérgenos.
- Metadatos de imagen privada para JPEG/PNG/WebP, con clave opaca, hash y tamaño; sin endpoint de subida o descarga en R02.
- Defensa tenant/account/brand mediante claves compuestas en MariaDB y comprobaciones de servicio.
- Ciclo de vida central: un tenant cancelado/inactivo no admite mutaciones de negocio.
- Auditoría `REQUIRED`, correlation ID, optimistic locking y reintentos idempotentes.
- Servicios separados de catálogo, productos, modificadores, precios y disponibilidad.

## Decisiones arquitectónicas

### Producto y categorías

`restaurant_product` pertenece a una marca. `restaurant_product_category` lo vincula con una o varias categorías de catálogos de esa misma marca. Esto permite reutilizar el producto en «Carta principal» y «Delivery» sin clonar su identidad.

### Precio

`restaurant_price` almacena candidatos explícitos por ámbito. R02 **no decide automáticamente un precio ganador**: la precedencia entre marca, local y canal queda pendiente de la entrevista de dominio. La API interna devuelve candidatos para no esconder una regla comercial inventada.

### Modificadores

Los grupos se asignan al producto. La asignación diferente por variante no se implementa todavía: se añadirá solo si Jama demuestra que la necesita.

### Borrado e histórico

Las entidades referenciables se archivan o desactivan. No existe hard-delete de dominio en los servicios. Los cambios de precio conservan fila histórica y auditoría.

### Medios

R02 registra solo metadatos de un objeto previamente validado y almacenado fuera de `public`. No acepta rutas, nombres proporcionados por navegador, PHP, SVG ni formatos activos. No incorpora archivos binarios al repositorio o a MariaDB.

## Pending Jama

- Precedencia exacta de precio: marca/local/canal.
- Catálogos por franja horaria, temporada o día de negocio.
- Variantes y modificadores reales, límites y suplementos.
- Modificadores específicos de una variante.
- Taxonomía y responsabilidad legal de alérgenos.
- Monedas necesarias; R02 persiste únicamente EUR por la convención actual de Gimnera.
- Roles y scopes Restaurant definitivos.
- Si una marca puede compartir productos con otra marca del mismo holding.
- Reglas de publicación y disponibilidad por canal.

## Future

- Programación temporal de cartas.
- Precio efectivo cuando la precedencia haya sido validada.
- Recetas, ingredientes, coste y stock.
- Media upload/download privado tras definir autorización Restaurant.
- API headless versionada y rate-limited.

## Out of scope R02

- Pedidos, mesas, QR operativo, cocina/KDS, impresoras, pagos, delivery integrado.
- Stock, recetas, descuento de ingredientes o cálculo de coste.
- Fiscalidad, facturación, VeriFactu, TicketBAI o afirmaciones de cumplimiento.
- UI pública, rutas HTTP y reutilización de roles Gym.
- Datos o imágenes de Jama.

## Seguridad y RBAC

`RESTAURANT_RBAC_PENDING_JAMA`. Durante R02 solo una identidad global sintética, activa y sin tenant puede construir los servicios internos. Esto no concede al superadmin acceso implícito desde HTTP: no hay controladores ni rutas Restaurant publicados.

## Fixtures

Los tests usan exclusivamente `Gimnera Food Demo`, `Carta Principal`, `Burger Demo`, `Bebida Demo` y `Producto Simple`. Son nombres y datos sintéticos; no representan requisitos de Jama.
