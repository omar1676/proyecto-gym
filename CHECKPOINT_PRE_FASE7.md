# Checkpoint pre-fase7

Fecha: 2026-08-19 (Europe/Madrid)

## Estado Git

- Rama: `main`.
- Commit base: `40480609610e1fbfec47d0b722c2f6c6a129ce04` (`first commit`).
- Las fases 1–6 estaban presentes como cambios sin confirmar y se han conservado íntegramente.
- No se ha podido crear un commit/tag porque la ACL de Windows deniega escritura en `.git` al proceso de trabajo.

## Entorno y datos

- Entorno activo auditado: `development`.
- Base declarada de trabajo: `portal_de_cursos`.
- Base independiente de pruebas: `portal_de_cursos_pruebas`.
- La regresión usa exclusivamente `APP_ENV=test` y la base de pruebas.

## Esquema

- Última migración: `migracion_v22.sql`.
- Migraciones pendientes: ninguna.
- Checksum de migraciones alterado: ninguno.

## Evidencia previa a la fase

- Suite principal: 248 comprobaciones correctas, 0 fallidas, 19 scripts.
- Pruebas HTTP: 35 correctas, 0 fallidas.
- Renderizado: 10 pantallas correctas.
- Smoke tests: 11 correctos.
- Último backup de base validado: `backup_db_2026-08-19_182231.sql.gz`.
- SHA-256 del backup: correcto.

## Exclusiones del checkpoint

El archivo ZIP asociado excluye `.git`, `.git.bak`, `.env`, copias, restauraciones,
logs, sesiones, dependencias y cualquier archivo de backup con datos.
