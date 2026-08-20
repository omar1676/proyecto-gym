# Feature freeze del piloto

## Regla

Desde la release candidata hasta el cierre del piloto no se incorporan nuevas
funciones de negocio, rediseños, integraciones físicas ni cambios de arquitectura.
El objetivo es validar lo que existe y obtener evidencia operativa.

## Cambios admitidos

- P0 reproducible: pérdida/corrupción, aislamiento roto, operación crítica imposible.
- P1 aceptado por el responsable del piloto: riesgo grave sin workaround seguro.
- Corrección de seguridad crítica o alta con prueba de regresión.
- Configuración/datos de staging o documentación operativa sin ampliar producto.

Cada excepción exige: incidencia, impacto, responsable, cambio mínimo, test,
backup/rollback y aprobación. Nunca se corrige directamente en producción.

## Congelado explícitamente

- DORLET, IDEMIA, huella, QR, NFC o controladoras.
- Portal/app de socio, reservas, IA, dashboards o funciones comerciales nuevas.
- Automatización fiscal no validada por gestoría.
- Migración de histórico económico sin mapeo y conciliación aprobados.
- Cambios de permisos para “hacer más cómodo” un flujo.

## Flujo de una incidencia

`Registrar → reproducir en staging → clasificar → aprobar → cambio mínimo → regresión completa → release candidata → smoke → seguimiento`.

P2, P3 e IDEA quedan en `FEEDBACK_PILOTO.md`; no entran en la release del
piloto salvo nueva decisión formal tras finalizar la observación.

