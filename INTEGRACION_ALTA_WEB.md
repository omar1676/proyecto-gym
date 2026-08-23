# Investigación del alta web de Cleto

Estado: **solo investigación y diseño**. F23 no crea API, webhook, importación ni sincronización con la web.

## Objetivo de la visita

Entender el flujo real de alta antes de elegir un contrato técnico. No se debe asumir que el formulario visible, el sistema que guarda los datos y el gestor que crea al socio son el mismo componente.

## Preguntas que deben responderse

- ¿Qué dominio y aplicación reciben actualmente el alta?
- ¿Cuál es el formulario exacto y qué campos son obligatorios?
- ¿Dónde se guardan los datos al enviar el formulario?
- ¿Qué software recibe finalmente el alta?
- ¿Existe API documentada? ¿Quién la mantiene y versiona?
- ¿Existe webhook saliente o entrante?
- ¿Hay acceso autorizado a una base de datos? ¿Es lectura, escritura o ambas?
- ¿Interviene un servicio Windows o una tarea programada?
- ¿Existe exportación/importación por CSV, Excel u otro formato?
- ¿Qué identificador estable une el registro web con el socio del gestor?
- ¿La contraseña pertenece a la web, al gestor o a ambos?
- ¿Quién crea el nombre de usuario y cómo evita colisiones?
- ¿El alta crea una membresía? ¿En qué estado y con qué fechas?
- ¿Se recoge IBAN? ¿Con qué consentimiento y validación?
- ¿Se crea un mandato SEPA? ¿Dónde queda la evidencia de aceptación?
- ¿Se envía email? ¿Desde qué sistema y con qué resultado observable?
- ¿Cómo se detectan duplicados de email, DNI/NIE y altas repetidas?
- ¿Qué ocurre si una parte acepta el alta y otra falla?
- ¿Existe reintento o reconciliación? ¿Quién atiende los fallos?
- ¿Qué volumen y picos de altas existen?
- ¿Qué datos son realmente necesarios y cuáles no deben transferirse?
- ¿Qué responsables autorizan el tratamiento, la integración y las pruebas?

## Evidencia que conviene recopilar

- Capturas del formulario y de todos sus estados de error, sin datos personales.
- Ejemplo sintético de la petición y respuesta, eliminando cookies, tokens y secretos.
- Documentación oficial de API/webhook, si existe.
- Esquema sintético de exportación/importación.
- Responsable técnico y procedimiento de soporte.
- Regla real de duplicado y de creación de membresía/mandato.

## Arquitectura conceptual futura (no implementada)

```text
WEB DEL GIMNASIO
        ↓
API GIMNERA AUTENTICADA Y VERSIONADA
        ↓
TenantContext calculado en servidor
        ↓
MISMO SocioRegistrationService DEL MOSTRADOR
        ↓
VALIDACIONES + IDEMPOTENCIA + TRANSACCIÓN
        ↓
BASE DE DATOS + AUDITORÍA
```

Principios propuestos para evaluar después de la visita:

- La web no elegirá libremente `empresa_id` ni `sede_id`.
- El alta web reutilizará el mismo servicio de aplicación que el alta de recepción.
- Cada petición tendrá autenticación técnica, idempotencia y `correlation_id`.
- DNI/NIE, email e IBAN seguirán las mismas validaciones que el mostrador.
- Ninguna contraseña viajará a logs, auditoría o respuestas de error.
- Un fallo parcial no podrá dejar socio, membresía, cobro o mandato incoherentes.
- Los reintentos devolverán el resultado previo o un estado reconciliable, sin duplicar.
- La integración empezará con datos sintéticos y entorno de pruebas.

## Decisiones pendientes

Contrato de autenticación, propiedad del identificador externo, sede destino, regla de membresía, gestión de contraseña, mandato SEPA, política de reintentos, soporte y responsabilidades de datos. Todas permanecen **NO VERIFICADAS** hasta la visita.
