# Saneamiento de repositorio — Fase 9.5

Fecha de auditoría: 20/08/2026.

Este documento no contiene valores de contraseñas, tokens ni credenciales.

## Hallazgos

| Tipo | Ubicación | Estado conocido | Acción |
|---|---|---|---|
| Credencial de base de datos | `.env` local | Desarrollo; no versionada | Conservar local y no compartir |
| Credencial de base de datos | `.env.produccion.bak` | Vigencia desconocida | Retirar y rotar obligatoriamente |
| Credencial de base de datos | `.git.bak`, historia antigua | Aparece no vacía y alcanzable desde referencias remotas antiguas | Retirar copia y rotar |
| Credencial de base de datos | `recursos/inscripciones.zip` | `.env` no vacío; ZIP ya incluido en `origin/main` | Retirar y rotar |
| Credenciales de demostración | tests y fixtures | Sintéticas/no productivas según el proyecto | Excluir de release |
| Contraseña inicial | `instalar.php` | Ya no existe literal; se exige `INSTALL_ADMIN_PASSWORD` por proceso | Excluir instalador de release |
| SMTP/API/clave privada | árbol activo revisado | No se encontró valor real confirmado | Mantener variables vacías en `.env.example` |

Si no puede demostrarse que una credencial histórica está revocada, su estado
es **PENDIENTE DE ROTACIÓN**. Ocultar el archivo del historial no sustituye la
rotación.

## `recursos/inscripciones.zip`

- 1.245 entradas y 21.861.091 bytes sin comprimir.
- 1.151 entradas pertenecen a `.git`.
- Contiene un `.env` con `DB_PASS` no vacío y seis SQL de esquema/migración.
- Los SQL revisados no contienen sentencias `INSERT` ni coincidencias de IBAN.
- La exploración de texto encontró patrones tipo email/DNI en código o
  fixtures; no se muestran valores y no se afirma que sean datos reales.
- No existe referencia runtime; solo era descrito en documentación como
  material de partida.

Clasificación: **HISTÓRICO / CÓDIGO HEREDADO / CÓDIGO MUERTO PARA RUNTIME**.

## Historia Git

- El repositorio actual parte de un único commit anterior a la consolidación.
- `origin/main` apunta a ese commit y contiene el ZIP histórico.
- El remoto usa HTTPS y no tiene una credencial embebida en su URL.
- No se ha comprobado si el repositorio remoto es público o privado.
- La Fase 9.5 elimina el ZIP del estado actual, pero no reescribe el commit
  remoto antiguo.

Cualquier reescritura exige confirmar propietarios, clones, ramas, pull
requests y ventanas de coordinación. La decisión queda pendiente; la rotación
de secretos es prioritaria.

## ACL de `.git`

La auditoría inicial encontró seis reglas `DENY` explícitas para tres SID no
resolubles, propagadas a hijos. La verificación final, ejecutada en el contexto
de `omar2` y repetida desde el entorno automatizado, confirmó cero reglas
`DENY`, propietario `omar2` e herencia habilitada. Además, `git add`, `commit` y
la creación de una etiqueta anotada finalizaron correctamente. No se atribuye
la retirada de las ACE a un comando concreto porque el recuento facilitado por
el usuario ya era cero antes de su llamada a `Set-Acl`.

No se debe usar `Everyone:FullControl`, eliminar `.git` ni reinicializar el
repositorio para resolverlo.

## Rotación mínima

1. Identificar el servidor o servicio al que pertenecía cada `DB_HOST` sin
   copiar su valor a tickets o chats.
2. Revocar o cambiar usuario/contraseña desde el servicio correspondiente.
3. Generar secretos únicos para desarrollo, test y producción.
4. Guardarlos en `.env`/gestor de secretos fuera de Git.
5. Reiniciar conexiones y comprobar que la credencial antigua falla.
6. Revisar logs sin registrar los valores.
7. Rotar también cualquier token encontrado durante la revisión privada del
   remoto o del checkpoint.

## Elementos que se conservan

- `.env` local, porque la aplicación lo necesita y está ignorado.
- `copias/`, como almacenamiento operativo local ignorado; nunca entra en la
  release.
- Migraciones SQL de `app/config/`, que forman parte del código.
- Tests y fixtures sintéticos, que se versionan pero se excluyen del ZIP
  productivo.
- `instalar.php`, como herramienta CLI histórica del repositorio; se excluye
  de la release productiva.
