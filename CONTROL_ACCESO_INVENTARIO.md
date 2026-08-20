# Inventario pasivo para la visita de control de acceso

Este documento sirve para observar y preguntar. No autoriza cambios, escaneo de
red, capturas de tráfico, extracción de credenciales ni pruebas físicas.

## Resultado local previo

**VERIFICADO el 20/08/2026:**

- no se encontró servicio o proceso local llamado DORLET, DASS/DASSnet,
  FitCloud o IDEMIA;
- no se encontró software instalado con esos nombres;
- no se encontró manual o documentación técnica local con esos nombres;
- el repositorio solo contiene referencias conceptuales propias.

**INTERFAZ REAL NO VERIFICADA.** No se afirma que exista API, SDK, REST, SOAP,
web service, OPC, acceso SQL ni módulo de integración.

## Componentes: no confundir

```text
LECTOR ≠ CONTROLADORA ≠ SERVIDOR ≠ SOFTWARE DE GESTIÓN
```

Para cada elemento hay que identificar dónde reside la identidad, la
autorización, los horarios, los eventos, la decisión offline y la comunicación
física.

## Checklist de visita

### Organización y autorización

- [ ] Responsable del gimnasio presente.
- [ ] Autorización escrita para observación y futura prueba.
- [ ] Proveedor/mantenedor y contacto.
- [ ] Contrato y módulos de integración contratados.
- [ ] Ventana futura de prueba acordada.

### Lector

- [ ] Fabricante:
- [ ] Modelo exacto:
- [ ] Número de serie fotografiable/autorizado:
- [ ] Tipo de credencial: huella/tarjeta/PIN/otro:
- [ ] Conexión física con la controladora:
- [ ] ¿Decide localmente o solo lee?:

### Controladora

- [ ] Fabricante:
- [ ] Modelo:
- [ ] Firmware visible desde administración:
- [ ] Ubicación:
- [ ] Número de puertas/zonas:
- [ ] Comportamiento offline documentado:
- [ ] Backup de configuración disponible:

### Servidor o PC

- [ ] Equipo y ubicación:
- [ ] Sistema operativo:
- [ ] Software instalado:
- [ ] Versión exacta:
- [ ] Servicios relacionados:
- [ ] Base de datos usada por el proveedor:
- [ ] Copia/rollback administrado por el proveedor:

### Integración oficial

- [ ] Documentación oficial entregada.
- [ ] API/SDK/web service confirmado por escrito.
- [ ] Versión y compatibilidad.
- [ ] Autenticación y gestión de secretos.
- [ ] Entorno de pruebas o sandbox.
- [ ] Rate limits/timeouts.
- [ ] Identificadores e idempotencia.
- [ ] Lectura de eventos y cursor.
- [ ] Soporte del fabricante para rollback.

### Red — solo observación legítima

- [ ] Diagrama existente.
- [ ] IPs visibles en la consola autorizada.
- [ ] Segmentación/VLAN.
- [ ] Flujo lector → controladora → servidor.
- [ ] Acceso remoto actual del mantenedor.
- [ ] Restricciones de firewall conocidas.

No ejecutar barridos de puertos, sniffing, MITM ni ingeniería inversa.

## Información que necesitamos del proveedor

1. Contrato oficial de integración y licencia.
2. Operaciones soportadas sin escritura directa en su base.
3. Modelo de identidad externa y alcance tenant/sede.
4. Semántica exacta de habilitar, bloquear y caducar.
5. Eventos reales, IDs únicos y orden temporal.
6. Comportamiento ante caída de red/servidor.
7. Backup, restauración y rollback soportados.
8. Política y responsabilidad sobre biometría.
