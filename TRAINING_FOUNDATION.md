# Gimnera Training Foundation

Estado: **release candidate local F25A**. No está fusionada ni desplegada.

## Alcance

Training representa entrenamientos diseñados por una persona. No genera
rutinas, no usa IA y no interpreta información clínica. Sus familias iniciales
son `GYM`, `STRENGTH`, `BOXEO`, `MMA`, `BJJ`, `CONDITIONING` y `GENERAL`.

La estructura común es:

```text
biblioteca global/privada
  → plantilla versionada
    → días ordenados
      → bloques ordenados
        → ejercicios o estaciones
          → plan independiente del socio
            → asignación principal e histórico
              → sesiones y resultados opcionales
```

## Decisiones de dominio

- `execution_type` es un conjunto controlado: `REPS`, `TIME`, `ROUNDS`,
  `DISTANCE`, `CIRCUIT` y `TECHNIQUE`.
- Duraciones se persisten en segundos; cargas, como `DECIMAL` en kg; distancia,
  como valor decimal y unidad `M`/`KM`.
- Un circuito es un bloque real con vueltas y descanso entre vueltas. Sus
  estaciones son elementos ordenados con tiempo o repeticiones y transición.
- Los parámetros críticos se guardan en columnas tipadas y validadas, no en un
  JSON opaco. Los textos se reservan para instrucciones y notas.
- Una plantilla se clona como snapshot. Sus cambios posteriores no modifican
  planes existentes.
- Existe un único plan principal activo por socio y empresa. Las asignaciones
  anteriores permanecen como histórico y el plan sustituido pasa a
  `ARCHIVED` sin perder sus sesiones.
- Solo una plantilla `ACTIVE`, completa y con el número de días declarado
  puede clonarse. Solo un plan con días, bloques y ejercicios completos puede
  asignarse, y únicamente el plan principal asignado admite nuevas sesiones.
- La ejecución de una sesión nunca se infiere de un acceso físico.
- Las ediciones de ejercicios, planes, orden y sesiones usan versiones
  optimistas para rechazar pérdidas silenciosas.

## Catálogo y permisos

El catálogo global tiene `id_empresa=NULL` y es de solo lectura para los
tenants. Cada gimnasio puede clonar un ejercicio global y mantener una versión
privada. Dos empresas pueden usar el mismo nombre o slug sin colisionar.

Dirección gestiona Training en su empresa. Admin gestiona el ámbito de su sede.
Recepción no diseña planes. La capacidad del socio queda preparada únicamente
para recursos propios, sin construir un portal nuevo. Superadmin no obtiene un
tenant implícito. El futuro rol `TRAINER` requiere una revisión completa de
RBAC y no forma parte de F25A.

## Medios privados

Las imágenes viven fuera de `public/`, en `TRAINING_MEDIA_DIR`. Solo se admiten
JPEG, PNG y WebP cuyo MIME real, extensión y dimensiones coincidan. El servidor
genera el nombre; el acceso se hace mediante una ruta autorizada con cabeceras
privadas y `nosniff`.

Los ficheros son inmutables mientras exista una referencia en biblioteca o en
un snapshot de plan. El borrado físico únicamente acepta medios sin referencias
en ambas estructuras. El plan sirve su propia metadata autorizada, de modo que
retirar la referencia de biblioteca no rompe el histórico. No existe borrado
de medios expuesto a usuarios en F25A.

Los vídeos son referencias HTTPS autorizadas, no binarios en MariaDB. Cada
medio admite fuente, licencia, atribución y texto alternativo. No se incorpora
contenido descargado de Internet en esta fase.

En una release inmutable, `TRAINING_MEDIA_DIR` debe apuntar al almacenamiento
privado compartido y entrar en la política normal de backup/restore de archivos.

## Privacidad

Training no incorpora diagnósticos, patologías, medicación, lesiones médicas ni
tratamientos. Las pantallas advierten que las notas no son una historia clínica.
Esto no constituye una declaración de cumplimiento legal.

## Operación y pruebas

La migración forward-only es `migracion_v33.sql`. Añade el esquema sin modificar
v1-v32. El schema gate verifica tablas, índices y relaciones contractuales.

La suite F25A cubre validación de todas las modalidades, forma contractual en
MariaDB, snapshot, asignación e idempotencia, sesiones, concurrencia
multi-proceso, catálogo global/privado, IDOR multiempresa/multisede, roles,
medios hostiles, vistas, histórico y carga sintética sin N+1 en planes grandes.
Las pruebas destructivas solo pueden ejecutarse con `APP_ENV=test` y una base
aislada.
