# Cifrado de backups locales

Estado: **DISEÑADO / NO VERIFICADO**.

Los backups locales actuales son archivos comprimidos con permisos restringidos,
pero no están cifrados. R2 sí utiliza `rclone crypt`.

## Diseño mínimo

Usar cifrado asimétrico estándar con GnuPG:

- la clave privada se genera y conserva fuera del VPS y fuera de Git;
- el VPS recibe únicamente una clave pública dedicada a backups;
- el fingerprint público se fija en configuración root-owned;
- cada backup se cifra a un archivo nuevo antes de cualquier retención;
- se calcula manifiesto y SHA-256 del ciphertext;
- el plaintext se conserva hasta verificar decrypt + restore aislado;
- nunca se escribe passphrase en argumentos, logs, systemd o `.env`;
- la rotación admite temporalmente dos recipients y se prueba antes de retirar
  el anterior.

No se reutiliza la contraseña de `rclone crypt` como clave local. Guardar el
recipient público en el VPS no concede capacidad de descifrado.

## Gate de migración

1. Custodia externa y recovery de la clave privada confirmados por dos personas
   o por propietario + depósito de recuperación.
2. Importar solo la clave pública en el VPS y verificar su fingerprint.
3. Crear un backup nuevo cifrado sin borrar el comprimido anterior.
4. Descargar el ciphertext a un entorno temporal controlado.
5. Descifrar sin exponer la clave y restaurar a DB temporal.
6. Verificar hashes, esquema, health y smoke; medir RTO.
7. Limpiar plaintext temporal y demostrar su ausencia.
8. Solo entonces aplicar retención gradual a copias antiguas.

Sin una clave pública dedicada y recovery externo, este P1 permanece abierto.
