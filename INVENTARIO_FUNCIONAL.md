# Inventario funcional actual

Estado auditado en Fase 7. `COMPLETO` significa completo para el alcance actual,
no equivalencia universal con otros productos.

| FUNCIONALIDAD | ESTADO | ROLES | EMPRESA/SEDE | TEST EXISTENTE | LIMITACIÓN | PRIORIDAD |
|---|---|---|---|---|---|---|
| Acceso en dos niveles (gimnasio + empleado) | COMPLETO | Dirección, admin, recepción | Empresa y sede validadas en servidor | `pruebas/acceso.php` | Requiere sustituir credenciales de demostración en despliegue | P0 |
| Logout y relevo de turno | COMPLETO | Personal | Conserva el acceso del local; elimina la sesión del empleado | `pruebas/acceso.php`, `SociosViewTest` | La salida completa del local es una acción separada | P0 |
| Recuperación de contraseña | PARCIAL | Personal | Usuario de su empresa | Security/HTTP | El envío depende de SMTP real, pendiente de infraestructura | P1 |
| Sesiones, CSRF y rate limiting | COMPLETO | Todos | Contexto calculado en backend | Unit, Security, HTTP | Requiere validar cookies/HTTPS en servidor real | P0 |
| Multiempresa y multisede | COMPLETO | Todos | `TenantContext` limita empresa y sede | `multiempresa.php`, `multisede.php` | Superadmin global sin panel comercial | P0 |
| Matriz de autorización | COMPLETO | Todos | Rol almacenado en BD | `autorizacion.php`, HTTP | Portal de socio aún no existe | P0 |
| Listado y búsqueda de socios | COMPLETO | Dirección, admin, recepción | Empresa/sede del contexto | `SociosPaginationTest`, Functional | 50 filas/página; búsqueda `%texto%` no es full-text | P0 |
| Alta y edición de socio | COMPLETO | Dirección, admin, recepción | Alta en sede activa; edición filtrada | Functional, multiempresa | Importación CSV es un flujo técnico separado | P0 |
| Baja y anonimización RGPD | PARCIAL | Modelo interno | Filtrada por tenant | `multiempresa.php` | Existe lógica de modelo, no un flujo administrativo completo expuesto | P1 |
| Cuotas, suplementos y precios | COMPLETO | Dirección, admin | Catálogo de empresa y, cuando aplica, sede | `suplementos.php`, permisos | Sin promociones ni reglas comerciales avanzadas | P0 |
| Contratación y renovación | COMPLETO | Dirección, admin, recepción | Socio y cuota del ámbito | `negocio.php`, `renovaciones.php` | No hay congelación/suspensión temporal | P0 |
| Pruebas temporales | COMPLETO | Dirección, admin, recepción | Sede activa | Functional | Duración fija configurada en código | P1 |
| Renovación y avisos automáticos | PARCIAL | Tarea técnica | Se ejecuta sede por sede | `renovaciones.php` | Depende de cron y SMTP configurados | P1 |
| Productos, imágenes y categorías | COMPLETO | Dirección, admin | Stock por sede | Functional, Security | Sin proveedores, compras ni transferencias entre sedes | P1 |
| Venta de mostrador | COMPLETO | Dirección, admin, recepción | Exige sede antes de comenzar | `negocio.php`, `facturacion.php`, concurrencia | Sin devolución parcial ni descuentos avanzados | P0 |
| Stock transaccional y concurrencia | COMPLETO | Dirección, admin | Producto de la sede | `ConcurrencyStockTest`, Integrity | No hay movimientos de almacén independientes | P0 |
| Anulación de venta | COMPLETO | Dirección, admin | Ticket de la sede | `facturacion.php`, autorización | Anulación completa, no parcial | P0 |
| Numeración, IVA y exportación CSV | PARCIAL | Dirección, admin | Serie por sede | `facturacion.php`, render | No genera factura legal/PDF ni integra fiscalidad externa | P1 |
| Caja y resumen por método de pago | COMPLETO técnico; política pendiente | Dirección, admin | Sede/empresa | `CashTest`, `CashConcurrencyTest`, Functional | Debe decidirse si vender/cobrar exige caja abierta | P0 de decisión |
| Mandatos y remesas SEPA | COMPLETO para fichero | Dirección, admin | Acreedor y recibos por sede | `sepa.php`, multiempresa | Envío al banco y conciliación siguen siendo externos/manuales | P0 |
| Gestión de empleados | COMPLETO | Dirección, admin según ámbito | Empresa/sede y reglas de rol | `personal.php`, autorización | Sin turnos ni planificación laboral | P1 |
| Gestión y marca de sedes | COMPLETO | Dirección/superadmin | Solo propia empresa | `personal.php`, multisede | Configuración comercial de empresa limitada | P1 |
| Informes operativos | PARCIAL | Dirección, admin | Ámbito autorizado | render, consultas de rendimiento | Sin informes configurables ni BI avanzado | P1 |
| Auditoría de actividad | PARCIAL | Dirección, admin | Empresa/sede | multiempresa, render | Límite de 200 entradas; sin exportación ni alertas | P1 |
| Backups, migraciones y operación | COMPLETO en diseño local | Operación | Global | smoke, restore de fases previas | Copia externa y servidor real pendientes | P0 |
| Importación masiva CSV | COMPLETO socios/productos; DRY-RUN membresías | Superadmin, dirección | Empresa y sede del TenantContext | Unit, Integration, Security, Functional, 5.000 filas | XLSX/JSON y ventas/cobros históricos pendientes | P0 |
| Control físico de accesos | NO EXISTE | — | — | Ninguno | DORLET/QR/NFC fuera del alcance actual | FUTURO |
| Histórico de accesos y aforo | NO EXISTE | — | — | Ninguno | Depende del futuro proveedor de acceso | FUTURO |
| Portal/app del socio | NO EXISTE | Socio futuro | Solo recursos propios | Regla `canOwn` existente | No hay interfaz ni autenticación de socio habilitada | FUTURO |
| SMS | NO EXISTE | — | Configuración futura por empresa | Ninguno | Sin proveedor ni consentimiento definido | FUTURO |
