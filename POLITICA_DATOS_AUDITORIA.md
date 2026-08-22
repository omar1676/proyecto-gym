# Política técnica pendiente de validación legal/DPO

No declara cumplimiento RGPD ni fija plazos legales.

Los eventos nuevos registran event/correlation ID, UTC, actor, empresa, sede,
origen controlado, acción, entidad, resultado, reason code, IP confiable y
metadata minimizada. Contraseñas, tokens, cookies, sesiones, secretos e IBAN no
se admiten en metadata.

Antes de datos reales deben decidirse con legal/DPO: retención por tipo de
evento, base y bloqueo de supresión, anonimización de actor/afectado, exportación,
tratamiento de logs/backups y respuesta a derechos. La supresión técnica debe
ser solicitud → revisión → anonimización compatible con obligaciones → auditoría;
los backups expiran por retención y no se editan manualmente.

Fotos personales se almacenan fuera del document root y solo se sirven tras
autorización de usuario, tenant, sede y rol. Assets de marca/producto siguen
siendo públicos porque no son fotos personales.
