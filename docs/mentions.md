# Menciones

Documentación del flujo de menciones, persistencia y notificaciones de `SivarSocial`.

## Regla de negocio

Una mención usa el formato `@username` y solo es válida cuando el usuario mencionado y el autor tienen una relación de seguimiento mutua.

- El autor no puede mencionarse a sí mismo.
- Los usuarios sugeridos por la API ya están filtrados como amistades mutuas.
- El backend vuelve a validar la relación al procesar el texto; el frontend no es una fuente de confianza.
- Los nombres de usuario se normalizan a minúsculas y no se procesan duplicados.

## Persistencia

### Tabla `mentions`

Migraciones relacionadas:

- `database/migrations/2026_09_22_000000_create_mentions_table.php`
- `database/migrations/2026_09_22_000001_add_foreign_keys_to_mentions_table.php`

La tabla almacena una mención por contenido y usuario mencionado:

| Campo | Descripción |
|---|---|
| `id` | Identificador de la mención. |
| `mentioned_user_id` | Usuario que recibe la mención. |
| `author_id` | Usuario que escribió el contenido. |
| `mentionable_type` | Tipo lógico del contenido: `post` o `comment`. |
| `mentionable_id` | Identificador del post o comentario. |
| `created_at` | Fecha de creación. |

El índice compuesto sobre `mentionable_type` y `mentionable_id` permite consultar las menciones de un contenido. La restricción única sobre esos campos y `mentioned_user_id` evita duplicados.

El modelo correspondiente es `app/Models/Mention.php`, con relaciones `mentionedUser`, `author` y `mentionable`.

### Tabla `notifications`

La notificación se guarda usando:

| Campo | Valor para una mención |
|---|---|
| `user_id` | ID del usuario mencionado. |
| `from_user_id` | ID del autor del contenido. |
| `type` | `mention`. |
| `post_id` | ID del post cuando la mención pertenece a un post; `null` para comentarios. |
| `data` | JSON con el tipo e ID del contenido y datos básicos del autor. |
| `read_at` | `null` hasta que el usuario la marque como leída. |

`App\\Models\\Notification::TYPE_MENTION` es la constante oficial del tipo.

## Procesamiento

### `MentionService`

Ruta: `app/Services/MentionService.php`

Responsabilidades:

1. `extractUsernames(string $text)` extrae tokens con la expresión `@([a-zA-Z0-9_]+)`.
2. Normaliza usernames a minúsculas y elimina duplicados.
3. `validateMutualFriends(int $authorId, array $usernames)` conserva únicamente usuarios con seguimiento en ambas direcciones.
4. `saveMentions(...)` inserta las menciones con `insertOrIgnore`, respetando la restricción única.

### `ProcessMentionsJob`

Ruta: `app/Jobs/ProcessMentionsJob.php`

El job recibe:

- `authorId`: autor del contenido.
- `text`: título, descripción, texto del post o comentario.
- `mentionableType`: `post` o `comment`.
- `mentionableId`: ID del contenido.

El job extrae y valida los usernames, guarda las filas en `mentions` y llama a `NotificationService::createMentionNotification` por cada usuario válido.

Actualmente se ejecuta con `dispatchSync` desde los controladores de posts y comentarios. Esta decisión garantiza que la fila de `notifications` exista durante la misma petición y no dependa de que haya un worker de Laravel ejecutándose.

## Puntos de entrada

### Crear un post

`POST /api/posts`

Requiere autenticación Sanctum.

El texto de menciones se obtiene de la combinación de:

- `titulo`
- `descripcion`
- `texto`

El controlador procesa la mención después de guardar el post. El tipo enviado al job es `post`.

Campos de texto relevantes:

| Campo | Tipo | Requerido |
|---|---|---:|
| `titulo` | string | no |
| `descripcion` | string, máximo 500 | no |
| `texto` | string, máximo 5000 | no |
| `tipo` | `imagen\|musica\|texto\|archivo` | sí |

### Crear un comentario

`POST /api/posts/{post}/comments`

Requiere autenticación Sanctum.

Body relevante:

| Campo | Tipo | Requerido |
|---|---|---:|
| `comentario` | string, máximo 500 | sí |
| `parent_id` | integer existente en `comentarios` | no |
| `gif_url` | URL, máximo 500 | no |

El controlador procesa el contenido del comentario con el tipo `comment`. Las respuestas también generan menciones usando el mismo flujo.

### Buscar usuarios mencionables

`GET /api/users/search-friends?query={texto}`

Requiere autenticación Sanctum.

La respuesta tiene la forma:

```json
{
  "success": true,
  "data": [
    {
      "id": 12,
      "name": "Nombre de ejemplo",
      "username": "usuario_ejemplo",
      "imagen": "avatar.webp",
      "imagen_url": "https://api.ejemplo.test/perfiles/avatar.webp",
      "insignia": null
    }
  ]
}
```

Con `query` vacío se devuelve una lista vacía. La búsqueda compara `username` y `name`, excluye al usuario autenticado, limita a 10 resultados y exige seguimiento mutuo.

### Consultar notificaciones

`GET /api/notifications`

Requiere autenticación Sanctum. Devuelve las notificaciones del usuario autenticado paginadas de 20 en 20.

Una notificación de mención incluye, además de los campos de la tabla:

```json
{
  "type": "mention",
  "post_id": 42,
  "data": {
    "mentionable_type": "post",
    "mentionable_id": "42",
    "author_username": "autor_ejemplo",
    "author_name": "Autor de ejemplo"
  },
  "message": "te mencionó",
  "time_ago": "hace unos segundos"
}
```

Para menciones en comentarios, `post_id` queda en `null` y `data.mentionable_type` es `comment`.

## Notificaciones push

`NotificationService::createMentionNotification` crea primero la fila local y luego llama a `NotificationController::sendPushNotification` con:

- `type`: `mention`.
- `mentionable_type`.
- `mentionable_id`.
- `user_id`: autor de la mención.
- `notification_id`: ID de la notificación local.

Si el usuario no tiene dispositivos activos o Firebase no está configurado, la notificación local se conserva; el fallo del push no elimina el registro de la base de datos.

## Archivos principales

- `app/Services/MentionService.php`
- `app/Jobs/ProcessMentionsJob.php`
- `app/Services/NotificationService.php`
- `app/Models/Mention.php`
- `app/Models/Notification.php`
- `app/Http/Controllers/Api/PostController.php`
- `app/Http/Controllers/Api/CommentController.php`
- `app/Http/Controllers/Api/UserController.php`
- `app/Http/Controllers/Api/NotificationController.php`
- `routes/api.php`
- `tests/Feature/MentionsTest.php`

## Verificación

La prueba `tests/Feature/MentionsTest.php` cubre:

- Extracción y normalización de usernames.
- Filtrado por seguimiento mutuo.
- Autenticación y respuesta del endpoint de sugerencias.
- Idempotencia de la tabla `mentions`.
- Creación de una notificación cuando se procesa una mención válida.

Comando:

```bash
php artisan test --filter=MentionsTest
```
