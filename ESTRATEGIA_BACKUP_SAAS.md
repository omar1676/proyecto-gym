# Estrategia de backup SaaS — dos niveles

Documento de diseño. No implementa restauración destructiva por empresa.

## A. Backup global

Debe capturar en una ventana consistente:

- dump transaccional de la base central;
- uploads necesarios;
- configuración no secreta y referencia a secretos externos;
- versión/release y commit;
- versión de esquema y checksums de migraciones;
- manifiesto con tamaño, fecha, motor y SHA-256 de cada componente.

El flujo existente de backup local comprimido y copia externa es la base. En
producción el resultado debe salir del servidor, cifrarse y verificarse mediante
una restauración periódica independiente.

## B. Backup por empresa

Es una exportación lógica consistente, no una colección de `SELECT` manuales.
Parte de `empresa.id`, obtiene sus sedes autorizadas y recorre el grafo:

- empresa, configuración y sedes;
- usuarios/empleados/socios;
- tarifas, suplementos y membresías;
- productos, categorías y stock;
- ventas, líneas, cobros y numeración;
- mandatos, remesas y recibos;
- auditoría;
- futuros mapas y eventos de acceso.

Cada entidad debe expresar cómo se determina su tenant: `empresa_id` directo o
relación comprobada mediante `sede_id`. El exportador debe ejecutarse sobre una
instantánea transaccional y generar manifiesto con recuentos, claves externas,
checksums y versión de esquema. Nunca incluirá filas de otra empresa.

## Retención propuesta

| Nivel | Diarias | Semanales | Mensuales | Ubicación |
|---|---:|---:|---:|---|
| Global | 7 | 4 | 6 | Local temporal + copia externa |
| Empresa bajo demanda | 3 por operación | — | Según contrato/RGPD | Repositorio externo controlado |

Los backups por empresa no sustituyen al global. Se generan para portabilidad,
auditoría o recuperación selectiva y tienen caducidad explícita.

## Restauración global

1. Servidor limpio y release compatible.
2. Verificar manifiesto y SHA-256.
3. Restaurar archivos a staging.
4. Restaurar la base en una base vacía.
5. Ejecutar solo migraciones posteriores necesarias.
6. Verificar esquema, recuentos, health y smoke tests.
7. Activar el servicio y auditar la restauración.

## Restauración selectiva

Caso: Cleto necesita recuperar información y otros 20 gimnasios no.

`backup → BD temporal → extraer tenant → validar relaciones → comparar → seleccionar → transacción → verificar → auditar`

1. Nunca restaurar el backup global encima de la base central activa.
2. Restaurarlo en una base temporal aislada y de la misma versión.
3. Exportar únicamente el tenant objetivo con el procedimiento anterior.
4. Comparar por claves estables y generar altas/cambios/conflictos.
5. Exigir aprobación del alcance exacto.
6. Aplicar en transacción o lotes atómicos con `restauracion_id`.
7. Reconciliar recuentos, importes, stock y relaciones.
8. Registrar quién autorizó, qué filas cambiaron y qué backup se utilizó.

## Riesgos de restauración selectiva

- FKs e IDs que ya estén ocupados: usar mapas, no sobrescribir IDs a ciegas.
- Ventas posteriores: no retroceder caja ni numeración sin conciliación.
- Stock: reconstruir por movimientos/corte o aplicar ajuste aprobado.
- Membresías posteriores: preservar renovaciones más nuevas.
- Auditoría: no reescribir eventos recientes ni ocultar la restauración.
- Datos compartidos: catálogos de empresa y sede requieren política de merge.
- Borrados/anonimización RGPD: no reintroducir datos borrados legalmente.

## Seguridad y operación

- SHA-256 inmediato y tras copia externa.
- Cifrado futuro con claves fuera del servidor y rotación documentada.
- Acceso mínimo, registro de descargas y prohibición de document root.
- Prueba de restauración global trimestral y selectiva antes de ofrecerla.
- Alertas de antigüedad, tamaño anómalo, checksum o copia externa fallida.
