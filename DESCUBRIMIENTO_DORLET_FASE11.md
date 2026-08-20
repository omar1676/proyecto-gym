# Fase 11 — Descubrimiento técnico DORLET

- Fecha: 20/08/2026.
- Método: observación aportada por el gimnasio, inspección local pasiva y
  consulta de fuentes públicas oficiales.
- Interfaz instalada: **NO VERIFICADA**.
- Escritura sobre proveedor o hardware: **NO REALIZADA**.

## Resultado ejecutivo

DORLET confirma públicamente que DASSnet es su plataforma de gestión y que
comercializa un **SDK Integración módulo de accesos**, referencia `D9110400`,
además de un SDK de dispositivos `D9110300` y uno de visitas `D9110500`.
Esto demuestra que existe una vía oficial potencial, pero no demuestra qué
producto ni versión están instalados en Cleto Reyes, que el módulo esté
licenciado, ni que su contrato sea compatible con el SaaS.

La web pública no proporciona métodos, tipos, autenticación, endpoints,
eventos, esquema de errores, rate limits, compatibilidad de versiones ni
procedimiento de rollback. La documentación técnica de DORLET requiere acceso
de cliente. Por ello **no existe contrato suficiente para implementar
`DorletAccessControlProvider`** y el provider real continúa bloqueado.

## Inventario consolidado

| Elemento | Marca | Modelo | Versión | Función | Evidencia | Estado |
|---|---|---|---|---|---|---|
| Terminal/lector de acceso | DORLET | No identificado | No identificada | Identificación en el acceso | Observación comunicada por el gimnasio | OBSERVADO / modelo NO VERIFICADO |
| Enrolador USB | IDEMIA | MSO 300 | No identificada | Captura/enrolamiento de huella | Observación comunicada por el gimnasio | OBSERVADO; función coherente con documentación oficial |
| Software de gestión usado por empleados | FitCloud | Módulo de accesos | No identificada | Permisos, horarios e históricos | Observación comunicada por el gimnasio | OBSERVADO / relación técnica NO VERIFICADA |
| Plataforma DORLET candidata | DORLET | DASSnet | No identificada | Gestión de control de accesos, perfiles, horarios y eventos | Catálogo oficial DORLET | PRODUCTO EXISTENTE; instalación NO VERIFICADA |
| SDK de integración de accesos | DORLET | Ref. D9110400 | No publicada | Integración software con el módulo de accesos | Catálogo oficial DORLET | EXISTENCIA VERIFICADA; contrato/licencia PENDIENTES |
| SDK de dispositivos | DORLET | Ref. D9110300 | No publicada | Integración de dispositivos | Catálogo oficial DORLET | EXISTENCIA VERIFICADA; relevancia PENDIENTE |
| SDK de visitas | DORLET | Ref. D9110500 | No publicada | Integración del módulo de visitas | Catálogo oficial DORLET | EXISTENCIA VERIFICADA; fuera del caso principal |
| Controladora/UCA | No identificada | No identificada | No identificada | Decisión local, relés y persistencia de permisos | Ninguna evidencia de la instalación concreta | NO VERIFICADO |

No se ha identificado el modelo del terminal DORLET, la controladora, su
firmware, el servidor DORLET ni una instalación concreta de DASS/DASSnet.

## Evidencia local pasiva

En el PC de trabajo actual no se encontraron nombres coincidentes en:

- servicios Windows;
- procesos activos;
- software instalado registrado;
- directorios de programa de primer nivel;
- documentos de Escritorio o Documentos.

Términos comprobados: DORLET, DASS, DASSnet, FitCloud, IDEMIA, MSO 300 y
Morpho. No se leyeron configuraciones privadas, credenciales ni bases de datos.
Tampoco se consultaron puertos ni tráfico.

Este resultado solo describe el PC inspeccionado. No demuestra qué software
existe en el PC o servidor real del gimnasio.

## Evidencia oficial pública

1. [DASSnet](https://www.dorlet.com/es/productos/software/dassnetr): DORLET lo
   describe como su plataforma modular de gestión integrada de seguridad.
2. [Control de accesos DASSnet](https://www.dorlet.com/es/productos/software/control-de-accesos/control-de-accesos):
   gestiona perfiles, rutas, calendarios, horarios, personas, elementos de
   campo y listados de eventos.
3. [Integración software](https://www.dorlet.com/es/productos/software/integracion-software):
   el catálogo enumera SDK de accesos, dispositivos y visitas.
4. [SDK Integración módulo de accesos](https://www.dorlet.com/es/productos/software/integracion-software/sdk-integracion-modulo-de-accesos):
   producto oficial con referencia `D9110400`, sin contrato técnico público.
5. [SDK Integración de dispositivos](https://www.dorlet.com/es/productos/software/integracion-software/sdk-integracion-de-dispositivos):
   producto oficial con referencia `D9110300`.
6. [Área documental](https://docs.dorlet.com/): requiere cuenta de cliente;
   no se intentó iniciar sesión.
7. [Obsolescencia DASS/DASSnet](https://www.dorlet.com/es/noticias/empresa/actualizate-en-2023):
   DORLET indica que DASS está fuera de soporte y que DASSnet 2.0 quedó
   obsoleto al finalizar 2023. Esto obliga a conocer la versión instalada.
8. [IDEMIA MSO 300 — fin de vida](https://biometricdevices.idemia.com/sfc/servlet.shepherd/document/download/069cy00000II1MwAAL):
   IDEMIA comunicó el fin de vida del MSO 300/301. No cambia la prohibición de
   consumir o custodiar biometría desde el SaaS.

La existencia comercial de un SDK no equivale a disponer de licencia,
documentación contractual ni compatibilidad con la instalación.

## Arquitectura: hechos e inferencias

### VERIFICADO

- DASSnet es capaz de gestionar identidades, permisos, horarios y eventos de
  control de accesos según DORLET.
- DORLET comercializa un SDK específico para el módulo de accesos.
- El SaaS ya dispone de un puerto agnóstico, outbox, idempotencia, auditoría y
  mock sin dependencia de DORLET.

### INFERIDO

La vía razonable sería que un adaptador técnico autorizado se comunique con
DASSnet o con un servicio oficial del SDK, no directamente con lectores,
controladoras ni tablas internas. El componente podría necesitar ejecutarse en
Windows o cerca del servidor DASSnet, pero esto debe confirmarlo el contrato.

### NO VERIFICADO

- que Cleto Reyes use DASSnet y no DASS u otra capa;
- la relación técnica FitCloud ↔ DORLET;
- ubicación de la fuente de verdad;
- arquitectura cliente/servidor;
- base de datos o servicio empleado;
- versión, módulos y licencias;
- funcionamiento offline de la controladora instalada.

## Clasificación de vías de integración

| Vía | Clasificación | Condiciones |
|---|---|---|
| SDK oficial DORLET de accesos | PREFERIDA | Contrato D9110400, versión compatible, licencia, soporte, read-only y sandbox |
| API/webservice oficial | PREFERIDA | Solo si DORLET confirma documentación, autenticación y soporte para la versión instalada |
| Servicio local oficial | ACEPTABLE | Ejecutable endurecido, cuenta dedicada, comunicación autenticada y mantenimiento del proveedor |
| Importación/exportación documentada | ACEPTABLE | Útil para altas/bajas no inmediatas; requiere idempotencia y confirmación |
| Base intermedia soportada | SOLO ÚLTIMO RECURSO | Únicamente mediante vistas/procedimientos oficialmente soportados; nunca tablas internas libres |
| Integración no documentada o ingeniería inversa | DESCARTADA | Sin garantía, rollback, compatibilidad ni soporte |
| Endpoints privados de FitCloud | DESCARTADA | Expresamente fuera de alcance |

## Decisión sobre el adaptador

`DorletAccessControlProvider` **NO IMPLEMENTADO**.

Motivo: el nombre y referencia comercial del SDK no constituyen un contrato de
software. Crear clases, DTO o llamadas ahora inventaría autenticación, tipos y
semántica. Se conserva exclusivamente `MockAccessControlProvider`.

Tampoco se añaden variables `DORLET_*` a `.env.example`: solo deberán aparecer
cuando la documentación oficial determine qué configuración existe realmente.

## Contrato mínimo que debe entregar DORLET/instalador

- producto y versión exactos instalados;
- módulo/licencia SDK contratados y referencia compatible;
- lenguaje/runtime y requisitos del SDK;
- operaciones read-only para salud, identidad y eventos;
- autenticación, rotación y mínimo privilegio;
- identificador único de identidad, sede, puerta y evento;
- paginación/cursor, orden, duplicados y retención de eventos;
- estados y errores oficiales;
- connect timeout, request timeout y rate limits;
- conducta offline y propagación hacia controladoras;
- sandbox/fake oficial o entorno de integración;
- backup, restauración, soporte y rollback;
- prohibición o separación de datos biométricos.

## Diseño read-only futuro

Si se recibe el contrato, el primer adaptador deberá iniciarse con:

```dotenv
ACCESS_CONTROL_PROVIDER=dorlet
DORLET_READ_ONLY=true
```

Los nombres reales de endpoint y credenciales no se definirán hasta conocer el
contrato. Con read-only solo podrán ejecutarse:

- `healthCheck()`;
- `findCredential()` para una identidad confirmada;
- `getLastEvents()` con límite y cursor.

Cualquier intento de `syncAccessDecision()` deberá devolver `UNSUPPORTED`
antes de iniciar comunicación mientras `DORLET_READ_ONLY=true`.

## Normalización propuesta — pendiente de contrato

### Salud

`AVAILABLE`, `DEGRADED`, `UNAVAILABLE`, `UNKNOWN`.

### Errores

`AUTH_ERROR`, `NOT_FOUND`, `TIMEOUT`, `RATE_LIMIT`, `PROVIDER_ERROR`,
`UNSUPPORTED`, `INVALID_MAPPING`.

Nunca se mostrará la respuesta interna del proveedor a recepción.

### Eventos

`ACCESS_GRANTED`, `ACCESS_DENIED`, `UNKNOWN`. Un resultado del SaaS o una
sincronización aceptada no se registran como entrada física.

## Shadow y reconciliación real futuros

El shadow real debe leer un único usuario confirmado y comparar sin escribir:

| SaaS | Estado proveedor normalizado | Resultado |
|---|---|---|
| PERMITIDO | ACTIVO | MATCH |
| BLOQUEADO | INACTIVO | MATCH |
| PERMITIDO | INACTIVO | MISMATCH |
| BLOQUEADO | ACTIVO | MISMATCH |
| REVISAR o estado no traducible | cualquiera | UNKNOWN |

La traducción `ACTIVO/INACTIVO` solo podrá definirse con semántica oficial. Un
`MISMATCH` genera evidencia y revisión; nunca corrección automática.

## Offline, consistencia y límites

**NO VERIFICADO:** dónde residen los permisos, cuánto persisten, qué decide la
controladora y qué ocurre si Internet, FitCloud, DASSnet o el SaaS caen.

La expectativa futura será:

```text
cambio SaaS → outbox → aceptación provider → propagación → confirmación
```

No se asumirá sincronización inmediata. Timeouts, reintentos y rate limits se
configurarán únicamente según documentación o mediciones autorizadas. La cola
de Fase 10 será el único mecanismo de entrega.

## Privacidad

El SaaS no necesita imágenes, templates, minucias ni hashes biométricos. Aunque
un SDK pudiera exponerlos, esos endpoints quedan excluidos. El MSO 300 se
considera un componente administrado por el sistema autorizado del proveedor,
no una fuente de datos para este proyecto.

## Criterios para escritura

| Criterio | Estado |
|---|---|
| Contrato/API oficial | PENDIENTE |
| Credenciales dedicadas | PENDIENTE |
| Permiso escrito del gimnasio | PENDIENTE |
| Backup/configuración del proveedor | PENDIENTE |
| Usuario único de prueba | PENDIENTE |
| Rollback aprobado | DOCUMENTADO, NO VERIFICADO |
| Read-only probado | PENDIENTE |
| Shadow real probado | PENDIENTE |
| Identidad confirmada | PENDIENTE |
| Sistema actual paralelo durante la prueba | PENDIENTE DE CONFIRMACIÓN |

Conclusión: **NO IMPLEMENTAR ESCRITURA**.
