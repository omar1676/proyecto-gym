# Restore real de staging

## Estado y regla de seguridad

Restore desde backup externo: **NO EJECUTADO** porque todavía no existe destino
externo. Un ensayo desde la copia local valida el formato, pero no satisface el
requisito de recuperación ante pérdida total del VPS.

Ensayo local independiente del 21/08/2026: **VERIFICADO**. Restauró 28 tablas y
9 archivos extraídos (manifiesto más 8 entradas) en 338 ms; health sobre la DB
restaurada fue correcto, las 27 migraciones coincidieron exactamente y el smoke
activo terminó 11/11. Se compararon usuarios, sedes, productos, ventas,
membresías, remesas, obligaciones, cobros, caja, auditoría y migraciones. Staging
estaba vacío de datos de negocio, por lo que esos recuentos coincidieron en cero;
no se presenta esta prueba como validación de volumen. La DB, archivos y permiso
temporal fueron eliminados, con cero grants residuales.

Nunca se restaura sobre `gimnasio_staging`. El destino debe contener `restore`
y ser una base independiente. Antes de importar se verifica el SHA-256 y el
manifiesto de DB, archivos y set global.

## Procedimiento completo

1. Registrar incidente, release requerida y responsables de recuperación.
2. Descargar el set desde el proveedor externo a una máquina limpia.
3. Descifrar mediante la configuración recuperada del gestor de secretos.
4. Comparar todos los SHA-256 con sus sidecars.
5. Crear `gimnasio_restore_<UTC>` con permisos temporales mínimos.
6. Ejecutar:

```bash
php ops/restore.php \
  --database=<backup_db.sql.gz> \
  --target=gimnasio_restore_<UTC> \
  --existing-empty \
  --files=<backup_files.tar.gz> \
  --files-target=<directorio-vacio>
php ops/verify_restore.php gimnasio_restore_<UTC> --files=<directorio-restaurado>
```

7. Apuntar temporalmente `DB_NAME` a la base restaurada y ejecutar
   `php ops/migrate.php --status`: 0 pendientes y 0 checksum mismatch.
8. Ejecutar health/smoke en un vhost aislado, nunca con el hostname activo.
9. Comparar tablas y recuentos de usuarios, membresías, productos, ventas,
   caja, auditoría y uploads sintéticos.
10. Documentar descarga, restore DB, archivos, validación y tiempo total.
11. Solo después de aprobación, limpiar la base y archivos temporales exactos.

## Objetivos

- RTO objetivo de alpha: 4 horas.
- RPO objetivo DB: 6 horas.
- RPO objetivo archivos: 24 horas.

Los 338 ms son una referencia local, no el RTO de desastre: no incluyen descarga,
descifrado ni aprovisionamiento. RTO/RPO reales se medirán tras el primer restore
descargado realmente del almacenamiento externo. Hasta entonces son
**PENDIENTES** y los datos reales están en **NO-GO**.

## Actualización Fase 17 — restore externo verificado

La fuente fue exclusivamente Cloudflare R2 mediante el remote cifrado. Se
descargó a una carpeta limpia, sin enlaces ni lectura de los artefactos locales,
y se restauró en `gimnasio_restore_f17_20260821_1645`, nunca sobre staging.

Resultados reales:

- 9 objetos descargados; 3 sidecars SHA-256 correctos;
- 28 tablas y 27 migraciones exactas;
- `pending=[]`, `checksum_mismatch=[]`;
- 8 archivos verificados por tamaño y SHA-256;
- usuarios 5/5, gimnasios/sedes 2/2, auditoría 5/5;
- productos, ventas, membresías, remesas, cobros y caja 0/0, coherente con el
  staging sintético actual;
- health OK y smoke 11/11.

El primer intento se detuvo porque el usuario de aplicación no puede crear
bases. Se precreó una DB independiente y se limitó el permiso a ella; el segundo
ensayo pasó completo.

| Paso | Tiempo |
|---|---:|
| Aprovisionar DB independiente | 101 ms |
| Descargar + descifrar con `rclone crypt` | 738 ms |
| Verificar SHA-256 | 38 ms |
| Restore DB + archivos | 370 ms |
| Comparar restore | 61 ms |
| Estado de migraciones | 65 ms |
| Health + 11 smoke | 858 ms |
| **RTO total observado** | **2.231 ms (2,231 s)** |

Descarga y descifrado son una misma operación de `rclone crypt`; DB y archivos
son una misma invocación de `ops/restore.php`. No se inventan subtotales que la
instrumentación no midió. RTO objetivo: 4 horas.
