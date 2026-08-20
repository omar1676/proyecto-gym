# Solicitud técnica final a DORLET/instalador

**Estado Fase 13: NO ENVIADA.** No se ha aportado evidencia de envío ni
respuesta. El texto queda preparado exclusivamente para envío humano autorizado.

No enviar sin autorización de Cleto y sin completar los campos. No adjuntar
socios, huellas, credenciales, backups ni datos de terceros.

**Asunto:** Interfaz oficial de integración — instalación Cleto Reyes

Buenos días:

Con autorización de Centro Deportivo Cleto Reyes estamos evaluando una futura
integración con su control de accesos. En esta fase no conectaremos hardware ni
modificaremos identidades, permisos o biometría; necesitamos identificar la vía
oficial y soportada.

¿Podrían confirmar para la instalación `[REFERENCIA/UBICACIÓN]`?

1. Producto, versión, módulos licenciados, mantenedor y estado de soporte.
2. Modelos/firmware de controladoras y lectores relevantes.
3. Disponibilidad y compatibilidad del SDK de accesos D9110400 o API oficial equivalente.
4. Documentación, ejemplos y entorno de prueba; autenticación y permisos mínimos.
5. Operaciones read-only para salud, identidad opaca, permisos no biométricos y eventos con ID/cursor.
6. Códigos de error, límites, timeouts, reintentos y funcionamiento offline.
7. Procedimiento soportado de backup/rollback y prueba reversible con un único usuario autorizado.
8. Compatibilidad con el enrolador IDEMIA MSO 300 observado, manteniendo la biometría fuera de nuestro SaaS.
9. Licencia, contrato, soporte y contacto técnico necesarios para una integración B2B.

Nuestra arquitectura usaría identificadores externos opacos y una interfaz
documentada; no accedería a bases internas ni extraería plantillas biométricas.

Gracias,

`[NOMBRE/CARGO]`<br>
`[EMPRESA]`<br>
`[TELÉFONO/EMAIL]`<br>
`[AUTORIZACIÓN O REFERENCIA DE CONTRATO]`

## Evidencia esperada

- [ ] Ficha de instalación/versiones y mantenedor.
- [ ] Licencia/contrato del módulo de integración.
- [ ] Manual SDK/API compatible y matriz read/write.
- [ ] Autenticación, errores, límites, eventos y modo offline.
- [ ] Entorno de prueba, soporte, backup y rollback.

Estado actual: `PENDIENTE`. `ACCESS_CONTROL_MODE=disabled`; no existe adaptador
real ni se ha probado comunicación con DORLET.
