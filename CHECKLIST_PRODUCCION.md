# Checklist de producción

Estado a 19/08/2026. `[OK]` significa verificado localmente; no equivale a
verificación del servidor futuro.

- [PENDIENTE] Dominio definitivo y DNS.
- [NO VERIFICADO] Certificado HTTPS, redirección, HSTS y ausencia de mixed content en dominio real.
- [PENDIENTE] Crear `.env` de producción fuera de Git con `APP_ENV=production`.
- [PENDIENTE] Rotar las credenciales que aparecen en el historial `.git.bak`.
- [PENDIENTE] Retirar `.env.produccion.bak`, `.git.bak`, tests y recursos de la release productiva.
- [OK] `.env.example` no contiene secretos reales.
- [OK] Base de test separada y bloqueo contra pruebas destructivas.
- [OK] Migraciones v1-v22 aplicadas y registradas sin checksums distintos.
- [OK] Backup MySQL comprimido, validado y con SHA-256.
- [OK] Backup de uploads/configuración no secreta con manifiesto y SHA-256.
- [PENDIENTE] Configurar y verificar `COPIAS_EXTERNAS_DIR` fuera del servidor.
- [OK] Restauración independiente de DB y archivos ensayada localmente.
- [PENDIENTE] Ensayar restauración en el proveedor definitivo y medir RTO.
- [NO VERIFICADO] SMTP: conexión, autenticación, SPF, DKIM, DMARC y envío sintético.
- [PENDIENTE] Instalar cron de backups, mantenimiento, monitor y tareas existentes.
- [OK] Logging estructurado separado, rotación diaria/tamaño y retención técnica.
- [PENDIENTE] Configurar alerta del monitor y rotación del servidor web/MySQL.
- [PENDIENTE] Aplicar propietario/grupo y permisos 0750/0640 en servidor.
- [OK] Uploads verifican MIME, tamaño, dimensiones y nombre generado.
- [OK] Configuración Apache/Nginx de ejemplo con document root `public/`.
- [OK] Accesos HTTP locales a `.env`, app, tests, pruebas y copias bloqueados.
- [NO VERIFICADO] Bloqueos de archivos en el servidor definitivo.
- [OK] Health check mínimo sin detalles internos.
- [OK] Smoke tests seguros y no destructivos.
- [PENDIENTE] Crear superadmin/administradores nominales con claves únicas y MFA del proveedor si existe.
- [OK] Tests automatizados de aislamiento multiempresa/multisede disponibles.
- [PENDIENTE] Ejecutar aislamiento y permisos en staging con configuración equivalente a producción.
- [OK] Procedimiento de rollback de código documentado.
- [PENDIENTE] Aprobar ventana de mantenimiento, responsables y contacto de incidentes.
