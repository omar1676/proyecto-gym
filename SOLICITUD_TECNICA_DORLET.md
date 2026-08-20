# Solicitud técnica a DORLET o al instalador

No enviar hasta completar los campos entre corchetes y confirmar quién es el
mantenedor autorizado de la instalación.

## Canal recomendado

- Instalador/mantenedor actual de Cleto Reyes.
- Formulario oficial: <https://www.dorlet.com/es/contacto>.
- Área documental de clientes: <https://docs.dorlet.com/>.

## Mensaje

**Asunto:** Solicitud de información técnica para integración oficial con el
control de accesos de Centro Deportivo Cleto Reyes

Buenos días:

Estamos preparando, con autorización del Centro Deportivo Cleto Reyes, una
integración entre su aplicación de gestión y la instalación de control de
accesos existente. En esta fase no se pretende abrir puertas ni modificar
usuarios, permisos, horarios, controladoras o datos biométricos. El objetivo es
identificar la interfaz oficial soportada y comenzar, si resulta viable, con
operaciones exclusivamente de lectura.

Necesitamos confirmar:

1. Nombre exacto del producto instalado: DASS, DASSnet u otro.
2. Versión, edición, módulos licenciados y estado de soporte.
3. Modelos y firmware de controladoras y lectores relevantes.
4. Si la instalación dispone o puede licenciar el **SDK Integración módulo de
   accesos, referencia D9110400**, u otra API/webservice oficial equivalente.
5. Versiones compatibles, requisitos de sistema y lenguaje/runtime del SDK.
6. Documentación contractual, ejemplos y fake/sandbox de integración.
7. Mecanismo de autenticación, rotación y permisos mínimos de una cuenta
   técnica dedicada.
8. Operaciones oficiales disponibles para:
   - comprobar salud/conectividad;
   - localizar una identidad externa exacta;
   - leer estado y permisos no biométricos;
   - consultar eventos con ID único, cursor y paginación;
   - gestionar, en una fase posterior, altas, bajas y permisos.
9. Semántica de estados, códigos de error, rate limits y timeouts recomendados.
10. Modelo offline: dónde residen los permisos, cuánto persisten y qué ocurre
    cuando el servidor o la comunicación no están disponibles.
11. Tiempo y confirmación de propagación desde el software hasta las
    controladoras.
12. Procedimiento soportado de backup, restauración y rollback para una prueba
    reversible con un único usuario autorizado.
13. Compatibilidad con el lector/enrolador IDEMIA MSO 300 observado, sin
    necesidad de extraer ni transferir biometría al nuevo SaaS.
14. Relación técnica soportada con el módulo de accesos de FitCloud, si forma
    parte de esta instalación.
15. Licenciamiento, mantenimiento y soporte necesarios para una integración
    B2B en producción.

La arquitectura propuesta mantiene los datos biométricos fuera del SaaS y
utiliza un identificador externo opaco. No necesitamos acceso a bases internas,
credenciales de otros clientes ni endpoints privados. Preferimos una interfaz
documentada y soportada por DORLET.

¿Podrían indicarnos el contacto técnico apropiado y facilitarnos la
documentación aplicable a esta instalación?

Gracias.

Atentamente,

[NOMBRE Y CARGO]<br>
[EMPRESA]<br>
[TELÉFONO]<br>
[EMAIL]<br>
[REFERENCIA DEL INSTALADOR O CONTRATO, SI EXISTE]

## Documentación a solicitar como evidencia

- [ ] Ficha de instalación y versiones.
- [ ] Contrato/licencia del módulo de integración.
- [ ] Manual del SDK/API correspondiente a la versión instalada.
- [ ] Matriz de operaciones read-only/write.
- [ ] Guía de autenticación y rotación de secretos.
- [ ] Catálogo de errores, límites y timeouts.
- [ ] Modelo de eventos, IDs y cursor.
- [ ] Documento de arquitectura y funcionamiento offline.
- [ ] Procedimiento de backup y rollback.
- [ ] Condiciones del entorno de pruebas y soporte.

No incluir en la solicitud exportaciones de socios, huellas, contraseñas,
tokens, backups ni datos pertenecientes a otros clientes.
