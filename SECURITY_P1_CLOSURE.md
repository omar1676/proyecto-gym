# Cierre P1 previo a datos reales

Base: `security/post-forensic-hardening` en
`171e876e817a530d6b0098dee79350d087bc4099`.

Este documento no contiene direcciones IP, claves, tokens, destinatarios,
credenciales, logs ni fingerprints privados. Registra únicamente estados y
criterios de verificación.

| P1 | Estado inicial | Evidencia exigida para cierre |
|---|---|---|
| GitHub 2FA | INACTIVE | Segundo factor activo y recovery confirmado fuera de Git/VPS. |
| Cloudflare 2FA | INACTIVE | Segundo factor activo y recovery confirmado fuera de Git/VPS. |
| OVH MFA | NOT VERIFIED | Estado visible, MFA activo y recovery confirmado. |
| Watchdog externo | PREPARED | Runner permanente externo detecta fallo sintético y alerta. |
| Alerta humana | NOT CONFIGURED | WARNING y CRITICAL recibidos por persona autorizada. |
| SSH nominal | NOT VERIFIED | Nueva sesión con clave nominal y password-only rechazado. |
| Claves temporales | ACTIVE | Alternativa probada; retirada individual y nueva sesión correcta. |
| Sudo | NOPASSWD ALL | Wrappers cerrados, sudoers mínimo y prueba en dos sesiones. |
| Segundo operador | NOT VERIFIED | Persona real completa el runbook sin ayuda verbal. |
| Backup local | PLAINTEXT COMPRESSED | Backup cifrado, decrypt y restore aislado verificados. |
| Atribución SSH | LIKELY LEGITIMATE | Confirmación humana coherente o evidencia técnica adicional. |

## Reglas

- `CONFIGURED` no equivale a `VERIFIED`.
- Nunca retirar la última vía de acceso funcional.
- Nunca guardar recovery secrets en Git, VPS, logs o documentación.
- Si la atribución SSH resulta negativa o aparece un P0, se detiene el cierre y
  se entra en Incident Response.
- El GO técnico de seguridad no sustituye revisión de privacidad, legal/DPO ni
  gestoría.
