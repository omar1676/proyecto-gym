# Gimnera Restaurants — Organization Foundation

Estado: candidate local `0.18.0-restaurants-foundation-rc1`. No desplegado y sin rutas públicas.

## Límite de dominio

`empresa` se utiliza temporalmente como organización/tenant de Platform. El dominio Restaurants no reutiliza `gimnasio`, `producto`, `venta`, `caja`, `socio` ni `membresia`.

La primera jerarquía persistente es:

```text
empresa (Platform tenant)
└── restaurant_account
    ├── restaurant_brand
    ├── restaurant_legal_entity
    └── restaurant_location ── brand + legal_entity
```

Una marca no es una entidad legal y una entidad legal no es un local. El local referencia ambas relaciones explícitamente.

## Invariantes v35

- Una organización Platform puede tener un único `restaurant_account` en esta primera versión.
- Toda raíz y todo descendiente conserva `id_empresa`.
- Las claves foráneas compuestas impiden enlazar account, marca, entidad legal o local de tenants distintos aunque el PHP falle.
- Slugs/códigos son únicos por tenant, no globalmente.
- El alta inicial de account, marca, entidad legal, local y auditoría es atómica.
- La misma UUID v4 y el mismo payload devuelven el mismo resultado; reutilizar la clave con datos distintos se rechaza mediante una huella determinista.
- Un alta con otra clave sobre una organización ya aprovisionada se rechaza.
- Solo un `superadmin` global activo, sin empresa ni sede, puede invocar el servicio.
- La organización debe estar `activa` y en lifecycle `ACTIVE`.
- La operación comparte el advisory lock del lifecycle del tenant, por lo que cancelación y aprovisionamiento no pueden confirmarse a la vez sin orden.
- Una auditoría REQUIRED fallida revierte las cuatro estructuras.

## Alcance deliberadamente excluido

No existen todavía rutas, UI, login, roles tenant de Restaurants, cartas, productos, pedidos, mesas, QR, cocina, pagos, fiscalidad, stock, delivery, integraciones, hardware ni datos de Jama.

Beauty no está disponible en este repositorio y su arquitectura continúa `NO VERIFICADA`. Esta foundation no presupone lenguaje, base de datos, identidad ni modelo de tenant de Beauty.

## Siguiente gate

El siguiente incremento recomendado, tras revisión de esta foundation y entrevistas de dominio, es `Menu/Catalog Foundation`. Antes de implementarlo deben fijarse al menos variantes, modificadores, alérgenos, alcance de precio/disponibilidad y la relación carta/canal/local.
