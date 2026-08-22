# Auditoría de generalización F22

Clasificación previa al onboarding. No contiene secretos ni datos reales.

| Hallazgo | Clasificación | Tratamiento F22 |
|---|---|---|
| `migracion_v20.sql` nombra Centro Deportivo Cleto Reyes | HISTÓRICO INMUTABLE | No se modifica; preserva instalaciones existentes. |
| `pruebas/preparar_base.php`, fixtures y E2E con Cleto/Villaviciosa/personas sintéticas | FIXTURE/TEST | Se conservan como regresión histórica; nunca entran en release. |
| Logo y menciones de marca en documentación de despliegue | ASSET/DOCUMENTAL | No son fallback de tenant; se documenta que no se copian. |
| `README` presentado como proyecto Cleto | DOCUMENTAL | Renombrado a Gimnera. |
| `categoria_producto` global | BLOQUEADOR SaaS | v29 añade tenant, migra/remapea histórico y fuerza FK/unicidad por empresa. |
| Unicidades humanas globales | BLOQUEADOR SaaS | v29 las convierte a unicidad por empresa; el login se resuelve tras el nivel de sede. |
| Alta de empresa/primera dirección por SQL | BLOQUEADOR SaaS | Sustituida por `TenantProvisioningService` y área mínima superadmin. |
| Marca de cabecera basada solo en `APP_NOMBRE` cuando no había logo | RUNTIME HARDCODE | Usa el nombre de sede autenticada sin convertirlo en autoridad. |
| Credencial técnica de sede mínima de seis caracteres | RIESGO CONFIGURACIÓN | Altas/rotación nuevas exigen al menos 12; onboarding genera 32. |
| Importador resolvía categoría solo por nombre | P1 TENANT | Corregido: resolución por `id_empresa + nombre`; prueba hostil con homónimos. |
| Servicios aceptaban `superadmin` ligado a tenant | P1 AUTORIZACIÓN | Corregido: plataforma exige `id_empresa IS NULL`; `TenantContext` rechaza el estado inconsistente. |
| Flash de credenciales temporales en sesión | P1 SECRETOS | Corregido: claves solo en respuesta `no-store`, nunca serializadas en sesión. |
| Footer con contacto/horario de ejemplo | CONFIGURACIÓN PENDIENTE | No bloquea aislamiento; debe pasar a datos tenant antes de cliente real. |
| SMTP funcional, reglas de caja/impago y fiscalidad | DECISIÓN DE NEGOCIO | No se inventan; email nace disabled. |

## Decisiones

- La credencial técnica de sede se mantiene porque el acceso actual tiene dos
  niveles. Se audita como `actor_type=sede`, separada del usuario humano.
- Superadmin es plataforma; dirección/admin/recepción no pueden abrir rutas de
  onboarding ni autoescalar.
- El onboarding inicial es una sola transacción. La reentrada devuelve el
  tenant existente sin volver a mostrar claves.
- No se implementa borrado destructivo de empresas.
- DORLET, biometría, FitCloud real, SMTP a socios y datos reales quedan fuera.

## Evidencia de cierre

- Suite local final: 68 scripts, 876 assertions, 0 fallos, 0 omitidos, exit 0.
- Fallo deliberado del runner: 1 assertion fallida, exit global distinto de 0.
- VPS: PHP 8.3.6, MariaDB 10.11.14 y lint de 208 PHP sin fallos.
- Suite completa en VPS con usuario temporal limitado a bases de test: 876/876,
  exit 0 y cero bases temporales residuales.
- Escala sintética local: 100 tenants en 2.046,42 ms, p95 27,74 ms,
  1.603 consultas de sesión y 77.188 bytes de payload de filas. No es un
  benchmark de capacidad productiva.
- Ninguna prueba utilizó `gimnasio_staging`, datos reales, DORLET o biometría.
