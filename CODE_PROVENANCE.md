# Procedencia del código y activos

Documento de due diligence; no concede por sí mismo ninguna licencia.

## Origen conocido

1. El repositorio procede de un proyecto anterior de cursos/inscripciones.
2. Ese código se evolucionó hacia un panel de gestión de gimnasios.
3. Las fases posteriores añadieron, entre otros elementos, multiempresa,
   multisede, autorización, hardening, pruebas, despliegue, importación,
   circuito económico y caja.
4. Una parte significativa de las fases de auditoría, documentación y cambios
   de código fue producida o revisada con asistencia de IA/Codex. No se ha
   medido un porcentaje fiable y no debe inventarse.

El historial antiguo conservado como `.git.bak` mostraba múltiples ramas y
colaboradores. El propietario debe confirmar por escrito que dispone de los
derechos necesarios sobre todas las contribuciones reutilizadas.

## Código propio y dependencias

- El backend activo es PHP propio con PDO y arquitectura MVC sencilla.
- No existe `composer.json` ni se distribuye `vendor/`.
- La interfaz carga Tailwind Browser y algunos iconos de Simple Icons desde
  `cdn.jsdelivr.net`. Sus licencias y condiciones deben registrarse antes de
  una comercialización formal.
- No se ha identificado código DORLET/IDEMIA, SDK biométrico ni software de
  controladoras.

La ausencia de un paquete o licencia visible no demuestra automáticamente la
propiedad intelectual del código. La procedencia debe confirmarse con autores,
contratos y material original.

## Activos de terceros o específicos del cliente

- Nombre, logos y marca de Cleto Reyes son específicos del primer gimnasio.
- Debe conservarse evidencia de autorización para distribuirlos o usarlos en
  producción, marketing y copias de demostración.
- Los datos reales, exportaciones, fotografías y material biométrico no deben
  entrar en Git.
- Los fixtures de `tests/` y `pruebas/` se consideran sintéticos; cualquier
  excepción debe retirarse y notificarse.

## Elementos retirados en Fase 9.5

- `recursos/inscripciones.zip`: copia histórica completa con `.git`, `.env` y
  SQL; sin dependencia runtime demostrada.
- `.git.bak`: metadatos e historia del repositorio anterior.
- `.env.produccion.bak`: configuración histórica con credencial no vacía.

El checkpoint externo previo conserva estos elementos únicamente para
recuperación controlada. No deben volver a una release ni a Git.

## Licencia

No existe una licencia open-source aprobada y no se añade MIT, GPL u otra por
defecto.

**LICENCIA PENDIENTE DE DECISIÓN DEL PROPIETARIO.**

Hasta documentar titularidad, contribuciones y activos, debe tratarse como
software comercial propietario no autorizado para redistribución pública.
