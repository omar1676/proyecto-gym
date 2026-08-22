# Onboarding SaaS repetible de un gimnasio

Procedimiento oficial F22. No requiere SQL manual, IDs elegidos a mano,
fixtures de cliente ni cambios de PHP. El alta inicial se ejecuta desde
`Empresas` por un `superadmin`; la configuración posterior utiliza las
pantallas productivas de sedes, personal, membresías, productos e
importaciones.

## 1. Alcance y seguridad

- Solo `superadmin` puede listar, crear o activar empresas.
- La solicitud web es `POST` con CSRF e idempotency key opaca generada por el
  servidor y transportada por el formulario; no concede autoridad.
- Empresa, primera sede, dirección, categorías, tarifa inicial y auditoría se
  confirman en una única transacción. Un fallo revierte todo.
- La empresa nace `inactiva/CONFIGURING` y termina
  `inactiva/READY_FOR_REVIEW`; solo el gate de activación la pasa a
  `activa/ACTIVE`.
- Las claves temporales de sede y dirección son aleatorias, diferentes y se
  muestran en la respuesta no cacheable del alta. No se serializan en sesión;
  solo sus hashes se persisten.
- Email funcional e importación nacen desactivados/omitidos.
- `ACCESS_CONTROL_MODE=disabled`; no se conecta DORLET ni se almacena
  biometría.

## 2. Alta inicial

Prerequisito de una instalación que todavía no tenga identidad de plataforma:
un operador autorizado ejecuta una sola vez `ops/bootstrap_platform_admin.php`
con los cinco campos `PLATFORM_ADMIN_*` recibidos por entorno y la confirmación
del entorno. El comando no muestra la contraseña, se bloquea contra carreras,
se audita y se niega a crear una segunda identidad. No promueve cuentas de un
gimnasio ni requiere SQL manual.

1. Entrar con cuenta nominal `superadmin`.
2. Abrir `Empresas`.
3. Completar razón social, nombre comercial, contacto, primera sede, email
   técnico de la sede, dirección nominal, colores, zona horaria y categorías.
4. Opcionalmente crear una tarifa inicial con las reglas económicas existentes.
5. Guardar las dos credenciales temporales en el gestor autorizado. No
   copiarlas a tickets, correo, Git, Markdown o logs.
6. Revisar que el estado sea `READY_FOR_REVIEW` y que existan exactamente una
   sede activa y una dirección activa.
7. Activar. El gate rechaza migraciones pendientes/incoherentes y configuración
   insegura.

Reenviar la misma solicitud no duplica nada y no vuelve a revelar claves. Si se
perdieron, se rotan mediante los flujos existentes; no se recupera texto claro.

## 3. Configuración posterior por interfaces productivas

- `Sedes`: añadir sedes, contacto, marca y credencial técnica rotatable.
- `Personal`: crear administradores y recepción nominales, con sede y rol.
- `Membresías`: crear tarifas propias; moneda soportada en F22: EUR.
- `Productos`: crear categorías propias y productos/stock por sede.
- `Importaciones`: terminar sin importación o ejecutar carga sintética/autorizada
  con dry-run, revisión y confirmación. No se importa FitCloud real en F22.

No se duplica la matriz de permisos: se reutiliza `Authorization`. Dirección no
puede crear empresas ni asignar `superadmin`.

## 4. Decisiones de ámbito

| Dato | Ámbito F22 | Motivo |
|---|---|---|
| Email/username/DNI humano | Por empresa | El primer nivel fija la empresa antes de resolver la identidad humana. |
| Email técnico de sede | Global | Identifica la sede antes de existir `TenantContext`. |
| Nombre de sede | Por empresa | Dos empresas pueden compartir nombre; una empresa no duplica la misma sede. |
| Categoría de producto | Por empresa | Cada gimnasio administra su catálogo. |
| Tarifa | Por empresa/sede según modelo existente | Mantiene el aislamiento actual. |
| Numeración de venta | Sede + serie + ejercicio | Cada sede empieza su secuencia sin heredar otro tenant. |
| Configuración/branding | Empresa y sede | No se hereda marca ni SMTP funcional de otro cliente. |

## 5. Revisión antes de activar

- [ ] Empresa, nombre comercial y contacto revisados.
- [ ] Primera sede y email técnico correctos.
- [ ] Dirección nominal, activa y sin sede restrictiva.
- [ ] Claves temporales custodiadas y rotación planificada.
- [ ] Branding propio; no aparecen marcas de otro cliente.
- [ ] Categorías y tarifas pertenecen al nuevo tenant.
- [ ] Email funcional `disabled`.
- [ ] Importación `SKIPPED` o validada por el flujo existente.
- [ ] `ACCESS_CONTROL_MODE=disabled`.
- [ ] Migraciones `pending=[]`, checksum/estructura sin diferencias.
- [ ] Eventos `ONBOARDING_*`, `TENANT_CREATED`, `SEDE_CREATED` y
  `OWNER_CREATED` presentes sin secretos.

## 6. Reentrada, concurrencia y cancelación

La idempotency key evita duplicados por doble clic, reintento o dos procesos.
Las unicidades de base protegen empresa, sede e identidades. La unidad inicial
es atómica, por lo que una interrupción no deja empresa/sede/owner huérfanos.

No existe borrado web de empresas. Los tests crean bases efímeras y las
eliminan completas. Un tenant sintético de staging se conserva identificado o
se marca para revisión operativa; no se borra mediante SQL ad hoc. Diseñar una
cancelación productiva con retención/auditoría es trabajo separado.

## 7. Segundo gimnasio

La suite `AtlasOnboardingTest` crea “Gimnasio Atlas Test”, Atlas Centro y Atlas
Norte, dirección, admin, recepción, socio, tarifa, membresía, categorías y
producto mediante el servicio y modelos productivos. La incorporación no exige
un diff ni SQL específico del cliente. Es evidencia sintética, no validación
con datos reales.

## 8. Datos que nunca se copian de un cliente

Nombres, logos, colores, emails, CIF, direcciones, teléfonos, tarifas,
productos, stock, acreedor SEPA, usuarios, credenciales, políticas no
aprobadas, IDs, mapeos, exportaciones, contratos DORLET/IDEMIA o biometría.
