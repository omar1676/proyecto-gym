# Decisión GO/NO-GO — Etapa 2

Fecha: 20/08/2026.

## Decisión: NO-GO

La Etapa 0 local demuestra que la release puede migrarse, cargarse con datos
sintéticos, respaldarse, restaurarse y recorrer flujos técnicos. No demuestra
que exista un staging real seguro ni que el negocio esté preparado para
operaciones controladas.

## Bloqueos P0

1. No existe servidor/subdominio staging autorizado con TLS verificado.
2. No existe backup físicamente externo ni restore probado desde ese destino.
3. El preflight no queda en verde: falta copia externa y la release local aún
   contiene `instalar.php` fuera del manifiesto de producción.
4. Faltan responsables nominales, canal de soporte y autoridad de rollback.
5. Siguen sin decidirse reglas prioritarias de caja, impagos/devoluciones,
   anulaciones, stock inicial y criterios de parada.

## Controles favorables

- Base local independiente, v26, datos sintéticos y accesos nominales.
- Backup DB/archivos con hash y restore local verificado en 1,213 s.
- Fuente de verdad inequívoca: sistema actual en todas las áreas.
- DORLET deshabilitado, email bloqueado y cron económico forzado a simulación.
- Sin P0 técnico conocido en la regresión ejecutada hasta este documento.

## Criterio para revisar la decisión

Configurar staging real, cerrar todos los P0 anteriores, repetir restore y
seguridad desde infraestructura separada, ejecutar Etapa 1 con observación
humana y aprobar por escrito la matriz de responsabilidades. Hasta entonces no
se realizan cobros, remesas, facturas, stock real ni importaciones reales.
