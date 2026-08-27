# Respuesta a incidentes

Este documento define el flujo técnico mínimo. No sustituye decisiones legales,
contractuales o de comunicación.

## Regla de preservación

Ante una señal de compromiso no se borran logs, journals, claves, procesos ni
archivos sospechosos. Se registra tiempo UTC, fuente, hash y responsable de la
captura. Si hay indicios de acceso root, binarios alterados, webshell o
persistencia desconocida, se detiene el desarrollo y se valora reconstruir el
VPS desde una imagen limpia en lugar de "limpiarlo" en sitio.

## Login SSH correcto de origen desconocido

1. Preservar `journalctl`, `auth.log*`, `wtmp`, configuración SSH, fingerprints
   de `authorized_keys`, sudoers y cron/systemd sin copiar secretos.
2. Reconstruir apertura, método, usuario, sesión, TTY, cierre, sudo/su,
   transferencia de archivos y cambios en la misma ventana.
3. Correlacionar con cambios de release, backups, Nginx/PHP, Git y la ventana de
   trabajo documentada. El WHOIS/RDAP es solo enriquecimiento pasivo; no se
   escanea la IP ni se intenta identificar a una persona.
4. Clasificar: `ATTRIBUTED_LEGITIMATE`, `LIKELY_LEGITIMATE`, `UNKNOWN`,
   `SUSPICIOUS` o `CONFIRMED_UNAUTHORIZED`.
5. Si queda `UNKNOWN`, mantener abierto el incidente, rotar credenciales por un
   canal coordinado y aumentar monitorización. No afirmar que el host está
   limpio al cien por cien.
6. Si es `CONFIRMED_UNAUTHORIZED`, aislar de forma controlada, preservar una
   imagen/evidencia, revocar accesos desde un canal confiable y reconstruir si
   hubo privilegio root, persistencia o integridad del sistema no demostrable.

## Otros casos

- Cuenta comprometida: invalidar sesiones, desactivar identidad, rotar el
  secreto y revisar auditoría por tenant/sede.
- Fuga de DB: contener acceso, preservar evidencia, rotar usuarios, restaurar
  solo desde copia verificada y escalar privacidad/legal.
- Fallo de backup: conservar toda copia local válida, detener retención
  destructiva y probar restore aislado desde la última copia íntegra.
- Proveedor comprometido: revocar tokens, revisar MFA y actividad de GitHub,
  OVH y Cloudflare desde una estación confiable.

## Criterios de reconstrucción

Reconstrucción recomendada ante cuenta root no atribuida, servicio persistente
desconocido, binario de sistema alterado, rootkit plausible, webshell con
escalada o imposibilidad de demostrar la integridad de la release/sistema.
