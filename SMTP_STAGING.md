# SMTP de staging

Estado: **PREPARADO / NO VERIFICADO**. No existen credenciales SMTP de staging.

El `Mailer` actual impone tres controles en `APP_ENV=staging`:

1. SMTP debe estar configurado; no existe fallback a `mail()`.
2. El destinatario debe coincidir exactamente con `STAGING_MAIL_ALLOWLIST`.
3. Una configuración incompleta devuelve fallo y registra el bloqueo sin
   contraseñas ni contenido sensible.

Las variables viven solo en `/var/www/gimnasio/shared/.env`:

```dotenv
MAIL_FROM=
MAIL_NOMBRE=Gimnera staging
MAIL_SMTP_HOST=
MAIL_SMTP_PUERTO=587
MAIL_SMTP_USUARIO=
MAIL_SMTP_CLAVE=
MAIL_SMTP_SEGURIDAD=tls
STAGING_MAIL_ALLOWLIST=
```

Para verificar: contratar/autorizar el proveedor, configurar SPF/DKIM/DMARC,
añadir un único buzón de prueba autorizado, ejecutar `php ops/smtp_check.php`
y enviar un correo sintético a ese buzón. Después se comprueban timeout, rebote
y bloqueo de un destinatario no permitido. No se reutilizará el correo de
Let's Encrypt ni se enviará a socios sin autorización separada.
