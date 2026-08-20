# Roadmap de seguridad

No implica que los controles estén implementados; es priorización posterior.

## Antes del piloto

- Rotar todas las credenciales de demostración y comprobar que no existen en producción.
- HTTPS real, cookies `Secure`, HSTS y cabeceras verificadas en el dominio piloto.
- Backup externo y restauración reciente verificada.
- Cuenta nominal por empleado; prohibir cuentas compartidas.
- SMTP real con remitente autorizado y sin datos reales en pruebas.
- Revisar permisos de filesystem, uploads y logs en el servidor definitivo.
- Alertas básicas de caída, disco, errores y backup fallido.

## Antes de producción

- 2FA obligatorio para superadmin/dirección y recomendable para administradores.
- Gestión de sesiones/dispositivos: visualizar y revocar sesiones activas.
- Alertas de login anómalo y cambios sensibles.
- Cifrado de backups con claves fuera del servidor y rotación ensayada.
- Rotación documentada de secretos de BD, SMTP y futuras integraciones.
- CSP con nonces/hashes tras eliminar dependencias inline incompatibles.
- Monitorización externa y alertas con guardias/responsables definidos.
- Revisión de retención RGPD para logs, auditoría, backups y futuros accesos.
- Pentest independiente centrado en tenant escape, IDOR y flujos de cobro.
- Procedimiento probado de credencial comprometida y pérdida de datos.

## Post-producción

- WAF/reverse proxy con reglas observadas antes de bloquear.
- Auditoría avanzada, exportación firmada y detección de patrones anómalos.
- Centralización de logs/SIEM si el volumen lo justifica.
- Pruebas periódicas de recuperación y simulacros de incidente.
- Revisión ASVS recurrente y análisis de dependencias en CI.
- Gestión de claves mediante servicio dedicado si crece el número de clientes.
- Segmentación adicional de infraestructura y credenciales por función.
