# Onboarding reproducible de un gimnasio

Plantilla técnica-operativa reutilizable. No es autoservicio: hoy la creación de
`empresa` y del primer usuario `direccion` exige aprovisionamiento técnico SQL.
Esta brecha debe cerrarse antes de escalar altas frecuentes; para un piloto
controlado puede ejecutarla un operador autorizado con revisión por pares.

## 1. Acuerdo y alcance

- [ ] Responsable contractual y de datos identificado.
- [ ] Empresa, nombre comercial, CIF/datos de contacto validados.
- [ ] Sedes y responsables confirmados.
- [ ] Módulos incluidos/excluidos y feature freeze documentados.
- [ ] DPA/privacidad, retención y canal de soporte acordados.

## 2. Preparación técnica

- [ ] Tenant creado con ID registrado, sin reutilizar fixtures/defaults.
- [ ] Sedes creadas y asociadas exclusivamente a la empresa.
- [ ] Usuario de dirección nominal; entrega/rotación segura de credencial.
- [ ] Recepción/admin nominales y sede mínima necesaria.
- [ ] Marca, correo y configuración propios; no hay textos/logos de otro cliente.
- [ ] `ACCESS_CONTROL_MODE=disabled` salvo proyecto separado aprobado.

## 3. Configuración de negocio

- [ ] Tarifas, IVA, duración y suplementos validados.
- [ ] Política de impago, devolución, prueba y días de gracia decidida.
- [ ] Regla de caja abierta/cierre/diferencias decidida.
- [ ] Productos, stock de corte y mínimos conciliados.
- [ ] Acreedor/mandatos/remesas solo si están en alcance.
- [ ] Criterio fiscal confirmado por asesor competente.

## 4. Datos

- [ ] Export autorizado, cifrado, hash y fecha de borrado.
- [ ] Perfilado/dry-run sin datos de otros clientes.
- [ ] Duplicados y transformaciones aprobados.
- [ ] Importación staging repetible y conciliada.
- [ ] Corte/rollback/fuente de verdad firmados.

## 5. Validación

- [ ] Login, empresa/sede, roles y permisos cruzados.
- [ ] Socio, membresía, venta, stock, caja, remesa/informes acordados.
- [ ] Backup externo y restore del entorno objetivo.
- [ ] SMTP/HTTPS/monitor/cron verificados.
- [ ] Formación, soporte, incidente y GO/NO-GO.

## Evidencia F12

`tests/Integration/SecondGymGeneralizationTest.php` crea de forma efímera
“Gimnasio Demo Norte”, dos sedes, dirección, recepción, socios, tarifa,
producto, caja y venta solo en la base de test. Demuestra el aislamiento y
explicita las dos inserciones técnicas aún no encapsuladas.

## Elementos específicos de Cleto que no se copian a otro cliente

- Nombre, logotipos, colores, emails, CIF, direcciones y teléfonos.
- Tarifas, productos, saldos, stock y datos acreedor SEPA.
- Usuarios Pedro/Dani y cualquier credencial demostrativa.
- Política de caja/impagos mientras no haya sido aprobada como plantilla.
- Instalación, contrato, IDs o documentación DORLET/IDEMIA.
- Exportaciones y mapeos del sistema actual.
