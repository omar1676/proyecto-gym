# Panel de gestión del gimnasio

## Operación de producción — fase 5

### Arquitectura recomendada

```text
/var/www/gimnasio/
├── releases/<commit>/        código inmutable, propietario deploy
├── current -> releases/...   symlink de la versión activa
└── shared/
    ├── .env                  secretos, 0640
    ├── uploads/              escritura del usuario PHP
    ├── logs/                 escritura del usuario PHP
    └── backups/              temporal; nunca bajo public/
```

El document root es exclusivamente `current/public`. El proceso web solo necesita
escritura en uploads, logs y sesiones si se almacenan en disco. Código, `app/`,
`ops/`, `cron/`, migraciones y tests deben ser de solo lectura. No usar `777`:
directorios compartidos `0750`, archivos `0640`, código `0755/0644`.

Requisitos: Linux, Apache 2.4 o Nginx, PHP **8.1+** (recomendado 8.2) con
`pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl`, `dom`, `simplexml` y
`zlib`; MariaDB 10.4+ o MySQL 8; UTF-8 `utf8mb4`; zona `Europe/Madrid`; TLS
válido. Hay ejemplos en `ops/server/`.

### Secuencia reproducible

1. Crear una release desde un commit/tag inmutable.
2. Enlazar `.env`, uploads y almacenamiento compartido.
3. `php ops/setup_directories.php`.
4. `php ops/preflight.php`; cualquier pendiente obligatorio detiene.
5. `php cron/copia_seguridad.php` y `php cron/copia_archivos.php`.
6. Activar mantenimiento en el balanceador/web si la migración bloquea tablas.
7. `php ops/migrate.php --confirm-production`; cualquier error detiene.
8. Cambiar `current` atómicamente a la release nueva.
9. `php ops/status.php` y `php ops/smoke.php https://dominio`.
10. Quitar mantenimiento y observar logs/monitor durante 30 minutos.

`php ops/deploy.php --confirm-production --url=https://dominio` ejecuta las
comprobaciones, copias, migración y smoke tests. La creación/activación de la
release sigue a cargo del sistema del servidor porque depende del hosting.

### Migraciones y rollback

`schema_migrations` conserva nombre, SHA-256, release y fecha. Un checksum
alterado o una migración fallida devuelve código distinto de cero y detiene el
despliegue. No editar migraciones aplicadas: crear la siguiente.

Para volver atrás tras un fallo de ventas: poner mantenimiento, conservar logs,
volver el symlink `current` a la release anterior y ejecutar smoke tests. Si la
migración solo añadió estructuras compatibles, no se revierte la base. Si fue
destructiva/incompatible, restaurar el backup predespliegue implica perder los
datos escritos desde ese backup: requiere decisión explícita del responsable.
No se promete rollback SQL automático.

### Backups y restauración

- MySQL cada 6 horas: `cron/copia_seguridad.php`.
- Uploads/configuración no secreta a diario: `cron/copia_archivos.php`.
- Retención GFS: 7 diarios, 4 semanales y 6 mensuales.
- Cada artefacto tiene SHA-256 y se valida antes de considerarse correcto.
- `COPIAS_EXTERNAS_DIR` debe ser un volumen/bucket sincronizado **fuera del
  servidor**. Vacío es una alerta, no un éxito.

Restauración ensayada:

```bash
php ops/restore.php --database=/backup/backup_db.sql.gz \
  --target=gimnasio_restore_ensayo \
  --files=/backup/backup_files.tar.gz \
  --files-target=/restore/uploads
php ops/verify_restore.php gimnasio_restore_ensayo
```

En un servidor limpio: instalar runtime, desplegar la misma release, recuperar
`.env` desde el gestor seguro, restaurar archivos y MySQL, ejecutar
`ops/migrate.php`, preflight, status y smoke; después cambiar DNS/balanceador.

Objetivos del piloto una vez activa la copia externa: **RPO 6 horas para MySQL,
24 horas para archivos; RTO 4 horas**. Mientras no exista copia externa, el RPO
ante pérdida total del servidor está PENDIENTE y no es aceptable para el piloto.

### Cron propuesto

```cron
0 */6 * * * php /var/www/gimnasio/current/cron/copia_seguridad.php
20 2 * * * php /var/www/gimnasio/current/cron/copia_archivos.php
40 2 * * * php /var/www/gimnasio/current/cron/mantenimiento.php
0 6 * * * php /var/www/gimnasio/current/cron/tareas.php
*/5 * * * * php /var/www/gimnasio/current/cron/monitor.php
```

Las salidas se envían al sistema de cron y a logs. `monitor.php` devuelve error
si falla DB, disco, backups, copia externa, health o aumenta el número de
errores. Configurar alerta de correo/SMS en el proveedor; el proyecto no incluye
una plataforma de observabilidad.

Véanse también `INCIDENTES.md`, `CHECKLIST_PRODUCCION.md` y
`PILOTO_CLETO_REYES.md`.

Adaptación del portal de inscripciones a cursos para gestionar **venta de productos**
y **socios/membresías**. Mantiene la arquitectura MVC original: front controller en
`public/index.php`, modelos con PDO, vistas PHP con Tailwind.

El control de acceso por huella dactilar es un sistema aparte y no forma parte de esto.

---

## 1. Puesta en marcha

### Requisitos
PHP 8.1+ con PDO MySQL, y MySQL 8+ / MariaDB 10.4+.

### Configuración

Copia `.env.example` a `.env` y ajusta:

```
DB_HOST=localhost
DB_PORT=3306          # ¡ojo! en XAMPP local puede ser 3307
DB_NAME=portal_de_cursos
DB_USER=usuario
DB_PASS=contraseña
DB_CHARSET=utf8mb4

APP_ENV=production
APP_URL=https://tu-dominio.es      # sin barra final
APP_NOMBRE=Nombre del Gimnasio
APP_LOGO=logo-cleto-reyes.png      # archivo dentro de public/assets/marca/
```

`APP_URL` alimenta **todos** los redirects y enlaces del panel; `APP_NOMBRE` aparece
en títulos, cabecera, pie y correos. Cambiar de dominio o de marca es tocar solo estas
dos líneas.

`APP_LOGO` es el logo de la instalación: sale en la pantalla de acceso inicial y en la
cabecera del panel. Sube el archivo a `public/assets/marca/` y escribe aquí su nombre;
si lo dejas vacío (o el archivo no está) se mantiene el icono genérico con `APP_NOMBRE`.

**`APP_ZONA_HORARIA`** (por defecto `Europe/Madrid`) fija la hora de PHP y de MySQL a la
vez. Sin ella, la caja "del día" y los vencimientos dependen de cómo esté configurado el
servidor, que en alojamiento compartido suele ir en UTC.

### Correo saliente

```
MAIL_FROM=noreply@tu-dominio.es    # TIENE que ser del dominio propio
MAIL_NOMBRE=Centro Deportivo Cleto Reyes
MAIL_SMTP_HOST=smtp.tu-proveedor.es
MAIL_SMTP_PUERTO=587
MAIL_SMTP_USUARIO=...
MAIL_SMTP_CLAVE=...
MAIL_SMTP_SEGURIDAD=tls           # tls (587), ssl (465) o vacío
```

Si `MAIL_SMTP_HOST` está relleno se envía por SMTP; si no, por `mail()` del servidor.
**En alojamiento compartido, usa SMTP.** Con `mail()` el correo sale con la identidad del
servidor, no la del dominio del gimnasio: el receptor comprueba el SPF, no cuadra, y el
aviso de vencimiento acaba en spam. De eso viven los recordatorios de cuota.

### Base de datos

Aplica los scripts de `app/config/` **en orden**, desde phpMyAdmin:

| Script | Contenido |
|---|---|
| `schema.sql` | Tablas base del portal original |
| `migracion.sql` | Roles, intentos de login, foto, activo |
| `migracion_v2.sql` | Tokens de reseteo de contraseña |
| `migracion_v3.sql` | Log de actividad y verificación de email |
| `migracion_v4.sql` | Plazas máximas |
| `migracion_v5.sql` | Imagen de curso |
| `migracion_v6.sql` | **Gimnasio**: roles, productos, ventas, membresías |
| `migracion_v7.sql` | **Suplementos** sobre la cuota base + cuota mensual a 40 € |
| `migracion_v8.sql` … `v16.sql` | Multisede, SEPA, personal y el cambio de `propietario` a `empresa` |
| `migracion_v17.sql` | **Facturación**: IVA, numeración de tickets y anulación sin borrado |
| `migracion_v18.sql` | Cambiar la contraseña cierra las sesiones abiertas |
| `migracion_v20.sql` | **Multiempresa**: empresas, pertenencia de sedes/usuarios/catálogos y auditoría |
| `migracion_v21.sql` | Integridad de importes/stock, claves de idempotencia e índices de rate limiting |
| `migracion_v22.sql` | Registro y checksums de migraciones |

> De la v5 en adelante los scripts no llevan `USE`: selecciona la base de datos antes
> de ejecutarlos (phpMyAdmin lo hace solo; por línea de comandos usa `-D nombre`).

**Haz copia de seguridad antes de las migraciones.** La v20 conserva el histórico,
crea la empresa inicial y le asigna las sedes y datos existentes; también convierte el
antiguo rol global `empresa` en `superadmin`.

### Carpetas de subida

Deben existir y tener permisos de escritura:
`public/assets/fotos/`, `public/assets/productos/`, `public/assets/cursos/`

### Carpetas que crea el sistema

`public/assets/marca/` (logo de la instalación) y `copias/` (volcados de la base) se
crean solas la primera vez que hacen falta. **`copias/` no debe quedar dentro de
`public/`**: el volcado lleva datos personales e IBAN. El script le pone su `.htaccess`,
pero la protección de verdad es que esté fuera del alcance de la web.

### Seguridad

**Borra `instalar.php` del servidor** cuando termines. Mientras esté subido, cualquiera
que lo abra puede resetear las cuentas `admin`, `recepcion` y `socio` a la contraseña
por defecto.

Con `APP_ENV=production` los errores dejan de salir por pantalla y se escriben en el log.
Un aviso de PHP sin querer enseña la ruta del servidor, el nombre de las tablas y a veces
la consulta entera al primero que pase por la web.

Cambiar la contraseña **cierra las sesiones abiertas** de esa cuenta en otros
dispositivos; la sesión desde la que se cambia sigue funcionando. Es lo que uno espera
cuando cambia la clave porque cree que alguien ha entrado.

---

## 2. Roles

| Rol | Acceso |
|---|---|
| `superadmin` | Operador interno de la plataforma; no pertenece a una empresa cliente |
| `direccion` | Todo dentro de su empresa y sus sedes; no pertenece a una sede concreta |
| `admin` | Todo dentro de **su** sede: productos, ventas, socios, membresías, remesas, reportes, log |
| `recepcion` | Mostrador de su sede: inicio, ventas y socios |
| `socio` | Ninguno: existe como dato del negocio, no inicia sesión |

El contexto del servidor obtiene usuario, empresa, sede y rol desde la cuenta autenticada;
ningún `empresa_id` enviado por el navegador decide el ámbito de una operación.

### Aislamiento entre sedes

Cada modelo se construye atado a la sede de quien ha entrado, y **el filtro se aplica
también al buscar por id**. Es importante entenderlo: `buscarPorId()` no es solo una
consulta, es la comprobación de permisos de casi todas las acciones del panel ("¿existe
este socio?" significa "¿existe *en mi sede*?"). Si se le quita el filtro, un id tecleado
a mano en la petición vuelve a alcanzar a gente de otra sede.

Dirección puede trabajar con todas las sedes de su empresa. Admin y recepción quedan
atados a su sede. Todos los modelos de negocio aplican además el límite de empresa.

El login y "Mi perfil" usan modelos **sin** sede a propósito: ahí todavía no se sabe de
qué gimnasio es nadie.

### La empresa tiene que elegir sede para dar de alta

El selector de la cabecera admite "Todas las sedes", que sirve para *mirar* (informes,
listados, historial). Pero todo lo que se da de alta guarda la sede en la que se hizo, y
sin ninguna fijada la fila nacería con `id_gimnasio NULL`: como el resto de listados
filtran por sede, esa venta o esa membresía dejaría de existir para todo el mundo.

Por eso las altas (venta, socio, membresía, prueba, mandato, producto, remesa) y la
descarga del fichero SEPA exigen una sede concreta y avisan si no la hay. El fichero SEPA
además se emite con el IBAN y el identificador de acreedor **de esa sede**, nunca con los
del primer gimnasio de la tabla.

---

## 3. Modelo de datos añadido

```
categoria_producto ──< producto ──< venta_linea >── venta ──> usuario (socio)
                                                              usuario (quien cobra)
tipo_membresia ──< socio_membresia >── usuario (socio)
suplemento     ──<
```

Tres decisiones que conviene conocer antes de tocar nada:

**Las líneas de venta congelan nombre y precio.** `venta_linea` guarda
`nombre_producto` y `precio_unitario` copiados en el momento de la venta. Si mañana
sube el precio de la proteína, los reportes de meses anteriores siguen cuadrando.

**Las membresías no guardan un campo "activa".** El estado se deduce comparando
`fecha_fin` con la fecha de hoy. Así nunca queda desincronizado y el aviso de
"próximas a vencer" es una consulta por rango. Al renovar antes de tiempo, la nueva
membresía empieza el día siguiente al vencimiento de la anterior: el socio no pierde
los días que le quedaban.

**El plus de disciplinas es un `suplemento`, no un tipo de cuota aparte.** Evita
duplicar el catálogo ("Mensual" y "Mensual + artes marciales"), permite cambiar el
precio base y el del plus por separado, y deja separar en los reportes lo que entra
por cuotas de lo que entra por extras. El importe del suplemento se cobra por cada
mes que dure la cuota base: trimestral con un plus de 25 €/mes son 75 €.

### Tarifas actuales

| Concepto | Precio |
|---|---|
| Cuota base mensual | 40 € |
| Artes marciales (boxeo, MMA, jiu-jitsu…) | +25 €/mes |
| Mensual + artes marciales | 65 € |

Trimestral (95 €) y Anual (330 €) siguen con **valores de ejemplo** anteriores: con la
base a 40 €/mes no cuadran. Ajústalos o desactívalos desde el panel, en Membresías.

---

## 4. Cómo funciona una venta

`VentaModel::registrar()` hace todo dentro de **una transacción**: cabecera, líneas y
descuento de stock. El descuento usa

```sql
UPDATE producto SET stock = stock - :cantidad
 WHERE id_producto = :id AND stock >= :minimo
```

Si otra caja vendió las últimas unidades entremedias, no afecta a ninguna fila, se
detecta con `rowCount() === 0` y se hace rollback completo. Por eso el stock no puede
quedar negativo aunque dos personas cobren a la vez.

Los precios se leen de la base de datos, nunca del formulario.

### Número de ticket

Cada venta lleva un correlativo **por sede, serie y año**: `A-2026-000001`. Se calcula
dentro de la misma transacción y con `FOR UPDATE`, así que dos cajas cobrando a la vez
no se llevan el mismo número. El 1 de enero se vuelve a empezar por el 1.

### IVA

Los precios que se teclean son **PVP con el IVA incluido** — lo que paga el cliente y lo
que pone la etiqueta. La base imponible se calcula hacia atrás (`precio / (1 + iva/100)`)
y se guarda congelada en cada línea, junto con el tipo aplicado: si mañana cambia el IVA
de un producto, lo ya cobrado se sigue desglosando como se cobró.

El tipo por defecto es el 21 %. Se cambia producto a producto y cuota a cuota.

### Anular NO borra

Anular marca la venta como `anulada`, devuelve el stock y guarda quién, cuándo y por qué.
La fila **se queda**, con su número y su importe.

Esto no es un capricho: un registro de cobro que desaparece es un agujero en la caja y,
con la ley antifraude, algo que el software no debe permitir. En los listados la venta
anulada sigue saliendo, tachada, para que el hueco en la numeración tenga explicación; en
los totales, la caja del día y los informes **no suma**.

---

## 4-bis. Tareas automáticas (cron)

Sin esto, cobrar la cuota del mes siguiente significa entrar socio por socio a renovar a
mano. Son dos archivos, los dos por línea de comandos:

```
0  6 * * *  /usr/bin/php /ruta/proyecto/cron/tareas.php           >> /ruta/logs/cron.log 2>&1
30 3 * * *  /usr/bin/php /ruta/proyecto/cron/copia_seguridad.php  >> /ruta/logs/copias.log 2>&1
```

**`cron/tareas.php`** hace tres cosas, sede por sede:

| Tarea | Cuándo | Qué hace |
|---|---|---|
| `renovar` | a diario | Renueva las cuotas **domiciliadas** que vencen en 3 días o menos, encadenando desde el día siguiente al vencimiento, y avisa al socio por correo |
| `avisar` | a diario | Avisa por correo a quien le vence la cuota en 7 días y **no** se le renueva sola |
| `remesa` | el día 1 | Crea la remesa del mes con los recibos pendientes. **No la envía**: alguien tiene que descargarla y subirla al banco |

Solo se renuevan solas las cuotas por **transferencia**: las de efectivo o datáfono hay
que cobrarlas en el mostrador. El socio puede pedir que no se le renueve (`renovar_auto`).

Antes de ponerlo en el cron, pruébalo en seco: `php cron/tareas.php --simular` enseña lo
que haría sin tocar nada. También admite una sola tarea: `php cron/tareas.php renovar`.

**`cron/copia_seguridad.php`** vuelca la base a `COPIAS_DIR` (fuera de `public/`),
comprime y borra lo que pase de `COPIAS_DIAS`. Usa `mysqldump` si está disponible y, si
no, vuelca con PHP puro. Comprueba la vía lenta antes de necesitarla: `php
cron/copia_seguridad.php --php`.

---

## 4-ter. Pruebas

```
php pruebas/preparar_base.php     # crea/rehace la base de pruebas (una vez)
php pruebas/negocio.php           # …y cualquier otra suite
```

**Las pruebas nunca tocan la base de trabajo.** Borran filas para partir de un estado
conocido, así que corren contra `DB_NAME_PRUEBAS`, que `preparar_base.php` reconstruye
desde migraciones y fixtures sintéticos sin leer la base real. El arranque común
(`pruebas/_arranque.php`) se niega a ejecutarse si `APP_ENV=production` o si el nombre de
la base de pruebas coincide con el de la de trabajo.

La prueba HTTP también exige `APP_ENV=test` y la cabecera testigo del servidor;
aborta antes de operar si el servidor no acredita el entorno de pruebas.

| Suite | Qué cubre |
|---|---|
| `negocio` | Ventas, stock, membresías |
| `facturacion` | Numeración, desglose de IVA, anulación sin borrado |
| `renovaciones` | Renovación automática: a quién sí, a quién no, y que no cobre dos veces |
| `multisede` | Que una sede no vea ni toque los datos de otra |
| `multiempresa` | Ataques cruzados por URL, POST, IDs, empresa, sede, ventas, cuotas y SEPA |
| `personal` | Altas, roles y permisos entre sedes |
| `sepa` | Mandatos, remesas, fichero XML y devoluciones |
| `iban` | Validación y enmascarado de cuentas |
| `suplementos` | Plus sobre la cuota base |
| `prueba_acceso` | Los 5 días de cortesía |
| `acceso` | Acceso en dos pasos, bloqueos y CSRF (por HTTP) |
| `render` | Prueba de humo: renderiza las 10 pantallas del panel |

---

## 5. Identidad visual

Paleta monocroma. La profundidad se consigue escalonando grises, no con color:

| Uso | Color |
|---|---|
| Cabecera, pie, botones, texto principal | `#111111` |
| Fondo de página | `#e6e6e6` |
| Tarjetas y tablas | `#ffffff` |
| Campos de formulario y cabeceras de tabla | `#ededed` |
| Fondos suaves y barras vacías | `#dcdcdc` |
| Relleno de barras | `#8a8a8a` |
| Cifras secundarias | `#404040` |

**El rojo se conserva a propósito** en tres sitios: errores de validación, membresía
vencida y stock a cero. Son alarmas que en un mostrador deben verse de un vistazo.

No queda ninguna imagen ni referencia del ayuntamiento. La cabecera pinta el logo de la
sede en la que se ha entrado; si esa sede no tiene, el de `APP_LOGO`; y sin ninguno de
los dos, un SVG en línea (una barra con discos) acompañado de `APP_NOMBRE`.

Los logos van siempre sobre una placa blanca, porque la barra es negra y los logos de
cliente suelen ser negros sobre transparente (el de Cleto Reyes lo es).

### La marca de cada gimnasio

Esa paleta monocroma es la del panel. **Las pantallas de acceso son de cada cliente**:
el login de la sede, el de recuperar contraseña y el de fijar una nueva se pintan con
su logo y sus colores, guardados en la tabla `gimnasio` (`logo`, `color_primario`,
`color_texto`).

Se configura en **Sedes → Marca**. Al elegir un logo, el navegador saca de él el color
principal y elige el color de texto que contrasta; los dos selectores quedan a mano
para retocarlos. El cálculo es del lado del cliente a propósito: no depende de la
extensión GD de PHP, que en muchos alojamientos compartidos no está activada.

`app/helpers/Marca.php` deriva del color principal todo lo demás —el degradado del
fondo, el color de los enlaces, el texto sobre el fondo— garantizando el contraste
mínimo AA. Una marca amarilla no acaba con enlaces amarillos ilegibles sobre blanco:
se oscurecen hasta que se leen.

Dos reglas que conviene no romper al tocar esas vistas:

- **El logo va siempre sobre blanco.** Puede ser oscuro sobre transparente —el de
  Cleto Reyes lo es— y desaparecería sobre un fondo de marca oscuro.
- Los colores llegan a atributos `style`, así que `Marca` solo deja pasar `#rrggbb`.

Cleto Reyes Villaviciosa ya está configurada (`logo_cleto_reyes.png`, `#222220`). Sede
Norte sigue sin logo: mientras no lo tenga se muestra la inicial de su nombre sobre su
color.

---

## 6. Integración futura con el control de accesos (IDEMIA + Dorlet)

Pendiente, pero conviene fijar el criterio antes de empezar:

**La plantilla biométrica NO debe guardarse en esta base de datos.** Se queda en el
sistema IDEMIA/Dorlet. Aquí solo se guardaría el identificador del titular en Dorlet
—un número— para poder vincularlo con el socio.

La arquitectura prevista es que el panel hable con Dorlet, no con el lector:

```
Panel PHP (socios y cuotas) ←→ Dorlet (accesos) ←→ IDEMIA (huella)
```

Al contratar o renovar, el panel escribiría en Dorlet la fecha de caducidad del titular
copiando `fecha_fin`. Dorlet cierra el acceso solo al llegar ese día, sin procesos
diarios ni dependencia de que este servidor esté vivo. Que el estado de la membresía se
calcule por fecha es justo lo que hace esto posible.

Antes de implementarlo hay que resolver:
- Producto y versión de Dorlet, y si hay módulo de integración contratado.
- Modelo del terminal IDEMIA.
- **Conectividad**: Dorlet estará en la red local del gimnasio y el panel en un hosting
  externo. Es el mayor obstáculo práctico — requiere VPN, un agente local que sincronice,
  o mover el panel a un servidor dentro del gimnasio.
- Requisitos de RGPD del tratamiento biométrico (ver sección 7).

---

## 7. Estado y trabajo pendiente

### Verificado
- Sintaxis correcta en 117 archivos PHP (`php -l`).
- Las 11 pantallas del panel, incluida Importaciones, renderizan sin errores ni
  avisos (`php pruebas/render.php`).
- **324 comprobaciones automáticas en verde** repartidas en 27 scripts de 4
  suites (ver "Pruebas"). Cubren además stock y rollback, numeración e IVA,
  aislamiento, SEPA, acceso en dos pasos y el motor de importación.

### Pendiente de personalizar
- **Aviso legal (`app/views/rgpd/rgpd.php`)**: tiene marcadores `[por completar]` en los
  datos del responsable del tratamiento. Es un texto con efectos legales y el pie del
  panel enlaza a él desde todas las pantallas. **No publicar sin completar.**
  Si se conecta la huella, hay que revisarlo con quien lleve la protección de datos:
  la biometría es categoría especial (art. 9 RGPD) y normalmente exige evaluación de
  impacto y una alternativa no biométrica (tarjeta, PIN o QR).
- **Precios** de Trimestral y Anual (siguen con valores de ejemplo).
- **Datos de contacto** del pie en `app/views/_footer.php` (dirección, teléfono, redes).
- **Datos bancarios de cada sede** (IBAN, BIC e identificador de acreedor SEPA) en
  Sedes → Domiciliaciones. Sin ellos no se puede crear ninguna remesa.
- **Tipos de IVA**: todo nace al 21 %. Revisa si alguna cuota o producto tributa a otro.

### Seguridad: credenciales expuestas en git
El archivo `.env` estaba **versionado** pese a figurar en `.gitignore` (el ignore no
afecta a archivos ya rastreados). La contraseña de producción está en el historial, en
los commits `464c312`, `38138a4`, `ea5a430`, `6cf1684` y `223dab6`.

Reescribir el historial no sirve: el repositorio ya está clonado por varias personas.
**Hay que rotar esa contraseña en producción.** Después, `git rm --cached .env` para
dejar de rastrearlo — avisando antes al equipo, porque al hacer pull git les borrará su
`.env` local.

### Heredado del portal de cursos
Las rutas, controladores, modelos y vistas de cursos, inscripciones, estudiantes y
profesores ya no forman parte de la aplicación activa. Las migraciones v1 a v6 y el
`schema.sql` conservan nombres del proyecto de origen porque documentan la evolución
histórica de instalaciones existentes; no deben editarse ni borrarse sin comprobar
primero qué migraciones se han aplicado en producción.

`UserModel::eliminarUsuario()` aún contiene limpieza defensiva de tablas antiguas para
instalaciones que no hayan ejecutado la migración v15. El flujo actual de bajas usa
anonimización y no depende de esas tablas.

### Ideas si el proyecto crece
- **Ticket imprimible**: la venta ya tiene número, desglose de IVA y estado; falta la
  plantilla para la impresora de tickets del mostrador.
- **Arqueo de caja** al cerrar el turno: cuadrar lo cobrado en efectivo con lo contado.
- **Lector de códigos de barras** para los productos (es un `<input>` con foco y poco más).
- Entrenadores y clases dirigidas: mejor como tablas nuevas (`entrenador`, `clase`,
  `reserva_clase`) que reaprovechando el esquema de cursos. En un centro de boxeo, las
  clases acaban siendo el producto principal.
- **Cifrar el IBAN** en la base de datos. Hoy está en claro; el volcado de copia de
  seguridad también. Requiere decidir dónde vive la clave y qué pasa si se pierde.
- **Doble factor** para la cuenta `empresa`, que es la que lo ve todo.

### Lo que aún NO cubre (importante saberlo)
- **No emite factura completa** con los datos fiscales del cliente: emite ticket con
  número, desglose de IVA y trazabilidad de anulaciones. Para facturar a empresas hace
  falta añadir los datos del receptor.
- **No está adherido a Verifactu**: no encadena los registros con hash ni los remite a la
  AEAT. Si el gimnasio entra en el ámbito del reglamento, esto hay que abordarlo — y
  conviene consultarlo con el asesor fiscal antes de decidir plazos.
- **No hay control de acceso** (torniquete, QR o huella) ni app para el socio.

---

## 8. Importaciones masivas (Fase 8)

La importación administrativa requiere el permiso `migrations.manage`; no está
disponible para recepción, administradores de sede ni socios. La empresa y la
sede se obtienen de `TenantContext`: las columnas `empresa_id`, `tenant_id` o
`sede_id` del archivo nunca cambian el contexto autorizado.

Variables de entorno:

```dotenv
IMPORT_DIR=/var/lib/gimnasio/imports
IMPORT_MAX_BYTES=10485760
IMPORT_MAX_ROWS=10000
IMPORT_RETENTION_DAYS=7
```

`IMPORT_DIR` debe estar fuera del document root, pertenecer al usuario del
proceso PHP y usar permisos equivalentes a `0750` para directorios y `0640`
para archivos. No debe publicarse ni incorporarse a backups indefinidos. El
cron técnico existente ejecuta `cron/mantenimiento.php`, que elimina staging
caducado sin borrar el historial ni los mapas de IDs.

La confirmación exige un backup reciente y verificado. En producción el backup
debe estar además en el almacenamiento externo configurado; un dump alojado
solo en el servidor no satisface la precondición. El despliegue debe crear el
directorio, aplicar `migracion_v24.sql` mediante el migrador existente y
comprobar una simulación antes de cualquier importación real.

El soporte operativo actual es CSV UTF-8. XLSX no se habilita: no existe una
dependencia mantenible ya instalada y no se incorpora un parser propio.
