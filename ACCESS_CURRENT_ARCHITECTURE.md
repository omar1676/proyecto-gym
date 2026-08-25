# Arquitectura actual de acceso — evidencia disponible en F26

## Resultado de la inspección estática

**NO VERIFICADO:** el paquete solicitado como “Fitcloud Foto y Huella” no está
presente en el worktree, adjuntos disponibles ni ubicaciones auditadas. Se
revisaron nombres de archivos/directorios y los índices internos de los ZIP sin
extraer ni ejecutar contenido. No se encontraron ejecutables, DLL, INI, XML,
bases locales, servicios o manuales pertenecientes a ese cliente.

Por ello este documento separa lo que demuestra el repositorio de lo que solo
recogen documentos anteriores. No se ha usado ninguna credencial, endpoint,
protocolo privado ni dato biométrico.

## Mapa con nivel de evidencia

```text
Empleado
  │ usa
  ▼
FitCloud / módulo de accesos                  [OBSERVADO comunicado; build NO VERIFICADO]
  │ relación técnica exacta                   [DESCONOCIDA]
  ├──────────► DASS/DASSnet o servicio local  [HIPÓTESIS; instalación NO VERIFICADA]
  │                 │
  │                 ├──► controladora         [DESCONOCIDA]
  │                 └──► lector DORLET        [OBSERVADO comunicado; modelo NO VERIFICADO]
  │
  └──────────► enrolador IDEMIA MSO 300       [OBSERVADO comunicado; uso USB coherente,
                                                integración concreta NO VERIFICADA]

Gimnera
  └──► AccessControlProvider + outbox mock     [VERIFICADO EN CÓDIGO]
          └──► DORLET real                     [NO IMPLEMENTADO / NO CONECTADO]
```

## Verificado en el código de Gimnera

- `AccessEligibilityService` calcula elegibilidad interna por socio y tenant.
- `AccessPolicyService` añade excepciones temporales, suspensión, denegación y
  bloqueo sin conocer fabricantes.
- `AccessControlProvider` define una frontera sin `openDoor()` y sin biometría.
- `AccessControlSyncService` soporta `disabled`, `shadow` y `active` confirmado.
- El único provider implementado es `MockAccessControlProvider`.
- `access_identity_map` guarda un identificador externo opaco, no una huella.
- `access_sync_job` es el outbox y `access_control_audit` su trazabilidad.
- Staging fuerza `ACCESS_CONTROL_MODE=disabled` desde configuración.

## Evidencia documental previa, no revalidada con el cliente FitCloud

Los documentos `DESCUBRIMIENTO_DORLET_FASE11.md` y
`CONTROL_ACCESO_INVENTARIO.md` registran:

- uso observado/comunicado de FitCloud por empleados;
- un lector DORLET cuyo modelo, firmware y controladora no se identificaron;
- un enrolador IDEMIA MSO 300 comunicado;
- existencia comercial de SDK DORLET, pero sin contrato, versión ni licencia;
- ausencia de documentación privada y de un adaptador real.

Estas afirmaciones no prueban qué proceso, puerto, base de datos o protocolo usa
la instalación de Cleto Reyes.

## Desconocido y bloqueante para un adaptador real

1. Producto y versión exacta de FitCloud.
2. Sistema operativo y arquitectura del puesto.
3. Lista de procesos/servicios instalados por FitCloud/DORLET.
4. Relación entre FitCloud, DASS/DASSnet y la controladora.
5. Modelo/firmware de lector y controladora.
6. Propietario, instalador, contrato de soporte y licencia de SDK.
7. API/SDK oficial compatible, autenticación, idempotencia y sandbox.
8. Semántica offline y fuente de verdad cuando Internet o Gimnera fallen.
9. Identificador externo no biométrico que pueda mapearse de forma reversible.
10. Procedimiento de shadow/read-only y rollback autorizado por el mantenedor.

## Material mínimo para continuar la investigación

Entregar una copia autorizada y sanitizada de los instaladores/manuales o, de
forma preferente, una exportación de inventario con:

- nombres, versiones y firmas de ejecutables/DLL;
- nombres de servicios y rutas de configuración sin secretos;
- esquema de componentes del instalador;
- manual/contrato del SDK oficial;
- versión de DASS/DASSnet y modelos de hardware;
- contacto del instalador/mantenedor.

La siguiente inspección seguirá siendo estática: hashes, firmas, metadatos,
strings controladas y configuración sin ejecutar binarios. Cualquier captura de
tráfico, consulta de endpoint o prueba con hardware necesita una autorización y
entorno aislado específicos.

## Conclusión

La política lógica F26 no depende de resolver esta incógnita. Un adaptador real
continúa **NO-GO**: no es posible elegir con evidencia entre SDK, API, base
intermedia o integración con servicio local. No se debe inferir el contrato a
partir del nombre “FitCloud Foto y Huella”.
