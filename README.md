# LaUapi

API REST de la plataforma académica/social universitaria. Este repositorio se
utiliza principalmente como **backend de API** para clientes móviles y
asíncronos (autenticados con Laravel Sanctum), e incluye además un panel web de
administración **Super Usuario (SU)**.

La documentación está enfocada en el consumo de la API y en el panel `su`.

## Instalación y Ejecución

### Paso 1: Clonar el Proyecto

```bash
git clone <Link del repositorio>
cd LaUapi
```

### Paso 2: Instalar Dependencias

```bash
composer install
npm install
```

### Paso 3: Configurar Entorno

```bash
copy .env.example .env
php artisan key:generate
```

### Paso 4: Configurar Base de Datos

1. Crear la base de datos en MySQL
2. Configurar credenciales en `.env`
3. Ejecutar migraciones:

```bash
php artisan migrate
```

### Paso 5: Iniciar el Proyecto

```bash
composer run dev
```

## Uso como API

La API se sirve bajo el prefijo `/api` y se define en `routes/api.php`. Las
rutas públicas no requieren autenticación; el resto están protegidas con el
middleware `auth:sanctum`.

### Autenticación

1. Registra o inicia sesión para obtener un token de acceso:

```bash
curl -X POST https://<host>/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "usuario@dominio.edu", "password": "secreto"}'
```

2. Envía el token en la cabecera `Authorization` para las rutas protegidas:

```bash
curl https://<host>/api/auth/me \
  -H "Authorization: Bearer <token>" \
  -H "Accept: application/json"
```

3. Prueba de disponibilidad de la API:

```bash
curl https://<host>/api/test
```

### Endpoints principales

Rutas públicas:

- `POST /api/auth/login`, `POST /api/auth/register`, `POST /api/auth/social-login`
- `POST /api/auth/check-username`, `POST /api/auth/check-email`
- `GET /api/universidades`, `GET /api/universidades/{id}/carreras`, `GET /api/carreras`
- `GET /api/posts`, `GET /api/posts/{post}`, `POST /api/posts/filter`, `GET /api/posts/user/{userId}`
- `GET /api/music/search`, `GET /api/music/track`, `GET /api/music/genre`, `GET /api/music/popular`
- `GET /api/check-update`

Rutas protegidas (`auth:sanctum`):

- Autenticación de sesión: `POST /api/auth/logout`, `GET /api/auth/me`, `PUT /api/user/edit`
- Posts: `POST /api/posts`, `DELETE /api/posts/{post}`
- Comentarios: `GET|POST /api/posts/{post}/comments`, `PUT|DELETE /api/comments/{id}`, `GET /api/comments/{id}/replies`
- Likes: `POST /api/posts/{post}/like`, `GET /api/posts/{post}/likes`, `GET /api/posts/{post}/like/check`
- Usuarios: `GET /api/users`, `GET /api/users/search`, `GET /api/users/{user}`, `GET /api/users/{id}/stats`
- Seguimiento: `POST /api/users/{id}/follow`, `POST /api/users/{id}/unfollow`, `GET /api/users/{id}/followers`, `GET /api/users/{id}/following`
- Notificaciones: `GET /api/notifications`, `GET /api/notifications/count`, `POST /api/notifications/read-all`
- Push (FCM): `POST /api/notifications/register-device`, `POST /api/notifications/unregister-device`
- Reportes: `POST /api/reportes`
- Chat: `GET /api/chat/contacts`, `POST /api/chat/messages`, `POST /api/chat/send`, `GET /api/chat/unread-count`
- Tareas: `apiResource /api/tasks`, `GET /api/tasks-stats`
- Pomodoro: `POST /api/pomodoro/start`, `POST /api/pomodoro/complete`, `GET /api/pomodoro/leaderboard`
- Banners: `GET /api/banners/active`, `POST /api/banners/{banner}/view`

Consulta `routes/api.php` para la lista completa de endpoints.

## Panel de Administración (Super Usuario · SU)

El panel `su` es la interfaz web de administración definida en `routes/web.php`
bajo el prefijo `us/su/lau` y protegida por el middleware `auth:super`. Sus
vistas viven en `resources/views/su/*` y usan el layout
`resources/views/layouts/app-su.blade.php`.

- Login: `GET /us/su/lau/login`, `POST /us/su/lau/session`
- Dashboard: `GET /us/su/lau/dashboard`
- Universidades: `GET|POST /us/su/lau/universidades`, `PUT /us/su/lau/universidades/{id}`
- Carreras: `GET /us/su/lau/carreras`, `POST /us/su/lau/carreras/crear`, `POST /us/su/lau/carreras/vincular`
- Usuarios: `GET /us/su/lau/usuarios`, `GET /us/su/lau/info/{username}`, `GET /us/su/lau/reportes`, `DELETE /us/su/lau/usuarios/{id}`
- Insignias: `GET /us/su/lau/insignias`, `POST /us/su/lau/insignias/create`, `POST /us/su/lau/users/insignia`
- Anuncios (Banners): `GET|POST /us/su/lau/ads/create`, `PUT /us/su/lau/ads/update/{id}`, `DELETE /us/su/lau/ads/{id}`
- Actualizaciones de la app: `GET|POST /us/su/lau/updates`, `PATCH /us/su/lau/updates/{id}/activate`, `DELETE /us/su/lau/updates/{id}`

## Estructura del Proyecto

```
LaUapi/
├── app/
│   └── Http/Controllers/
│       ├── Api/                          # Controladores de la API REST
│       │   ├── AuthController.php         # Login, registro y sesión (Sanctum)
│       │   ├── PostController.php         # Publicaciones (CRUD y filtros)
│       │   ├── CommentController.php      # Comentarios y respuestas
│       │   ├── LikeController.php         # Likes (toggle/listado)
│       │   ├── UserController.php         # Usuarios, búsqueda y estadísticas
│       │   ├── FollowerController.php     # Seguir/dejar de seguir
│       │   ├── NotificationController.php # Notificaciones y tokens FCM
│       │   ├── ChatApiController.php      # Mensajería (Pusher)
│       │   ├── MusicSearchController.php  # Búsqueda de música (iTunes)
│       │   ├── UniversidadController.php  # Universidades y carreras
│       │   ├── TaskController.php         # Tareas (to-do)
│       │   ├── PomodoroController.php     # Sesiones Pomodoro y podio
│       │   ├── BannerController.php       # Banners de la app
│       │   ├── ReporteController.php      # Reportes de usuarios
│       │   └── AppUpdateController.php    # Verificación de actualizaciones
│       └── SUController.php               # Lógica del panel Super Usuario (SU)
├── resources/
│   └── views/
│       ├── su/                           # Vistas del panel SU
│       │   ├── login.blade.php           # Login del panel SU
│       │   ├── dashboard.blade.php       # Dashboard SU
│       │   ├── universidad.blade.php     # Gestión de universidades
│       │   ├── carreras.blade.php        # Gestión de carreras
│       │   ├── usuarios.blade.php        # Gestión de usuarios
│       │   ├── perfil.blade.php          # Perfil de usuario (SU)
│       │   ├── reportes.blade.php        # Reportes de usuarios
│       │   ├── insignia.blade.php        # Gestión de insignias
│       │   ├── anuncio.blade.php         # Gestión de anuncios (banners)
│       │   └── updates.blade.php         # Gestión de actualizaciones de la app
│       └── layouts/
│           └── app-su.blade.php          # Layout base del panel SU
└── routes/
    ├── api.php                           # Rutas de la API (Sanctum)
    └── web.php                           # Rutas web y panel SU (auth:super)
```

## Comandos Útiles

### Al cambiar de rama
```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
php artisan clear-compiled
php artisan storage:link
```

## Tecnologías

- **Backend**: Laravel 12 + PHP 8.2
- **API**: Laravel Sanctum (autenticación por tokens)
- **Base de Datos**: MySQL
- **APIs externas**: iTunes Search API
- **Mensajería en tiempo real**: Pusher
- **Notificaciones push**: Firebase Cloud Messaging (FCM)
- **Imágenes**: Intervention Image
- **Build**: Vite
