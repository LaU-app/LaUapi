<?php

use App\Models\Comentario;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SUController;
use App\Http\Controllers\LikeController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\ImagenController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\ComentarioController;
use App\Http\Controllers\FollowerController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SpotifyApiController;
use App\Http\Controllers\iTunesApiController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\RecoverController;
use App\Http\Controllers\ColaboradoresController;

// ============================================================================
// RUTA PRINCIPAL - PÁGINA DE INICIO
// ============================================================================

/**
 * Página principal de la aplicación
 * Muestra el feed principal con posts de usuarios
 */
Route::get('/', function () {
    return redirect()->route('su.us.laulogin');
})->name('home');// ============================================================================
// SISTEMA DE AUTENTICACIÓN
// ============================================================================

/**
 * REGISTRO DE USUARIOS
 * Formulario de registro y procesamiento de nuevos usuarios
 */
// Route::get('/register', [RegisterController::class, 'show'])->name('register');
// Route::post('/register', [RegisterController::class, 'store']);
// Route::post('/register/validate-step1', [RegisterController::class, 'validateStep1']);

/**
 * INICIO DE SESIÓN
 * Formulario de login y autenticación de usuarios
 */
// Route::get('/login', [LoginController::class, 'index'])->name('login');
// Route::post('/login', [LoginController::class, 'store']);

/**
 * CIERRE DE SESIÓN
 * Termina la sesión del usuario autenticado
 */
Route::post('/logout', [LogoutController::class, 'store'])->name('logout');

// ============================================================================
// SISTEMA DE RECUPERACIÓN DE CONTRASEÑA
// ============================================================================

/**
 * SOLICITUD DE RECUPERACIÓN
 * Formulario para solicitar código de recuperación por email
 */
// Route::get('/recuperar', [RecoverController::class, 'index'])->name('recuperar');
// Route::post('/recuperar', [RecoverController::class, 'enviarCodigo'])->name('recuperar.enviar');

/**
 * VERIFICACIÓN DEL CÓDIGO
 * Formulario para ingresar el código de verificación enviado por email
 */
// Route::get('/code-verific', [RecoverController::class, 'index2'])->name('code.verific');
// Route::post('/code-verific', [RecoverController::class, 'validarCodigo'])->name('code.verification');

/**
 * RESTABLECIMIENTO DE CONTRASEÑA
 * Formulario para establecer nueva contraseña tras verificación exitosa
 */
// Route::get('/restablecer', [RecoverController::class, 'index3'])->name('restablecer');
// Route::post('/restablecer', [RecoverController::class, 'restablecer'])->name('restablecer.verification');

// ============================================================================
// GESTIÓN DE PERFILES Y USUARIOS
// ============================================================================

/**
 * EDICIÓN DE PERFIL
 * Permite al usuario modificar su información personal y configuración
 */
Route::get('/editar-perfil', [PerfilController::class, 'index'])->name('perfil.index')->middleware('auth');
Route::post('/editar-perfil', [PerfilController::class, 'store'])->name('perfil.store')->middleware('auth');

/**
 * ENLACES SOCIALES
 * Gestión de enlaces sociales del usuario
 */
Route::post('/social-links', [App\Http\Controllers\SocialLinksController::class, 'store'])->name('social-links.store')->middleware('auth');
Route::delete('/social-links/{id}', [App\Http\Controllers\SocialLinksController::class, 'destroy'])->name('social-links.destroy')->middleware('auth');
Route::patch('/social-links/{id}/move-up', [App\Http\Controllers\SocialLinksController::class, 'moveUp'])->name('social-links.move-up')->middleware('auth');
Route::patch('/social-links/{id}/move-down', [App\Http\Controllers\SocialLinksController::class, 'moveDown'])->name('social-links.move-down')->middleware('auth');

/**
 * BÚSQUEDA DE USUARIOS (PÚBLICA)
 * Sistema de búsqueda para encontrar otros usuarios de la plataforma
 */
Route::get('/buscar-usuarios', [UserController::class, 'buscar'])->name('usuarios.buscar');

/**
 * ELIMINACIÓN DE CUENTA
 * Permite al usuario eliminar permanentemente su cuenta
 */
Route::delete('/user', [UserController::class, 'destroy'])->name('user.destroy');

// ============================================================================
// SECCIÓN DE COLABORADORES
// ============================================================================

/**
 * PÁGINA DE COLABORADORES
 * Muestra la lista de usuarios con badge de colaborador
 */
Route::get('/colaboradores', [ColaboradoresController::class, 'index'])->name('colaboradores.index');

// ============================================================================
// SISTEMA DE POSTS (PUBLICACIONES)
// ============================================================================

/**
 * CREACIÓN DE POSTS
 * Formulario para crear nuevas publicaciones
 */
Route::get('/posts/create', [PostController::class, 'create'])->name('posts.create')->middleware('auth');
Route::post('/posts', [PostController::class, 'store'])->name('posts.store')->middleware('auth');

/**
 * EDICIÓN DE POSTS
 * Formulario para editar publicaciones existentes
 */
Route::get('/posts/{post}/edit', [PostController::class, 'edit'])->name('posts.edit')->middleware('auth');
Route::put('/posts/{post}', [PostController::class, 'update'])->name('posts.update')->middleware('auth');

/**
 * VISUALIZACIÓN Y GESTIÓN DE POSTS
 * Ver post individual y eliminar posts propios
 */
Route::get('/{user:username}/posts/{post}', [PostController::class, 'show'])->name('posts.show');
Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy')->middleware('auth');

/**
 * PERFIL DE USUARIO
 * Muestra todos los posts de un usuario específico
 */
Route::get('/{user:username}', [PostController::class, 'index'])->name('posts.index');

// ============================================================================
// SISTEMA DE LIKES
// ============================================================================

/**
 * GESTIÓN DE LIKES
 * Dar like y quitar like a publicaciones
 */
Route::post('/posts/{post}/likes', [LikeController::class, 'store'])->name('posts.likes.store')->middleware('auth');
Route::delete('/posts/{post}/likes', [LikeController::class, 'destroy'])->name('posts.likes.destroy')->middleware('auth');

/**
 * OBTENER LIKES VIA AJAX
 * Para cargar dinámicamente la lista de usuarios que dieron like
 */
Route::get('/posts/{post}/likes', [PostController::class, 'getLikes'])->name('posts.likes.get');

// ============================================================================
// SISTEMA DE COMENTARIOS
// ============================================================================

/**
 * AGREGAR COMENTARIOS
 * Permite comentar en las publicaciones de otros usuarios
 */
Route::post('/{user:username}/posts/{post}', [ComentarioController::class, 'store'])->name('comentarios.store')->middleware('auth');

// ============================================================================
// GESTIÓN DE IMÁGENES
// ============================================================================

/**
 * SUBIDA DE IMÁGENES
 * Para posts y contenido multimedia
 */
Route::post('/imagenes', [ImagenController::class, 'store'])->name('imagenes.store');

/**
 * IMÁGENES DE PERFIL
 * Subida y actualización de foto de perfil de usuario
 */
Route::post('/imagenes-perfil', [ImagenController::class, 'storePerfil'])->name('imagenes.perfil.store');

/**
 * ELIMINACIÓN DE IMÁGENES
 * Remover imágenes del servidor y base de datos
 */
Route::delete('/imagenes', [ImagenController::class, 'destroy'])->name('imagenes.destroy');

/**
 * SUBIDA DE ARCHIVOS
 * Para posts con archivos adjuntos (PDF, DOC, etc.)
 */
Route::post('/archivos', [ImagenController::class, 'storeArchivo'])->name('archivos.store');

/**
 * DESCARGA DE ARCHIVOS
 * Descargar archivos adjuntos en posts
 */
Route::get('/archivos/{filename}', function ($filename) {
    $filePath = storage_path('app/public/archivos/' . $filename);

    if (!file_exists($filePath)) {
        abort(404, 'Archivo no encontrado');
    }

    return response()->download($filePath);
})->name('archivos.download');

/**
 * VISTA PREVIA DE ARCHIVOS PDF
 * Ver archivos PDF sin descargar
 */
Route::get('/archivos/preview/{filename}', function ($filename) {
    $filePath = storage_path('app/public/archivos/' . $filename);

    if (!file_exists($filePath)) {
        abort(404, 'Archivo no encontrado');
    }

    $mimeType = mime_content_type($filePath);

    return response()->file($filePath, [
        'Content-Type' => $mimeType,
        'Content-Disposition' => 'inline; filename="' . $filename . '"'
    ]);
})->name('archivos.preview');

// ============================================================================
// SISTEMA DE SEGUIMIENTO (FOLLOW/UNFOLLOW)
// ============================================================================

/**
 * SEGUIR/DEJAR DE SEGUIR POR USERNAME
 * Sistema básico de seguimiento usando el nombre de usuario
 */
Route::post('/{user:username}/follow', [FollowerController::class, 'store'])->name('users.follow')->middleware('auth');
Route::delete('/{user:username}/unfollow', [FollowerController::class, 'destroy'])->name('users.unfollow')->middleware('auth');

/**
 * LISTAS DE SEGUIDORES Y SEGUIDOS
 * Muestra la lista de usuarios que siguen o que son seguidos por un usuario específico
 */
Route::get('/{user:username}/followers', [FollowerController::class, 'followers'])->name('users.followers');
Route::get('/{user:username}/following', [FollowerController::class, 'following'])->name('users.following');

/**
 * SEGUIR/DEJAR DE SEGUIR POR ID
 * Para funcionalidades AJAX y llamadas asíncronas
 */
Route::post('/users/{user}/follow', [FollowerController::class, 'storeById'])->name('users.follow.id')->middleware('auth');
Route::post('/users/{user}/unfollow', [FollowerController::class, 'destroyById'])->name('users.unfollow.id')->middleware('auth');

// ============================================================================
// INTEGRACIÓN CON APIs MUSICALES
// ============================================================================

/**
 * API DE SPOTIFY
 * Búsqueda de música y obtención de información de tracks
 */
Route::get('/spotify/search', [SpotifyApiController::class, 'search'])->name('spotify.search');
Route::get('/spotify/track', [SpotifyApiController::class, 'getTrack'])->name('spotify.track');

/**
 * API DE ITUNES
 * Búsqueda de música, tracks, géneros y contenido popular
 */
Route::get('/itunes/search', [iTunesApiController::class, 'search'])->name('itunes.search');
Route::get('/itunes/track', [iTunesApiController::class, 'getTrack'])->name('itunes.track');
Route::get('/itunes/genre', [iTunesApiController::class, 'searchByGenre'])->name('itunes.genre');
Route::get('/itunes/popular', [iTunesApiController::class, 'getPopular'])->name('itunes.popular');
Route::get('/itunes/more', [iTunesApiController::class, 'getMoreResults'])->name('itunes.more');


// ============================================================================
// SUPER USUARIO (SU) - PANEL DE ADMINISTRACIÓN
// ============================================================================

// Autenticación SU
Route::get('/us/su/lau/login', [SUController::class, 'login'])->name('su.us.laulogin');
Route::post('/us/su/lau/session', [SUController::class, 'storelau'])->name('su.us.lausess');
Route::post('/logoutus', [SUController::class, 'storeus'])->name('logoutus');

// Grupo protegido por Middleware de SU
Route::middleware(['auth:super'])
    ->prefix('us/su/lau')
    ->as('su.')
    ->group(function () {

        // Vistas Generales
        Route::get('/dashboard', [SUController::class, 'dashboard'])->name('dash');

        Route::get('/universidades', [SUController::class, 'universidad'])->name('uni');
        Route::post('/universidades', [SUController::class, 'storeuni'])->name('uni.store');
        Route::put('/universidades/{id}', [SUController::class, 'updateUni'])->name('uni.update');

        Route::get('/carreras', [SUController::class, 'carrera'])->name('uni.ca');
        // Ruta para crear la carrera en el catálogo (Botón Superior)
        Route::post('/carreras/crear', [SUController::class, 'storeCarrera'])->name('ca.store');

        // Ruta para vincular la carrera a la universidad (Botón Interno)
        Route::post('/carreras/vincular', [SUController::class, 'assignCarrera'])->name('ca.assign');


        // Gestión de Usuarios SU
        Route::get('/usuarios', [SUController::class, 'userperfil'])->name('usuarios');
        Route::get('/buscar-usuarios', [SUController::class, 'buscarUsuarios'])->name('user.buscar');
        Route::get('/info/{user:username}', [SUController::class, 'info'])->name('info');
        Route::get('/reportes', [SUController::class, 'reportes'])->name('reportes');

        Route::delete('/usuarios/{id}', [SUController::class, 'destroy'])->name('user.destroy');

        // Gestión de Insignias
        Route::get('/insignias', [SUController::class, 'insig'])->name('insig');
        Route::post('/insignias/create', [SUController::class, 'storeinsig'])->name('insig.create');
        Route::post('/users/insignia', [SUController::class, 'addInsignia'])->name('add.insig');
        Route::put('/info/{user:username}/insignia', [SUController::class, 'editInsignia'])->name('update.insig');
        Route::delete('/info/{user:username}/insignia', [SUController::class, 'deleteInsignia'])->name('delete.insig');

        // Gestión de Anuncios (Banners)
        Route::get('/ads/create', [SUController::class, 'ads'])->name('ads');
        Route::post('/ads/create', [SUController::class, 'create'])->name('ads.create');
        Route::put('/ads/update/{id}', [SUController::class, 'update'])->name('ads.update');
        Route::delete('/ads/{id}', [SUController::class, 'delete'])->name('ads.delete');

        Route::post('/ads/{id}/reset-views', [SUController::class, 'resetViews'])->name('ads.resetviews');

        // Gestion de actualización app
        Route::get('/updates', [SUController::class, 'updateindex'])->name('updates.index');
        Route::post('/updates', [SUController::class, 'storeup'])->name('updates.store');
        Route::patch('/updates/{id}/activate', [SUController::class, 'activateup'])->name('updates.activate');
        Route::delete('/updates/{id}', [SUController::class, 'destroyup'])->name('updates.destroy');
    });


// ============================================================================
// CHATIFY - CUSTOM ROUTES OVERRIDE
// ============================================================================

use App\Http\Controllers\ChatifyOverrideController;

/**
 * Custom Chatify endpoints to preserve unread counts and chat order
 * These routes override the default Chatify behavior
 */
Route::middleware(['web', 'auth'])->prefix('chatify')->group(function () {
    Route::post('/getContactsCustom', [ChatifyOverrideController::class, 'getContacts'])->name('chatify.contacts.get.custom');
    Route::get('/getContactsCustom', [ChatifyOverrideController::class, 'getContacts'])->name('chatify.contacts.get.custom.fallback');
    Route::post('/updateContacts', [ChatifyOverrideController::class, 'updateContactItem'])->name('chatify.contacts.update');
    Route::post('/mutualContactsList', [ChatifyOverrideController::class, 'mutualContactsList'])->name('chatify.mutual.contacts');
    Route::post('/idInfo', [ChatifyOverrideController::class, 'idInfo'])->name('chatify.idinfo');
});
