# Informe de backup externo — Fase 14

Estado: **PENDIENTE — NO EXISTE DESTINO FÍSICAMENTE SEPARADO**.

## Estado real

- Los scripts locales generan y validan backups DB/archivos con SHA-256.
- La retención GFS implementada sirve como base técnica.
- No hay bucket, servidor remoto, volumen independiente ni credenciales.
- No se realizó transferencia externa y, por tanto, no hay hash de objeto
  remoto ni evidencia de retención real.

Otra carpeta, partición o montaje en el mismo servidor no se aceptará como
externo.

## Controles requeridos al seleccionar destino

| Control | Evidencia necesaria | Estado |
|---|---|---|
| Separación física | Cuenta/servidor y ubicación distintos del VPS | PENDIENTE |
| Transporte | TLS/SSH validado | PENDIENTE |
| Cifrado en reposo | Configuración del proveedor o cifrado previo | PENDIENTE |
| Clave | Custodio y almacenamiento separado de los backups | PENDIENTE |
| Integridad | SHA-256 local y remoto iguales | PENDIENTE |
| Retención | 7 diarios, 4 semanales, 6 mensuales o política aprobada | PENDIENTE |
| Fallo | Salida no cero, log y alerta recibida | PENDIENTE |
| Acceso | Lectura/escritura mínima y restauración autorizada | PENDIENTE |

Si en el futuro contiene datos reales, el cifrado en reposo y en tránsito será
obligatorio. No se ha generado ni inventado ninguna clave de cifrado.

Objetivo documentado, todavía no real: backup DB cada 6 horas y archivos cada
24 horas. El RPO no se considera configurado hasta verificar el cron remoto.
