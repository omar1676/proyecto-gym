# Plan de aprovisionamiento de staging

Estado al 20/08/2026: **PENDIENTE DE INFRAESTRUCTURA AUTORIZADA**.

No se ha aportado servidor, dominio, DNS, credenciales, almacenamiento externo,
SMTP ni canal de alertas. Este plan no elige ni contrata proveedor y no contiene
costes: no existe una tarifa o presupuesto documentado localmente que permita
estimarlos con evidencia.

## Decisiones que debe aportar el responsable

| Decisión | Dato requerido | Estado |
|---|---|---|
| Servidor | Proveedor, cuenta, región, IP y persona autorizante | PENDIENTE |
| Dominio | Dominio base y subdominio de staging | PENDIENTE |
| DNS | Gestor, acceso, registro, TTL e IP esperada | PENDIENTE |
| Backup externo | Proveedor/servidor físicamente separado y retención aceptada | PENDIENTE |
| Cifrado | Cifrado del proveedor o previo al envío y custodio de la clave | PENDIENTE |
| SMTP | Servidor de prueba, remitente y destinatarios allowlist | PENDIENTE |
| Alertas | Canal, destinatarios y horario de atención | PENDIENTE |
| Operación | Responsable de soporte, STOP y rollback | PENDIENTE |

## Requisitos técnicos mínimos ya documentados

- Linux con Apache 2.4 o Nginx y document root exclusivo en `public/`.
- PHP 8.1+; recomendado 8.2, con `pdo_mysql`, `mbstring`, `openssl`,
  `fileinfo`, `curl`, `dom`, `simplexml` y `zlib`.
- MariaDB 10.4+ o MySQL 8, `utf8mb4` y zona `Europe/Madrid`.
- Usuario de sistema dedicado, sin root, código `0644/0755`, directorios
  escribibles `0750/0640` y nunca `777`.
- Base y usuario exclusivos de staging, con permisos limitados a esa base.
- Release inmutable sin `.git`, `.env`, `tests`, `pruebas`, backups,
  `instalar.php`, logs, sesiones ni material histórico.
- TLS válido, sesiones, uploads, imports, logs y secretos separados.

El dimensionamiento de CPU, RAM y disco debe decidirse con el proveedor y
medirse con el escenario sintético; no hay un tamaño documentado que pueda
presentarse aquí como requisito verificado.

## Secuencia mínima tras recibir accesos

1. Registrar autorización, responsables y ventana de trabajo.
2. Crear servidor y usuario de servicio dedicado.
3. Configurar `staging.<dominio>` con TTL acordado y verificar resolución.
4. Instalar runtime y TLS; bloquear acceso directo que eluda el proxy HTTPS.
5. Crear DB/usuario de staging y directorios persistentes independientes.
6. Construir una release limpia desde el commit autorizado y verificar sus
   exclusiones antes de transferirla.
7. Crear `.env` por canal seguro; nunca guardarlo en Git o en el artefacto.
8. Ejecutar preflight, migraciones v1–v26 y datos exclusivamente sintéticos.
9. Configurar backup externo, cifrado, cron y alertas.
10. Realizar restore descargando realmente desde el destino externo.
11. Ejecutar health/smoke/seguridad desde otra red y registrar tiempos.
12. Solo después organizar la visita de observación de Etapa 1.

No se inicia Etapa 2 hasta que el preflight real, restore externo y responsables
queden documentados como verificados.
