# Auditoría arquitectónica previa — Gimnera Restaurants

Fecha: 2026-09-03

Estado: **FASE 0 COMPLETADA / IMPLEMENTACIÓN CONDICIONADA**

Datos utilizados: exclusivamente código y fixtures sintéticos.

## 1. Fuente de verdad inspeccionada

| Línea | Commit | Estado |
|---|---|---|
| `main` / `origin/main` | `e12a9f036aaa3a4741de67ab1571fe48ca8f9b0f` | F24.1 estable, esquema v32 |
| `integration/f25a-f26` | `5e494564ffe6394d419c918dd245eae648996618` | Training v33 + Access Policy v34, no está en `main` |
| `security/p1-closure` | `fd1c0ca3c702ad644bad4f68490bb092fa0cf3bd` | cierre operativo en línea separada |
| Beauty | — | **NO VERIFICADO**: no hay repositorio Beauty en este workspace |

La rama de trabajo `feature/restaurants-foundation` nació desde `origin/main`.
No se ha modificado `main`, staging ni ninguna base real.

## 2. Diagnóstico del sistema actual

Gimnera es hoy un monolito modular incipiente, pero el lenguaje ubicuo y varias
entidades centrales siguen siendo específicamente Gym:

- `empresa` es el tenant SaaS y puede evolucionar a organización de plataforma;
- `gimnasio` mezcla sede, branding, credencial de acceso de nivel 1, datos
  fiscales/bancarios y configuración del centro;
- `usuario` mezcla identidad autenticable, empleado, socio, rol Gym, sede, DNI,
  IBAN, foto y datos de baja/anonimización;
- `producto`, `venta`, `caja_*`, `socio_membresia` y `tipo_membresia` expresan
  operaciones Gym, no conceptos genéricos de comercio;
- `Authorization` contiene roles y permisos Gym;
- `TenantContext` reconstruye el contexto desde el login en dos niveles del
  gimnasio y no es todavía un contexto de identidad multiproducto;
- `AdminController` concentra 1.700+ líneas y no debe recibir Restaurants;
- el árbol conserva deuda histórica del portal de cursos en el esquema inicial
  y en la limpieza legacy de `UserModel`.

Conclusión: renombrar estas piezas como “Platform” produciría una abstracción
falsa y aumentaría el riesgo de romper Gym.

## 3. Arquitectura incremental recomendada

La opción de menor riesgo es un **modular monolith** durante la primera etapa:

```text
Gimnera runtime / infraestructura compartida
├── Platform (contratos futuros, no SSO todavía)
│   ├── Organization
│   ├── Identity
│   ├── Product entitlement
│   └── Audit / files / observability contracts
├── Gym (código existente; sin movimiento masivo)
├── Beauty (adaptador futuro tras auditoría propia)
└── Restaurants
    ├── Domain
    ├── Application
    └── Infrastructure
```

No se recomiendan microservicios, tres bases operativas ni SSO en esta fase.
Separar código y tablas por dominio ofrece un boundary auditable sin introducir
fallos distribuidos, duplicar operación o reescribir Gym.

### Reglas de boundary

1. Todas las tablas de Restaurants usan prefijo `restaurant_`.
2. Toda raíz Restaurants lleva `id_empresa`; ningún tenant se acepta desde el
   navegador sin validarlo contra el contexto del servidor.
3. Las relaciones críticas incluyen defensa tenant-aware mediante índices/FK
   compuestas cuando una PK aislada permitiría cruces.
4. Restaurants no consulta ni escribe `socio`, `socio_membresia`, `producto`,
   `venta`, `caja_*`, `gimnasio` o tablas de Training/Access.
5. El código Restaurants no se añade a `AdminController`.
6. Los eventos de auditoría usan la infraestructura común mediante un adaptador;
   no acoplan el dominio a textos o roles Gym.
7. Beauty solo tendrá un adaptador cuando su arquitectura real sea auditada.

## 4. Matriz de reutilización

| Componente actual | Clasificación | Decisión |
|---|---|---|
| `Database` / PDO seguro | `REUSE_AS_IS` | Infraestructura común por entorno. |
| `Money` | `REUSE_AS_IS` | Céntimos/DECIMAL; Restaurants definirá impuestos y snapshots propios. |
| `RequestContext` / correlation ID | `REUSE_AS_IS` | Contexto técnico común. |
| `SafeException` / `AppLogger` | `REUSE_AS_IS` | Logging técnico saneado. |
| CSRF, headers, sesión segura | `REUSE_AS_IS` | Controles HTTP comunes; no implican SSO. |
| Release, backup, restore, health | `REUSE_AS_IS` | Infraestructura, extendiendo manifiestos y checks de esquema. |
| Migrador | `REUSE_WITH_ABSTRACTION` | Válido en el monolito; necesita numeración lineal coordinada. |
| `empresa` | `PLATFORM_CANDIDATE` | Usar como tenant puente; no renombrar ni ampliar de forma masiva todavía. |
| `TenantLifecyclePolicy` | `REUSE_WITH_ABSTRACTION` | Protege la organización; no sustituye lifecycle de cada producto. |
| `LogModel` / auditoría | `REUSE_WITH_ABSTRACTION` | El envelope es útil; columnas y actor siguen parcialmente ligados a Gym. |
| `TenantContext` | `DO_NOT_REUSE` como Platform | Puede alimentar un adaptador temporal, pero no es Gimnera ID. |
| `Authorization` | `DO_NOT_REUSE` como RBAC global | Roles actuales son Gym. Restaurants necesita permisos propios. |
| `UserModel` / `usuario` | `DO_NOT_REUSE` como Identity | Conflación de identidad, socio y empleado. Requiere futura separación. |
| `GimnasioModel` / `gimnasio` | `GYM_SPECIFIC` | No representar un local de restaurante con esta tabla. |
| `ProductoModel` / `producto` | `GYM_SPECIFIC` | No sirve como plato/variante/modificador/disponibilidad multicanal. |
| `VentaModel` / `venta` | `GYM_SPECIFIC` | No sirve como pedido canónico ni state machine de cocina/pago. |
| `CashModel` | `GYM_SPECIFIC` | Reutilizar patrones, no tabla ni reglas. |
| `FinancialModel`, SEPA, membresías | `GYM_SPECIFIC` | No reutilizar para order/payment/refund/settlement. |
| Stock Gym | `DO_NOT_REUSE` | Stock restaurante requiere receta, consumo, mermas y unidades propias. |
| `PrivatePhotoStorage` | `REUSE_WITH_ABSTRACTION` | Patrón seguro; Restaurants requerirá stores y políticas por tipo de media. |
| Provider/outbox/retry de Access | `REUSE_WITH_ABSTRACTION` | Patrón útil para pagos/KDS/canales, nunca tablas o estados Access. |
| Idempotencia/concurrencia | `PLATFORM_CANDIDATE` | Reutilizar contrato y técnicas; keys y locks quedan por agregado/tenant. |

## 5. Holding, marcas, entidades legales y locales

Para la primera iteración, el holding se representa por el tenant raíz
`empresa`. No se crea otra tabla “holding” duplicada sin un caso de holding
anidado demostrado.

El dominio Restaurants añade raíces propias:

```text
empresa (tenant / holding puente)
  └── restaurant_account
        ├── restaurant_brand
        ├── restaurant_legal_entity
        └── restaurant_location
              ├── brand
              └── legal_entity
```

Esto permite que marca, entidad legal y local sean conceptos distintos. Un
local pertenece al mismo tenant que su marca y entidad legal; la base debe
rechazar cruces incluso si el PHP falla.

`restaurant_account` representa la activación del vertical dentro del tenant
durante esta etapa. Es un puente deliberado, no el diseño final de billing o
suscripciones de Platform.

## 6. Identidad, roles y Gimnera ID

No debe implementarse todavía Gimnera ID sobre `usuario`:

- el correo solo debe localizar una identidad, nunca decidir un producto;
- la relación futura será `identity -> organization -> product entitlement -> role/scope`;
- una misma identidad podrá pertenecer a organizaciones/productos distintos;
- el superadmin global no adquiere contexto tenant implícito;
- los roles Restaurants no deben añadirse al enum Gym de `usuario`.

Hasta diseñar la identidad común, la fundación Restaurants no expondrá login ni
UI de operador. Los tests de aplicación usarán un contexto explícito y
sintético creado por servidor. Esto evita consolidar una deuda de identidad.

## 7. Beauty

Beauty se considera un producto real declarado por negocio, pero técnicamente
**NO VERIFICADO**. No se presupone lenguaje, base, sesión, roles, tenancy ni
seguridad. La futura integración requiere:

1. inventario y threat model de Beauty;
2. comparación de identidad/tenant/sesión/auditoría;
3. contrato de integración (redirect, token exchange o gateway);
4. migración incremental, nunca copia de tablas por coincidencia de nombres.

Restaurants no depende de completar esa auditoría.

## 8. Riesgo de migraciones entre ramas

`main` termina en v32. Training ya reserva v33 y Access Policy v34 en
`integration/f25a-f26`. El migrador enumera ficheros de forma contigua y se
detiene ante el primer número ausente.

Por tanto:

- **PROHIBIDO** crear Restaurants como v33 desde `main`;
- **PROHIBIDO** añadir v35 dejando ausentes v33/v34;
- **PROHIBIDO** renumerar v33/v34 históricos ya publicados;
- la implementación persistente de Restaurants debe incorporar como ancestro
  explícito `integration/f25a-f26` y comenzar en v35;
- el futuro Merge Gate debe integrar primero v33/v34 y después v35.

Esto no significa que Training/Access estén en `main`; significa que la rama
Restaurants declara una dependencia de migración visible y revisable.

## 9. Primer núcleo recomendado

El mejor primer paso es **Restaurant Organization Foundation**, limitado a:

- cuenta Restaurants tenant-bound;
- marcas;
- entidades legales;
- locales;
- estados mínimos y versionado optimista;
- provisioning atómico e idempotente;
- aislamiento multiempresa y constraints tenant-aware;
- auditoría técnica segura mediante adaptador.

Se excluyen expresamente:

- carta/productos;
- pedidos, mesas y QR;
- pagos, refunds y fiscalidad;
- KDS, impresoras y hardware;
- stock y delivery;
- login/SSO/RBAC global;
- integraciones o datos de Jama.

Los fixtures usarán organizaciones sintéticas que no representen clientes
reales.

## 10. Gate de implementación

La implementación puede comenzar solo si:

1. la rama Restaurants conserva `main` como ancestro;
2. `integration/f25a-f26` se incorpora únicamente a esta rama, nunca a `main`;
3. la suite base de la integración está verde;
4. v35 añade checks estructurales al migrador;
5. ninguna ruta se despliega ni se conecta a staging;
6. los tests usan una DB temporal exclusiva;
7. la suite demuestra atomicidad, idempotencia y rechazo cross-tenant.

## 11. Decisiones pendientes que no deben inventarse

- Arquitectura real y contrato de integración de Beauty.
- Modelo final de Gimnera ID y recuperación de cuenta multiproducto.
- Catálogo de roles Restaurants y delegación por local.
- Relación exacta entre holding y varias organizaciones legales si cruzan
  tenants comerciales.
- Proveedor de pagos, settlement y fiscalidad.
- Responsabilidad de carta/web entre Gimnera y el proveedor actual de Jama.
- Canales, KDS, impresoras y operación offline.

Estas decisiones no bloquean la fundación estructural propuesta, pero sí los
módulos posteriores.

## 12. Veredicto Fase 0

- Reorganización física masiva de Gym: **NO-GO**.
- Renombrar tablas Gym como Platform: **NO-GO**.
- Microservicios/SSO común ahora: **NO-GO**.
- Modular monolith con boundary Restaurants: **GO**.
- Restaurant Organization Foundation en v35, sobre ancestro v34: **GO
  CONDICIONADO A SUITE BASE VERDE**.
- Menú, pedidos, QR, cocina, pagos y datos reales: **NO-GO en esta fase**.
