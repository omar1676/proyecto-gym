# Gimnera Restaurants — Domain Discovery Jama (R01)

Estado: **guion preparado; entrevista no ejecutada**  
Fecha de preparación: 2026-09-04  
Alcance: descubrimiento de dominio previo a Menu/Catalog, Orders, Kitchen, Stock, QR y Payments.

## Regla de evidencia

Cada conclusión debe llevar exactamente una de estas etiquetas:

- `VERIFICADO_POR_JAMA`: respuesta literal confirmada por una persona autorizada de Jama, con fecha y rol (no datos personales).
- `HIPOTESIS`: posibilidad que orienta preguntas, pero no autoriza diseño ni implementación.
- `PENDIENTE`: pregunta sin respuesta o respuesta todavía ambigua.
- `FUERA_DE_ALCANCE`: no se decide ni implementa en R01.

Una frase aportada previamente como contexto se conserva como `HIPOTESIS_CONTEXTO` hasta que Jama la confirme directamente. No se pedirán ni registrarán credenciales, pedidos, clientes, facturas ni otros datos reales.

## Evidencia disponible antes de la entrevista

### VERIFICADO_POR_JAMA

Ningún requisito de dominio ha sido verificado directamente durante R01. El contador de requisitos en este estado es **0**.

### HIPOTESIS_CONTEXTO (requiere confirmación)

- Jama Fusión opera restauración chifa peruana en Madrid.
- Se está formando o planificando una estructura holding con varias marcas/locales.
- Una empresa externa mantiene actualmente presencia web/carta y el coste comunicado rondaría 300 € trimestrales; falta precisar concepto, contrato y unidad de cobro.
- Se está trabajando en QR por mesa, pago y llegada de pedidos a cocina.
- Una arquitectura headless podría permitir conservar el frontend/proveedor actual y conectar un backend operativo de Gimnera.

Estas frases no son requisitos aceptados ni compromisos comerciales.

### FUERA_DE_ALCANCE R01

- Diseñar o crear tablas de carta, productos, pedidos, tickets, cocina, pagos o stock.
- Elegir PSP, TPV, KDS, impresora, delivery aggregator o proveedor fiscal.
- Consumir APIs, reverse engineering, conectar hardware o procesar pagos.
- Definir cumplimiento fiscal/legal o tratar datos personales reales.

## Protocolo de entrevista

1. Pedir primero que Jama describa su operativa actual sin enseñar una solución Gimnera.
2. Registrar expresiones literales de negocio, separando hechos actuales, excepciones y deseos.
3. Para cada respuesta anotar: etiqueta, fecha, rol que responde, alcance (holding/marca/sociedad/local/canal) y ejemplos sintéticos.
4. Si dos personas discrepan, marcar `PENDIENTE`; no escoger la respuesta más cómoda.
5. No solicitar accesos, exportaciones, capturas con clientes, tickets, credenciales o información fiscal identificable.
6. Cerrar cada bloque preguntando: “¿Qué excepción importante no hemos contemplado?”.

## 1. Organización, holding y alcance

Estado inicial: `PENDIENTE`.

- ¿Cuántas sociedades forman hoy el grupo y cuáles están solo planificadas?
- ¿Cuántas marcas existen hoy? ¿Cuántas se prevén a corto plazo?
- ¿Cuántos restaurantes/locales están operativos?
- ¿Una sociedad puede operar varias marcas?
- ¿Una marca puede operar mediante varias sociedades?
- ¿Un local pertenece siempre a una única sociedad legal?
- ¿Un local puede operar varias marcas o una cocina compartida?
- Si comparte cocina, ¿las cartas y pedidos siguen separados por marca?
- ¿Quién necesita visión global del grupo? ¿Quién solo puede ver una marca o un local?
- ¿Qué cambios organizativos son frecuentes (nueva marca, traspaso de local, cambio de sociedad)?

Salida esperada: diagrama confirmado `holding → brands ↔ legal entities → locations`, con cardinalidades y excepciones. La Foundation v35 demuestra técnicamente que una cuenta puede contener varias marcas, entidades y locales, pero no demuestra que ese modelo sea el de Jama.

## 2. Software, proveedores y costes actuales

Estado inicial: `PENDIENTE`.

- ¿Qué aplicaciones/proveedores usan actualmente y qué responsabilidad exacta tiene cada uno?
- ¿Quién gestiona web, carta, pedido online, QR, TPV, cocina, reservas, delivery, stock, caja y contabilidad?
- ¿Qué intercambios son manuales (copiar pedidos, cierres, hojas de cálculo, llamadas)?
- ¿Qué sistema consideran fuente de verdad para precios, disponibilidad, ventas y caja?
- ¿El coste es por local, marca, sociedad, web, dispositivo, pedido o volumen?
- ¿Qué contratos, permanencias o límites de exportación existen?
- ¿Qué proveedor no desean sustituir inicialmente?

No se piden credenciales. Las integraciones se clasifican después como `NECESARIA_MVP`, `DESEABLE`, `FUTURA` o `NO_NECESARIA`, indicando si existe API documentada, sandbox, webhook, exportación y contrato.

## 3. Recorrido de un pedido real

Estado inicial: `PENDIENTE`.

Pedir una narración completa, sin proponer estados:

1. Un cliente llega y se sienta.
2. Accede a la carta.
3. Decide y comunica el pedido.
4. El pedido llega a cocina/barra.
5. Se prepara y coordina.
6. Se sirve o entrega.
7. Se paga.
8. Se cierra mesa, pedido, turno y caja.

Repetir el relato para `SALA`, `QR`, `TAKEAWAY`, `WEB`, `DELIVERY`, `TELEFONO` y cualquier otro canal real. Por canal registrar:

- quién crea el pedido y con qué identidad/rol;
- dónde se fija precio y disponibilidad;
- cómo se enruta a cocina;
- formas de pago;
- condiciones de cierre;
- excepciones y resolución de errores.

## 4. Carta y disponibilidad

Estado inicial: `PENDIENTE`.

- ¿La carta pertenece a una marca, a un local o a una combinación?
- ¿Un local puede tener una carta distinta de otro de la misma marca?
- ¿Un producto puede existir solo en determinados locales o canales?
- ¿Cambian precios por local, canal u horario?
- ¿Hay franjas (desayuno/comida/cena), cartas temporales o eventos?
- ¿Quién publica y retira una carta? ¿Requiere revisión?
- ¿Quién marca agotados y con qué rapidez debe reflejarse en sala, QR, web y delivery?
- ¿Un producto puede estar agotado solo en un local o canal?
- ¿Qué debe ver un cliente cuando un producto deja de estar disponible durante un pedido?

## 5. Producto, variantes, modificadores y combos

Estado inicial: `PENDIENTE`.

Pedir ejemplos reales anonimizados de qué llaman “producto”: plato, bebida, menú, combo, suplemento u otra unidad comercial.

### Variantes

- ¿Qué diferencias consideran variante y cuáles producto distinto?
- ¿Existen tamaños, formatos, sabores, cantidades o puntos de cocción?
- ¿Una variante cambia precio, impuestos, disponibilidad, receta o estación?
- ¿Las variantes son comunes a la marca o específicas del local/canal?

Ejemplos como normal/doble, mediana/grande o 330/500 ml son solo preguntas, no requisitos.

### Modificadores

- ¿Se permiten exclusiones, extras, salsas, acompañamientos o punto de preparación?
- ¿Cuáles son obligatorios? ¿Mínimo/máximo de elecciones?
- ¿Modifican precio, receta, alérgenos o enrutado de cocina?
- ¿Dependen de producto, variante, local, canal u horario?
- ¿Cómo se expresa una combinación no permitida?

### Menús/combos

- ¿Hay composición por grupos (principal + bebida + acompañamiento)?
- ¿El cliente elige? ¿Hay precio fijo y suplementos?
- ¿Cómo se representa agotado o sustitución de un componente?
- ¿Cómo aparece el combo en cocina, ticket, stock y reporting?

No se diseña el catálogo hasta obtener estas reglas.

## 6. Alérgenos e ingredientes

Estado inicial: `PENDIENTE`; requiere propietario de dato y validación separada.

- ¿Cómo gestionan hoy la información de alérgenos?
- ¿Se asocia al producto final, receta, ingrediente, variante o modificador?
- ¿Quién mantiene y aprueba la información?
- ¿Dónde se muestra/imprime y con qué proceso de actualización?
- ¿Una modificación puede añadir o retirar un alérgeno declarado?
- ¿Qué ocurre ante sustituciones de ingredientes?

Gimnera no inferirá alérgenos ni ofrecerá consejo médico. Una respuesta de negocio no sustituye revisión legal/operativa.

Para ingredientes:

- ¿Necesitan solo productos finales o receta/escandallo?
- ¿Una venta debe descontar ingredientes automáticamente?
- ¿Cómo manejan rendimientos, unidades, conversiones y sustituciones?
- ¿Qué precisión necesitan para coste y merma?

Esta decisión condiciona Stock y no puede posponerse fingiendo que unidades de producto e ingredientes son equivalentes.

## 7. Stock, compras y merma

Estado inicial: `PENDIENTE`.

- ¿Qué inventarían: unidades, botellas, cajas, kg, litros o ingredientes?
- ¿Quién cuenta y con qué frecuencia?
- ¿Existen almacenes por local, cocina, barra o central?
- ¿Cómo registran merma, rotura, invitación, consumo interno y error?
- ¿Necesitan lotes, caducidades o trazabilidad? Si sí, separar fase y validación.
- ¿Gestionan proveedores, compras, precios históricos, albaranes y facturas?
- ¿Qué parte es imprescindible para MVP y cuál puede seguir en el sistema actual?

## 8. Precios, impuestos y fiscalidad

Estado inicial: `PENDIENTE`; fiscalidad = revisión especializada posterior.

- ¿Precio base por marca, local, canal u horario?
- ¿Cómo se resuelven promociones, suplementos y redondeos?
- ¿Los precios mostrados incluyen impuestos?
- ¿Qué tipos impositivos usan hoy y quién los configura/valida?
- ¿Qué sistema calcula hoy IVA y genera documentos fiscales?
- ¿Qué exportan a gestoría y qué necesitan al cierre?
- ¿Qué normativa/proveedor fiscal soporta el proceso actual?

No se declarará conformidad fiscal ni se implementará facturación real basándose en memoria o en esta entrevista.

## 9. Cocina y estados operativos

Estado inicial: `PENDIENTE`.

- ¿Cómo recibe cocina los pedidos: impresora, KDS, pantalla, papel u otro?
- ¿Existen estaciones/partidas (barra, caliente, frío, postres u otras)?
- ¿Un pedido se divide? ¿Quién coordina que salga completo?
- ¿Qué estados usan realmente, qué significan y quién los cambia?
- ¿Qué evento representa que cocina lo ha visto/aceptado?
- ¿Qué ocurre si un dispositivo o impresora falla?

### Cambio tras enviar a cocina

- ¿Se puede añadir, retirar o modificar una línea?
- ¿Quién lo autoriza y necesita motivo?
- ¿Cómo se notifica inequívocamente a cada estación afectada?
- ¿Cómo se conserva el original y la corrección en auditoría?
- ¿Qué pasa con precio, pago, stock y tiempos ya iniciados?

### Cancelaciones y devoluciones

- ¿Cómo distinguen cancelación total, línea cancelada, devolución, error, invitación y merma?
- ¿Quién puede ejecutarlas y qué aprobación/motivo se exige?
- ¿Se puede cancelar después de preparar, servir, cobrar o cerrar caja?

## 10. Pagos y caja

Estado inicial: `PENDIENTE`; PSP e integración quedan fuera de R01.

- ¿Qué medios usan: efectivo, tarjeta, QR/online, mixto u otros?
- ¿Se divide ticket por persona, artículos o importes?
- ¿Hay pagos parciales, depósitos, propinas, devoluciones o contracargos?
- ¿Qué sistema confirma el pago y cómo se concilia?
- ¿Qué ocurre si el pago se autoriza y el pedido no se actualiza (o al revés)?

### Caja y business day

- ¿Cuándo abre/cierra una caja o turno? ¿Quién lo hace?
- ¿Qué significa caja teórica y caja real para Jama?
- ¿Qué ocurre si olvidan cerrar? ¿Quién corrige, durante cuánto tiempo y con qué auditoría?
- ¿A qué hora termina realmente la jornada, especialmente después de medianoche?
- ¿Tiene sentido cierre automático? ¿Qué excepciones existen?

No se definirá `business_day` como día natural ni se fijará medianoche sin respuesta confirmada.

## 11. Reporting y rentabilidad

Estado inicial: `PENDIENTE`.

Pregunta abierta inicial: **“Cuando termina el mes, ¿qué información os gustaría conocer y hoy cuesta obtener?”**

Después aclarar si requieren margen/coste/merma por producto, local, marca, sociedad o canal; con qué fuente de coste y periodicidad. No prometer rentabilidad fiable si no existe escandallo/coste mantenido.

## 12. Clientes, reservas y retención

Estado inicial: `PENDIENTE`; no recoger datos reales.

- ¿Identifican clientes? ¿En qué canales y con qué propósito?
- ¿La identidad procede de reserva, cuenta, teléfono, email, QR o loyalty?
- ¿Necesitan historial o fidelización? ¿Quién puede verlo?
- ¿Importa detectar que un habitual deja de venir?
- Si importa, ¿existe una identidad suficientemente fiable y consentida para relacionar visitas?

Sin identidad fiable, Retention individual de restaurantes queda `NO_VIABLE/NO_DEMOSTRADO`, no se infiere.

## 13. QR, mesa y seguridad de contexto

Estado inicial: `PENDIENTE`.

- ¿QR único por mesa, genérico por local o solo carta?
- ¿El cliente se identifica? ¿Puede pedir, pagar o llamar a camarero?
- ¿Cómo nace y termina una sesión de mesa?
- ¿Cómo evitan pedidos desde fuera del local, QR fotografiado o mesa equivocada?
- ¿Qué ocurre al cambiar de mesa, unir mesas o dividir cuenta?
- ¿Quién puede invalidar/reimprimir un QR?

## 14. Web, delivery e integraciones

Estado inicial: `PENDIENTE`.

- ¿Qué entrega exactamente la empresa web actual: CMS, carta, pedido, pago, branding, hosting y mantenimiento?
- ¿Puede consumir una API y recibir estado de pedido? ¿Hay documentación y entorno de pruebas?
- ¿Quién posee dominio, contenido y datos? No solicitar accesos en la entrevista.
- ¿Qué canales delivery usan y cómo llegan los pedidos: tablet, integrador, TPV o transcripción manual?
- Por proveedor: ¿API oficial, partner agreement, sandbox, webhook y exportación?

No se promete Glovo/Uber Eats/Just Eat ni se hace reverse engineering. La compatibilidad headless sigue siendo `HIPOTESIS` hasta confirmar capacidades contractuales/técnicas.

## 15. Roles, ámbitos y auditoría

Estado inicial: `PENDIENTE`.

Inventariar roles reales, no reutilizar automáticamente los de Gym: holding, dirección, gerente, encargado, camarero, cocina, caja, administración y otros.

Por rol preguntar:

- alcance: holding, marca, sociedad, local, turno o estación;
- qué puede ver;
- qué puede crear/modificar/cancelar;
- qué necesita aprobación o separación de funciones;
- cómo se hace alta, cambio de local y offboarding.

Acciones candidatas a auditoría que Jama debe priorizar: cancelación/corrección de pedido, cierre/reapertura de caja, cambio de precio, agotado, merma, invitación, devolución y cambio de permisos. No se registrará éxito si la operación falla.

## Matriz de decisiones que debe producir la entrevista

| Decisión | Estado R01 | Bloquea |
|---|---|---|
| Cardinalidad brand/legal entity/location | PENDIENTE | Organización definitiva y scoping fiscal |
| Propietario y variantes de carta | PENDIENTE | Menu/Catalog |
| Variantes/modificadores/combos | PENDIENTE | Menu/Catalog y snapshot de pedido |
| Fuente/propietario de alérgenos | PENDIENTE | Publicación de carta |
| Producto final vs receta/ingrediente | PENDIENTE | Stock y rentabilidad |
| Precio por local/canal/horario | PENDIENTE | Catalog/Orders |
| Canales y flujo real | PENDIENTE | Order Core |
| Estados y cambios post-cocina | PENDIENTE | Order/Kitchen state machines |
| Partidas y dispositivo de cocina | PENDIENTE | Kitchen Core |
| Business day/cierre de caja | PENDIENTE | Caja/reporting |
| QR y sesión de mesa | PENDIENTE | Table + QR |
| PSP, split, refund y conciliación | PENDIENTE | Payments |
| Roles/ámbitos reales | PENDIENTE | RBAC Restaurants |
| APIs/contratos de proveedores | PENDIENTE | Headless/adapters |

## Gate antes de programar Menu/Catalog

`NO-GO` mientras no estén confirmados como mínimo:

1. propiedad de carta y alcance de producto/precio/disponibilidad;
2. diferencia producto/variante/modificador/combo;
3. responsabilidad y fuente de alérgenos;
4. necesidad de receta/ingredientes para stock;
5. reglas multi-brand/multi-legal-entity relevantes;
6. roles que mantienen y publican catálogo.

Tras la entrevista se actualizará este documento sin sustituir respuestas por interpretaciones. Cada cambio de `PENDIENTE` a `VERIFICADO_POR_JAMA` debe incluir evidencia de reunión sanitizada, no datos operativos reales.
