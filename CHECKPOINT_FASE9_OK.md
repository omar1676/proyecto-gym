# Checkpoint Fase 9 — circuito económico y caja

Fecha: 20/08/2026 (Europe/Madrid)

## Punto de partida verificado

- versión inicial `0.7.0-fase8`;
- migraciones hasta v24 sin pendientes ni checksum alterado;
- regresión inicial 324/324;
- HTTP 37/37, render 11/11 y smoke 11/11;
- checkpoint Fase 8 íntegro;
- checkpoint previo Fase 9: `checkpoint_pre-fase9_2026-08-19_232750.zip`, SHA-256 `513506b7356c6ab8f257b6e75bc646dbd05499c5738c39e0e0770011838371bf`;
- backup de desarrollo previo a v25: `backup_db_2026-08-19_233058.sql.gz`, SHA-256 `925013d7625212bfc8ae7defcab92e2bcc00b1e5626be8dbe3d9538f57b531e6`.

## Resultado

- versión `0.8.0-fase9`;
- migración `migracion_v25.sql` aplicada en desarrollo y en fixture limpio;
- tablas nuevas: `obligacion_pago`, `cobro`, `caja_sesion`, `caja_movimiento`;
- membresía/obligación/cobro diferenciados;
- deuda exacta en céntimos y cobros en `DECIMAL`;
- SEPA presentado/confirmado/devuelto sin duplicar devoluciones;
- caja por sede y turno, efectivo separado de tarjeta/SEPA;
- ventas y anulaciones con movimientos compensatorios;
- `SocioFinancialService` y `AccessEligibilityService` centralizados;
- acceso exclusivamente lógico `PERMITIDO/BLOQUEADO/REVISAR`;
- DORLET, huellas, biometría y hardware no integrados.

## Evidencia

- regresión: 4 suites, 32 scripts, 379 comprobaciones, 0 fallidas;
- HTTP: 37 correctas, 0 fallidas;
- render: 12 pantallas, todas `[OK]`;
- smoke: 11 comprobaciones `[OK]`;
- concurrencia: una sola apertura y un solo cierre ganan entre dos procesos;
- `EXPLAIN`: las cuatro consultas económicas/caja principales usan índices v25;
- migraciones: `pending=[]`, `checksum_mismatch=[]`, release de v25 `0.8.0-fase9`.

## Limitaciones explícitas

- `.git` local sigue sin escritura por ACL de Windows; no se modificaron permisos.
- política de bloqueo por deuda pendiente de decisión de Cleto; por defecto devuelve `REVISAR`.
- cumplimiento fiscal pendiente de gestoría; no se afirma facturación legal completa.
- no se ha probado integración física ni dominio/servidor real.
- la réplica externa de backups sigue no configurada.

El ZIP final y su SHA-256 se generan después de este documento y se verifican excluyendo `.env`, copias, logs, sesiones, secretos y staging de importaciones.
