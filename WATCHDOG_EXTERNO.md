# Watchdog externo de Gimnera

Estado: **PREPARADO / NO VERIFICADO**. No existe aún un ejecutor independiente
autorizado fuera del VPS.

Ejecutar `php ops/external_watchdog.php https://staging.gimnera.es` cada cinco
minutos desde un servicio externo. Exit 0 es OK; exit 2 exige alerta humana.
Comprueba `/health`, `/heartbeat`, validez TLS, antigüedad máxima de 300 segundos
y latencia. No necesita acceso SSH, DB ni puertos nuevos.

Prueba de fallo segura: en el ejecutor externo se apunta temporalmente a un
hostname `.invalid` o a una copia sintética de heartbeat antiguo. Debe producir
exit 2 y una alerta del proveedor. No se tumba staging. Hasta recibir esa alerta
el watchdog permanece NO VERIFICADO.
