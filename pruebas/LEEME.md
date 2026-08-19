# Pruebas automatizadas

Las pruebas usan exclusivamente `DB_NAME_PRUEBAS` con `APP_ENV=test`. El
arranque aborta si el nombre coincide con `DB_NAME`; `preparar_base.php`
reconstruye la base con esquema, migraciones y datos sintéticos, sin copiar ni
leer datos de la base de trabajo.

```powershell
C:\xampp\php\php.exe pruebas\preparar_base.php
C:\xampp\php\php.exe tests\run.php
```

La organización mantenible está en `tests/Unit`, `tests/Integration`,
`tests/Security` y `tests/Functional`. El runner también incorpora los scripts
históricos válidos de `pruebas/`.

## Carga funcional del piloto

Para medir listados y búsquedas con volumen sintético (siempre en la base de
pruebas), reconstruye primero el fixture y después ejecuta la carga y el
benchmark:

```powershell
C:\xampp\php\php.exe pruebas\preparar_base.php
C:\xampp\php\php.exe pruebas\carga_piloto.php
C:\xampp\php\php.exe pruebas\rendimiento_piloto.php
```

La carga crea 5 empresas, 14 sedes, 5.000 socios, 7.500 membresías, 210
productos, 6.000 ventas y 12.000 eventos de auditoría. Es reproducible: para
repetirla hay que reconstruir la base con `preparar_base.php`; el cargador se
niega a duplicar sus datos.

## Rendimiento Fase 7

La ampliación mantiene los datos en la base de pruebas y lleva la empresa
inicial a 5.000 socios para medir paginación real, búsquedas y HTML:

```powershell
C:\xampp\php\php.exe pruebas\preparar_base.php
C:\xampp\php\php.exe pruebas\carga_piloto.php
C:\xampp\php\php.exe pruebas\carga_fase7.php
C:\xampp\php\php.exe pruebas\rendimiento_fase7.php
```

La ruta histórica sin paginar puede medirse una sola vez con
`rendimiento_fase7.php --include-legacy`; se omite por defecto porque con 5.000
filas su ejecución es intencionadamente muy lenta.

Con el servidor local arrancado en `APP_ENV=test`, la medición HTTP se ejecuta
en otra terminal:

```powershell
$env:APP_ENV='test'
$env:TEST_BASE_URL='http://127.0.0.1:8094/index.php'
C:\xampp\php\php.exe pruebas\rendimiento_http_fase7.php
```

## Prueba HTTP

El servidor debe acreditar `APP_ENV=test`; en otro caso `acceso.php` aborta
antes de enviar accesos o limpiar intentos. En Windows/XAMPP:

```powershell
New-Item -ItemType Directory -Force pruebas\sesiones_tmp
$env:APP_ENV='test'
$env:APP_URL='http://127.0.0.1:8091'
C:\xampp\php\php.exe -S 127.0.0.1:8091 -t public
```

En otra terminal:

```powershell
$env:APP_ENV='test'
$env:TEST_BASE_URL='http://127.0.0.1:8091/index.php'
C:\xampp\php\php.exe pruebas\acceso.php
```

## Sonda del arnés

Este comando debe terminar con código 1. Demuestra que una aserción rota no
produce un falso positivo:

```powershell
C:\xampp\php\php.exe tests\Unit\ValidationTest.php --force-failure
```

## Importaciones masivas — Fase 8

Los fixtures son exclusivamente sintéticos y pueden regenerarse de forma
determinista. El importador solo se prueba contra `DB_NAME_PRUEBAS`:

```powershell
C:\xampp\php\php.exe pruebas\generar_fixtures_importacion.php
C:\xampp\php\php.exe pruebas\preparar_base.php
C:\xampp\php\php.exe tests\run.php
C:\xampp\php\php.exe pruebas\rendimiento_importacion_fase8.php
```

`rendimiento_importacion_fase8.php` aborta si no está en `APP_ENV=test`, crea
su propia empresa y sede sintéticas, mide 5.000 socios y elimina ese tenant al
terminar. Los archivos de entrada permanecen en `pruebas/fixtures/importaciones`;
los temporales del motor se guardan fuera de `public/` y se purgan mediante
`cron/mantenimiento.php`.
