# Generalización y segundo gimnasio sintético

## Prueba ejecutable

`tests/Integration/SecondGymGeneralizationTest.php` crea y elimina, solo con
`APP_ENV=test`, una empresa “Gimnasio Demo Norte” con dos sedes, dirección,
recepción, dos socios, una tarifa, un producto, una caja abierta y una venta.
Comprueba pertenencia, stock, movimiento de caja y denegación desde otro tenant.

## Resultado de la auditoría de nombres

| Hallazgo | Ubicación | Clasificación | Decisión |
|---|---|---|---|
| Logos Cleto | `public/assets/gimnasios`, `public/assets/marca`, `recursos` | FIXTURE/ASSET | Conservar; marca configurable |
| Datos Cleto de empresa inicial | `migracion_v20.sql` | DEFAULT histórico | Conservar para migrar legado; no usar para nuevos tenants |
| Emails/nombres en pruebas | `pruebas/*` | FIXTURE | Conservar mientras sean sintéticos |
| Menciones en comentarios de vistas/helpers | login/sedes/Marca | DOCUMENTACIÓN | No afectan ejecución; limpiar cuando se revise copy técnico |
| Mensajes de deuda mencionaban Cleto | `AccessEligibilityService.php` | BUG RUNTIME | Corregido a “política comercial de la empresa” sin cambiar reglas |
| Alta empresa/dirección sin servicio | No existe modelo/controlador de provisioning | BRECHA RUNTIME/OPERACIÓN | P1: requiere SQL técnico controlado |

## Conclusión

Los modelos de sede, socio, tarifa, producto, venta y caja funcionan con un
segundo tenant sin modificar PHP por cliente. No se necesitaron rutas,
constantes ni consultas manuales específicas de Cleto para operar. Sí se
necesitó SQL parametrizado para crear la empresa y el primer usuario de
dirección: la aplicación es multi-tenant en operación, pero el onboarding aún
no es autoservicio ni totalmente encapsulado.

