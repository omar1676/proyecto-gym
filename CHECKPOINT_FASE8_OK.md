# Checkpoint Fase 8 — motor de importación

- Fecha: 2026-08-19 (Europe/Madrid).
- Versión: `0.7.0-fase8`.
- Rama observada: `main`.
- Commit base observado: `40480609610e1fbfec47d0b722c2f6c6a129ce04`.
- Git: escritura bloqueada por ACL de Windows; no se modificaron permisos.
- Los cambios acumulados de las fases anteriores permanecen conservados.

## Base de datos

- Base de desarrollo: `portal_de_cursos`.
- Base independiente de pruebas: `portal_de_cursos_pruebas`.
- Última migración: `migracion_v24.sql`.
- Migraciones pendientes: 0.
- Discrepancias de checksum: 0.
- Backup previo a aplicar v24: `backup_db_2026-08-19_224532.sql.gz`.
- SHA-256: `25d79eda9dfa07bcaebaf54ebf75ea6b2bf3b503b03aea4883fbf47601d437bd`.
- Copia externa: no configurada; una importación en producción queda bloqueada
  hasta disponer de backup reciente, verificado y externo.

## Verificación

- Sintaxis PHP: 117 archivos, 0 fallos.
- Regresión principal: 27 scripts, 324 comprobaciones, 0 fallos.
- Acceso HTTP: 37/37.
- Renderizado: 11/11, incluida la pantalla de Importaciones.
- Smoke: 11/11.
- Migraciones de fixture de pruebas: schema + v1–v24, correctas.

## Rendimiento sintético

- Archivo: 5.000 socios, 656.623 bytes.
- Parsing streaming: p50 31,93 ms; máximo 36,23 ms.
- Dry-run: 25.016,49 ms; 5.000 válidas, 0 errores y 0 cambios de negocio.
- Importación: 25.994,31 ms; 5.000 creados en lotes de 250.
- Verificación: 5.000 socios exclusivamente en empresa/sede sintéticas.
- Memoria PHP máxima: 4 MiB.
- El tenant sintético se eliminó al finalizar el benchmark.

## Alcance preservado

- No se accedió a FitCloud ni se creó un perfil FitCloud sin exportación autorizada.
- No se usaron datos reales.
- No se tocó DORLET ni ningún dato biométrico.
- Ventas históricas, cobros y facturas no se importan.
- Membresías se limitan a análisis y dry-run.
- No existe rollback masivo destructivo por batch.

El ZIP asociado excluye `.git`, `.git.bak`, `.env`, copias, restauraciones,
logs, sesiones, temporales de importación y dependencias regenerables.
