# Arquitectura actual de acceso — evidencia estática FitCloud / DORLET

Fecha: 2026-08-25

Fuente: `Fitcloud_Foto_y_Huella.zip`

Método: hashes, cabeceras PE, recursos de versión, imports/exports, strings
controladas, configuración redactada e índices de archivos. No se ejecutó,
instaló, registró ni conectó ningún componente.

## Conclusión ejecutiva

El ZIP demuestra una aplicación Windows x86 que combina:

1. una interfaz local `fotoclientes.exe` para fotos, búsqueda de
   cliente/trabajador, altas/bajas y huellas;
2. comunicación remota SOAP/CGI con un host de `fitcloud.es`;
3. captura/enrolamiento local mediante MorphoSmart/ActiveMorphoKit;
4. operaciones denominadas `AnadirHuella`, `EliminarHuella`,
   `ObtenerHuella`, `AperturaPuerta`, `CierrePuerta` y `ResetASDx`;
5. firmware ASDx identificado por nombre como versión 1.34 / FitCloud 1.02 y
   una herramienta DORLET de grabación de firmware 5.4.0.

Esto demuestra componentes y capacidades, pero no constituye un contrato de
API soportado. No hay WSDL, documentación oficial, licencia de integración,
sandbox ni garantía de compatibilidad.

## Mapa de arquitectura y confianza

```text
FITCLOUD (host tenant bajo fitcloud.es)
        │
        │ SOAP/CGI + export PHP             [VERIFICADO ESTÁTICAMENTE]
        │ contrato/autenticación oficial    [DESCONOCIDO]
        ▼
SOFTWARE LOCAL WINDOWS x86
  fotoclientes.exe + configuracion.exe
        │
        ├── ActiveMorphoKit / MorphoSmart SDK 4.4
        │       │ captura/enroll/identify/verify/delete
        │       ▼
        │   MSO / lector Morpho-Sagem USB   [VERIFICADO EN SDK/DRIVERS]
        │
        ├── comandos huella/puerta/ASDx     [VERIFICADO EN STRINGS SOAP]
        │       │ transporte/ruta física exacta
        │       ▼
        │   DORLET / ASDx                   [PROBABLE]
        │       │ firmware y programador presentes
        │       ▼
        │   torno/puerta/controladora       [PROBABLE; HARDWARE NO OBSERVADO]
        │
        └── captura de foto VideoCapX       [VERIFICADO COMO CAPACIDAD]

GIMNERA
  AccessPolicyService
        │ ALLOW / DENY + identity id opaco
        ▼
  AccessControlProvider + outbox
        │
        └── provider real                   [NO IMPLEMENTADO / NO CONECTADO]
```

## Evidencia de FitCloud

`fotoclientes.exe`:

- SHA-256:
  `8446047A78EB0427A5F9FF48A592AE76861282E1B0B33CB88BD9E9BEDB668E17`.
- PE x86, 2.825.728 bytes, sin información de versión y sin firma válida.
- Importa `wsock32.dll`; contiene además componentes Indy/SSL enlazados.
- Contiene `/FitCloud.cgi`, un envelope SOAP y nombres de operaciones.
- Contiene una ruta `exportclientes.php` con un identificador de sesión en
  query string. No se utilizó ni se reconstruyó ningún valor.
- La configuración apunta a un subdominio específico de `fitcloud.es`,
  redactado, e indica acceso seguro.

Confianza:

- comunicación software local ↔ FitCloud: **ALTA**;
- SOAP/CGI como mecanismo usado por este build: **ALTA**;
- que ese CGI sea una API pública y soportada: **NO VERIFICADO**;
- semántica completa, autenticación, idempotencia y SLA: **DESCONOCIDO**.

## Identificador del cliente

Evidencia estática:

- etiquetas `Cliente/Trabajador`;
- símbolos/textos `codigocliente`;
- errores que mencionan `IdCliente`;
- búsqueda/operación de altas y bajas asociada a esa identidad.

La evidencia demuestra que el programa maneja un identificador de
cliente/trabajador, pero no demuestra:

- si es entero o string;
- longitud máxima;
- unicidad global o por recinto;
- estabilidad tras migraciones;
- si coincide directamente con el ID público de FitCloud.

Por tanto debe tratarse como `external_identity_id` opaco. El campo
`VARCHAR(190)` y el mapa tenant-aware de Gimnera son conceptualmente adecuados.

## Altas, bajas y control físico

Evidencia de alta/baja:

- interfaz `Altas/Bajas`;
- acciones `anadir_huella` y `eliminar_huella`;
- SOAP `AnadirHuella`, `EliminarHuella` y `ObtenerHuella`;
- actualización e información de lectores;
- mensajes que asocian la operación con un cliente.

Evidencia de control:

- SOAP `AperturaPuerta`, `CierrePuerta` y `ResetASDx`;
- textos `Torno Conectado`, número de lector, relés y versión ASDx;
- firmware `ASDx V134 FITCLOUD V102.mot`;
- programador DORLET 5.4.0.

No puede afirmarse por estática si la alta/baja modifica primero FitCloud, el
lector, una controladora o varias capas. Tampoco se ha demostrado el
comportamiento offline.

## Morpho / Sagem

El paquete incorpora SDK y drivers reales:

- ActiveMorphoKit Enrol 1.7 / producto 3.10;
- AMK_MSO300 3.8.1sdkmso-FDK;
- MorphoSmart SDK 4.4;
- MSO100 y MSO_SpUsb 4.4;
- drivers USB x86/x64 y probable servicio `serv_spusb.exe`.

Los exports incluyen capacidades de captura, enrolamiento, identificación,
verificación, almacenamiento/borrado en base biométrica y comunicación USB o
serie. Esto delimita la capa biométrica, pero no demuestra dónde guarda sus
templates la instalación real.

No se encontró ningún template, minucia, imagen biométrica ni base de clientes
dentro del ZIP.

## DORLET / ASDx

Está verificada la presencia de firmware ASDx y herramientas DORLET. Es
probable que ASDx sea la capa de controladora/torno, pero el paquete no aporta:

- modelo de controladora;
- topología física;
- SDK DORLET documentado;
- protocolo soportado;
- dirección o puerto de hardware;
- licencia para integrar;
- procedimiento oficial de rollback.

Una integración directa DORLET continúa siendo **NO-GO**.

## Red, base local y servicios

- Capacidad de red: **VERIFICADA** por sockets, SOAP/CGI, SSL y configuración.
- HTTP/HTTPS: presentes como capacidad; la configuración declara modo seguro.
- TCP/sockets: verificado como capacidad; puertos concretos no demostrados.
- UDP/WebSocket/REST: cadenas de librería insuficientes; **NO VERIFICADO**.
- DB local SQLite/MySQL/MariaDB/SQL Server/Access/Firebird: no encontrada.
- SQL embebido relevante en los ejecutables principales: no encontrado.
- Servicio Windows: `serv_spusb.exe` dentro de los CAB hace probable un
  servicio auxiliar Morpho USB; nombre registrado y ejecución no verificados.

## Configuración y credencial

`datoscfg` es Base64 con XML UTF-16. Contiene hostname FitCloud, flags de
acceso seguro/DORLET/torno, parámetros del lector y una credencial técnica no
vacía.

- `SECRET_FOUND=true`
- `CONFIG_SECRET_PRESENT=true`
- `ROTATE_AFTER_INVESTIGATION=true`

El valor no se muestra, no se probó y no se incorporará a Git. Base64 es
codificación, no protección criptográfica.

## Matriz de evidencia

| Componente | Función aparente | Evidencia | Confianza | Datos tratados | Integración posible | Riesgo |
|---|---|---|---|---|---|---|
| `fotoclientes.exe` | UI de clientes, fotos, huellas y accesos | PE, strings, SOAP, sockets | Alta | ID, foto y biometría en runtime | Solo con contrato oficial | Alto: unsigned/legacy |
| `configuracion.exe` | Configura host, lector, torno y DORLET | PE y XML asociado | Alta | Credencial/config técnica | No como API | Alto: secreto reversible |
| MorphoSmart SDK | Captura, enrola, identifica y verifica | Versiones, imports y exports | Alta | Templates/imagen en runtime | Servicio local soportado | Muy alto por biometría |
| Drivers Morpho | USB y servicio auxiliar | MSI/CAB/SYS/INF | Alta | Comunicación dispositivo | No desde SaaS | Alto: legado/firmas |
| FitCloud CGI/SOAP | Operaciones remotas | Ruta y mensajes SOAP | Alta como capacidad | ID y operaciones de huella/acceso | Candidato si el proveedor lo documenta | Alto: contrato desconocido |
| ASDx firmware | Firmware orientado a FitCloud | nombre + S-record | Media/alta | Control físico | No directamente | Crítico si se flashea |
| DORLET Grabador Flash | Programa firmware | MSI/EXE y metadata | Alta | Firmware/controladora | No como integración SaaS | Crítico: no ejecutar |
| VideoCapX | Captura de fotografía | OCX y dependencias multimedia | Alta | Foto en runtime | Fuera del Access Policy | Privacidad/legacy |
| `AccessPolicyService` | Decide autorización | código y tests F26 | Alta | socio/estado, no huella | Fuente lógica | Bajo mientras disabled |
| `AccessControlProvider` | Sincronización abstracta | interfaz + outbox | Alta | ID externo + decisión | Sí, futuro | Requiere confirmación real |

## Frontera recomendada para Gimnera

Orden de preferencia:

1. **API oficial FitCloud**, documentada, licenciada, con sandbox, identidad
   estable, idempotencia y confirmación de resultado.
2. **Servicio local soportado por FitCloud/DORLET**, aislado en el puesto y con
   contrato de comandos estable; Gimnera enviaría solo ID y ALLOW/DENY.
3. **Intercambio documentado** por fichero/cola/DB/API de integración oficial,
   nunca escritura directa en una DB de tercero sin soporte contractual.

Si ninguna existe, la conclusión correcta es `ninguna interfaz limpia
identificada`; los strings del binario no autorizan construir un cliente CGI
privado.

## Compatibilidad con F26

La frontera actual es conceptualmente compatible:

- `access_identity_map` representa `socio_id ↔ external_identity_id` sin
  biometría;
- `syncAccessDecision()` transporta una decisión y una clave idempotente;
- `findCredential()` permite validar el ID externo;
- `healthCheck()` y `getLastEvents()` cubren salud y conciliación;
- outbox, reintentos y auditoría evitan depender de una llamada web síncrona.

No es necesario cambiar la lógica de `AccessPolicyService`.

Antes de implementar un provider real conviene, según el contrato que se
obtenga:

- mantener `syncAccessDecision()` como operación central;
- añadir una interfaz separada y opcional para aprovisionamiento de identidad
  si el proveedor lo soporta, sin añadir biometría;
- distinguir confirmación remota, aplicada parcialmente y desconocida;
- declarar capacidades del provider;
- no incorporar `openDoor()` al puerto de políticas.

No se implementa ahora `syncIdentity()` ni `revokeAccess()` porque el contrato
real sigue sin conocerse. Un DENY sincronizado puede cubrir la revocación de
acceso; borrar una credencial o template es otra operación y no debe inferirse.

## Caso Pedro

```text
external_identity_id = X (opaco)
AccessPolicy = TEMPORARY
starts_at = inicio autorizado
expires_at = inicio + 3 días

durante vigencia  -> ALLOW
en expires_at     -> DENY
después           -> DENY

provider futuro   -> sincroniza X + DENY y devuelve confirmación
```

Gimnera no necesita conocer ni almacenar la huella.

## Fallo de sincronización

Contrato requerido:

```text
POLICY=DENY
PROVIDER_SYNC=FAILED
=> estado remoto NO CONFIRMADO
=> CRITICAL
=> nunca afirmar "puerta bloqueada"
```

El servicio actual no declara éxito: conserva `FAILED/RETRY`, audita el código
y devuelve resultado fallido. Sin embargo, `AccessControlSyncService` registra
todos los fallos con severidad `WARNING`, incluso un DENY no sincronizado.
Esto es un **P1 operativo antes de activar cualquier provider real**. No se ha
modificado en esta investigación estática.

## Riesgos principales

1. Credencial técnica potencialmente activa reversible dentro del ZIP.
2. Casi todos los binarios/instaladores carecen de firma válida.
3. Archivos ejecutables disfrazados como `.txt` y ZIP disfrazado como `.doc`.
4. SDK x86 y dependencias antiguas, incluido OpenSSL 0.9.8r.
5. Identificador de sesión incluido conceptualmente en una URL de exportación.
6. Ausencia de documentación/contrato de API y comportamiento offline.
7. Firmware/controladora sin modelo ni rollback demostrado.
8. Fallo de sincronización DENY aún no escala a CRITICAL en Gimnera.

## Estado

- API oficial FitCloud: **NO-GO**, contrato no demostrado.
- Adaptador local: **NO-GO activo**; candidato para sandbox/shadow solo tras
  documentación oficial y rotación de credencial.
- DORLET directo: **NO-GO**.
- Prueba física: **NO-GO** hasta disponer de autorización del mantenedor,
  inventario de hardware, equipo aislado, rollback y datos totalmente
  sintéticos.
- Biometría necesaria en Gimnera: **NO**.

## Próxima prueba segura

Solicitar a FitCloud/DORLET, sin entregar secretos del ZIP:

1. producto y versiones soportadas;
2. documentación oficial del endpoint o servicio local;
3. modelo de autenticación y sandbox;
4. semántica de alta/baja/deny y confirmación;
5. identificador externo estable;
6. modelo ASDx/controladora/lector y procedimiento oficial de rollback;
7. confirmación para rotar la credencial empaquetada.

Después puede diseñarse un harness sintético en `shadow`, todavía sin hardware
ni datos reales.
