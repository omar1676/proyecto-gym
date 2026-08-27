# Baseline de hardening operativo

## SSH

Política efectiva esperada: solo clave pública, root deshabilitado,
`MaxAuthTries 3`, `LoginGraceTime 30`, sin X11, túneles TCP, agente, túnel de
red ni `GatewayPorts`. Antes de recargar: checkpoint, `sshd -t`, sesión de
recuperación viva y prueba desde una segunda conexión. Una clave temporal no se
retira hasta demostrar acceso nominal alternativo.

## Sudo

`NOPASSWD: ALL` es deuda P1. La retirada exige primero comandos root-owned con
argumentos validados para health, backup, deploy inmutable, cambio atómico de
`current`, rollback compatible y restore temporal. No se permiten comodines
sobre `cp`, `mv`, `ln`, shells, editores ni intérpretes. El cambio se prueba en
una segunda sesión y mantiene recuperación por consola del proveedor.

## Fail2ban

No forma parte de la cadena de autenticación. Puede reducir ruido de Internet,
pero se instala únicamente con configuración probada, allowlist operativa,
backend systemd y rollback. La defensa primaria sigue siendo contraseña
deshabilitada, clave pública, UFW y límites pre-auth.

## Releases

El artefacto se genera con `git archive`. El sidecar `.manifest.json` producido
por `ops/build_release.php` se instala como
`.gimnera-release-manifest.json` en la raíz de la candidata antes de ejecutar
`ops/deploy.php`. El gate compara bytes y SHA-256 de todos los archivos, rechaza
CRLF introducido después del build, archivos extra y symlinks no previstos.
Los únicos enlaces runtime permitidos son `.env`, uploads de gimnasios y de
productos, siempre hacia un `shared` bajo `/var/www`.

La primera verificación de una candidata debe ejecutarse con el verificador de
la release activa conocida o con una copia root-owned, no confiando únicamente
en el script incluido dentro de la propia candidata. El gate interno de
`ops/deploy.php` es defensa adicional y se ejecuta antes de cargar la
configuración de la candidata.

## Supply chain

Tailwind Browser queda fijado a una versión exacta y protegido por SRI. Los
iconos quedan fijados a una versión exacta; al cargarse como imágenes no
ejecutan script, pero su vendorización local sigue recomendada antes de
producción. `unsafe-inline` permanece porque retirarlo sin compilar Tailwind ni
eliminar scripts inline rompería la interfaz; debe cerrarse en una fase probada,
no como cambio cosmético.

HSTS no se activa globalmente mientras existan previews y subdominios cuya
compatibilidad HTTPS/rollback no se haya validado. Para el dominio final se
decide después de inventario DNS completo.

## Watchdog, alertas y backups

Un watchdog solo es externo si corre fuera del VPS y comprueba health, TLS,
heartbeat interno y edad del backup. Una alerta humana solo se marca verificada
cuando el destinatario confirma recepción. Los backups locales contienen datos
en claro comprimidos y requieren cifrado at-rest o retención mínima coordinada;
no se elimina una copia válida durante la transición. R2 debe permanecer bajo
`crypt` y cada restore se valida en una DB independiente.
