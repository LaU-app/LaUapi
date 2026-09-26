<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\LikeController;
use App\Http\Controllers\Api\MusicSearchController;
use App\Http\Controllers\Api\UniversidadController;
use App\Http\Controllers\Api\FollowerController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\AppUpdateController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Rutas públicas (no requieren autenticación)
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/social-login', [AuthController::class, 'socialLogin']);
Route::post('/auth/check-username', [AuthController::class, 'checkUsername']);
Route::post('/auth/check-email', [AuthController::class, 'checkEmail']);

// Universidades y Carreras (públicas para el formulario de registro)
Route::get('/universidades', [UniversidadController::class, 'index']);
Route::get('/universidades/{universidadId}/carreras', [UniversidadController::class, 'getCarreras']);
Route::get('/carreras', [UniversidadController::class, 'getAllCarreras']);

// Posts públicos (solo lectura)
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{post}', [PostController::class, 'show']);
Route::post('/posts/filter', [PostController::class, 'filtropost']);
Route::get('/posts/user/{userId}', [PostController::class, 'userPosts']);

// Búsqueda de música iTunes (público)
Route::get('/music/search', [MusicSearchController::class, 'search']);
Route::get('/music/track', [MusicSearchController::class, 'getTrack']);
Route::get('/music/genre', [MusicSearchController::class, 'searchByGenre']);
Route::get('/music/popular', [MusicSearchController::class, 'getPopular']);

Route::get('/check-update', [AppUpdateController::class, 'checkUpdate']);

// Rutas protegidas (requieren autenticación con token Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/realtime/auth', \App\Http\Controllers\Api\RealtimeAuthController::class);
    Route::get('/tasks-stats', [\App\Http\Controllers\Api\TaskController::class, 'stats']);
    Route::apiResource('tasks', \App\Http\Controllers\Api\TaskController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

    Route::prefix('pomodoro')->controller(\App\Http\Controllers\Api\StudyController::class)->group(function () {
        Route::get('preferences', 'preferences');
        Route::put('preferences', 'updatePreferences');
        Route::post('start', 'start');
        Route::post('complete', 'finish');
        Route::post('cancel', 'cancel');
        Route::get('active', 'active');
        Route::get('sessions', 'history');
        Route::get('stats', 'stats');
        Route::get('leaderboard', 'leaderboard');
    });

    // Autenticación
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/auth/user', [AuthController::class, 'me']); // Alias para compatibilidad
    Route::put('/user/edit', [AuthController::class, 'edit']); // Estadísticas de seguimiento

    //followers count
    Route::get('/auth/followers/{id}', [FollowerController::class, 'followers']); // Alias para compatibilidad
    Route::get('/auth/following/{id}', [FollowerController::class, 'following']);

    // Posts protegidos (crear y eliminar requieren autenticación)
    Route::post('/posts', [PostController::class, 'store']);
    Route::delete('/posts/{post}', [PostController::class, 'destroy']);

    // Comentarios
    Route::get('/posts/{post}/comments', [CommentController::class, 'index']); // Listar comentarios de un post
    Route::post('/posts/{post}/comments', [CommentController::class, 'store']); // Crear comentario
    Route::get('/comments/{comentario}', [CommentController::class, 'show']); // Ver comentario específico
    Route::put('/comments/{comentario}', [CommentController::class, 'update']); // Editar comentario
    Route::delete('/comments/{comentario}', [CommentController::class, 'destroy']); // Eliminar comentario
    Route::get('/comments/{comentario}/replies', [CommentController::class, 'replies']); // Ver respuestas

    // Likes
    Route::post('/posts/{post}/like', [LikeController::class, 'toggle']); // Dar/quitar like (toggle)
    Route::get('/posts/{post}/likes', [LikeController::class, 'index']); // Listar usuarios que dieron like
    Route::get('/posts/{post}/like/check', [LikeController::class, 'check']); // Verificar si el usuario dio like

    // Usuarios
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/search', [UserController::class, 'search']);
    Route::get('/users/search-friends', [UserController::class, 'searchFriends']);
    Route::get('/users/{userIdentifier}/posts', [UserController::class, 'posts']); // Posts de un usuario específico
    Route::get('/users/{userIdentifier}/stats', [UserController::class, 'stats']); // Estadísticas completas del usuario
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::get('/user/{usuario_username}', [UserController::class, 'foreignUser']); // Alias para compatibilidad

    // Sistema de Seguimiento (Follow/Unfollow)
    Route::post('/users/{id}/follow', [FollowerController::class, 'follow']); // Seguir usuario
    Route::post('/users/{id}/unfollow', [FollowerController::class, 'unfollow']); // Dejar de seguir
    Route::post('/users/{id}/follow/toggle', [FollowerController::class, 'toggle']); // Toggle follow/unfollow
    Route::get('/users/{id}/follow/check', [FollowerController::class, 'check']); // Verificar si sigue
    Route::get('/users/{id}/followers', [FollowerController::class, 'followers']); // Lista de seguidores
    Route::get('/users/{id}/following', [FollowerController::class, 'following']); // Lista de seguidos
    Route::get('/users/{id}/follow/stats', [FollowerController::class, 'stats']); // Estadísticas de seguimiento

    // Notificaciones (likes, comentarios, seguidores)
    Route::get('/notifications', [NotificationController::class, 'index']); // Todas las notificaciones
    Route::get('/notifications/unread', [NotificationController::class, 'unread']); // Solo no leídas
    Route::get('/notifications/count', [NotificationController::class, 'count']); // Contador de no leídas
    Route::get('/notifications/type', [NotificationController::class, 'byType']); // Filtrar por tipo
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']); // Marcar una como leída
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']); // Marcar todas como leídas
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']); // Eliminar una notificación
    Route::delete('/notifications/clear-read', [NotificationController::class, 'clearRead']); // Limpiar leídas

    // Push Notifications - Registro de tokens de dispositivo
    Route::post('/notifications/register-device', [NotificationController::class, 'registerDevice']); // Registrar token FCM
    Route::post('/notifications/unregister-device', [NotificationController::class, 'unregisterDevice']); // Desregistrar token FCM

    // Reportes de usuarios
    Route::post('/reportes', [ReporteController::class, 'store']);

    // ========== CHAT ROUTES ==========
    Route::get('/chat/contacts', [App\Http\Controllers\Api\ChatApiController::class, 'getContactsJSON']);
    Route::get('/chat/favorites', [App\Http\Controllers\Api\ChatApiController::class, 'getFavoritesJSON']);
    Route::post('/chat/messages', [App\Http\Controllers\Api\ChatApiController::class, 'fetchMessagesJSON']);
    Route::post('/chat/send', [App\Http\Controllers\Api\ChatApiController::class, 'sendMessageJSON']);
    Route::post('/chat/typing', [App\Http\Controllers\Api\ChatApiController::class, 'typing']);
    Route::post('/chat/auth', [App\Http\Controllers\Api\ChatApiController::class, 'pusherAuth']);
    Route::post('/chat/favorite', [App\Http\Controllers\Api\ChatApiController::class, 'toggleFavorite']);
    Route::post('/chat/checkFavorite', [App\Http\Controllers\Api\ChatApiController::class, 'checkFavorite']);
    Route::post('/chat/shared', [App\Http\Controllers\Api\ChatApiController::class, 'getSharedPhotos']);
    Route::post('/chat/makeSeen', [App\Http\Controllers\Api\ChatApiController::class, 'makeSeen']);
    Route::get('/chat/unread-count', [App\Http\Controllers\Api\ChatApiController::class, 'unreadCount']);

    // Banner de la app
    Route::get('/banners/active', [BannerController::class, 'getActive']);

    Route::post('/banners/{banner}/view', [BannerController::class, 'markViewed']);
});

// Ruta de prueba de la API
Route::get('/test', function () {
    return response()->json([
        'success' => true,
        'message' => 'API funcionando correctamente',
        'timestamp' => now()
    ]);
});
