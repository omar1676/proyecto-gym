# Inventario y custodia de secretos

Este documento registra tipos y procedimientos, nunca valores.

| Secreto | Propietario actual | Ubicación autorizada | Recuperación/rotación | Segundo custodio | Impacto de pérdida |
|---|---|---|---|---|---|
| OVH | propietario | gestor del proveedor | recuperación de cuenta y rotación | pendiente | VPS/DNS inaccesibles |
| Cloudflare/R2 | propietario | proveedor + `/etc/gimnera/rclone.conf` 0600 | revocar token y emitir otro limitado | pendiente | backup externo inaccesible |
| rclone crypt password/salt | propietario | gestor externo al VPS | no recuperable: custodiar dos copias seguras | pendiente | backups cifrados irrecuperables |
| SSH | cada operador | clave privada individual | revocar clave pública y emitir otra | pendiente | acceso operativo perdido/comprometido |
| DB | servicio | `.env` shared fuera de Git | rotar usuario DB local | pendiente | aplicación/DB comprometidas |
| TLS | Certbot/root | `/etc/letsencrypt` | reemitir por DNS/HTTP challenge | proveedor | HTTPS interrumpido |
| SMTP alertas | propietario técnico | `.env` shared fuera de Git | revocar contraseña/token SMTP | pendiente | alertas humanas no llegan |
| Git | propietario | proveedor Git/gestor personal | revocar token/SSH y MFA recovery | pendiente | código/release comprometidos |

MFA de OVH, Cloudflare y Git: **NO VERIFICADO**; activarlo exige confirmar los
métodos de recuperación para no perder el acceso. MFA privilegiado dentro de
Gimnera es necesario antes de producción con datos reales, pero no se implementa
de forma casera en F21.
