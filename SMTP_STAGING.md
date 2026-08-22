# SMTP de staging

Estado: **PREPARADO / NO VERIFICADO**. No existen credenciales SMTP de staging.

## Canal técnico de alertas

Es independiente del correo funcional y usa únicamente `ALERT_SMTP_*`,
`ALERT_FROM`, `ALERT_FROM_NAME`, `ALERT_TO` y `ALERT_ALLOWED_RECIPIENTS`.
No existe fallback a `mail()`. `ALERT_TO` debe aparecer de forma exacta en
la lista separada por comas `ALERT_ALLOWED_RECIPIENTS`; vacío bloquea todos
los envíos aunque el SMTP esté configurado.
Tiene timeout y tres intentos limitados; los logs no incluyen credenciales.

```bash
php ops/monitor_alert_test.php --confirm-staging-test --severity=TEST
php ops/monitor_alert_test.php --confirm-staging-test --severity=WARNING
php ops/monitor_alert_test.php --confirm-staging-test --severity=CRITICAL
```

No se marca VERIFICADO hasta confirmar recepción humana y observar
deduplicación/cooldown.

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
