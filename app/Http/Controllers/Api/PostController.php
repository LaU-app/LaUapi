<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Carrera;
use App\Models\Post;
use App\Models\User;
use App\Jobs\ProcessMentionsJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

/**
 * Controlador de Posts para la API de LaU app
 * Maneja todas las operaciones CRUD de posts (crear, leer, actualizar, eliminar)
 * Soporta posts de imagen y música con sus respectivos metadatos
 */
class PostController extends Controller
{
    /**
     * Obtiene todos los posts para el feed de la API
     */
    public function index()
    {
        try {
            $posts = Post::with(['user', 'comentarios.user', 'likes'])
                ->withCount(['comentarios', 'likes'])
                ->latest()
                ->paginate(20);

            $posts->getCollection()->transform(function ($post) {
                if ($post->imagen) {
                    $post->imagen_url = url('uploads/' . $post->imagen);
                }
                if ($post->imagen_mini) {
                    $post->imagen_mini_url = url('uploads/' . $post->imagen_mini);
                }
                if ($post->archivo) {
                    $post->archivo_url = url('files/' . $post->archivo);
                }
                if ($post->user && $post->user->imagen) {
                    $post->user->imagen_url = url('perfiles/' . $post->user->imagen);
                }
                // Nota: En el feed mantenemos la carga simple para rendimiento.
                // Si necesitas anidamiento en el feed, habría que aplicar la lógica de show() aquí también.
                if ($post->comentarios) {
                    $post->comentarios->transform(function ($comentario) {
                        if ($comentario->user && $comentario->user->imagen) {
                            $comentario->user->imagen_url = url('perfiles/' . $comentario->user->imagen);
                        }
                        return $comentario;
                    });
                }
                if ($post->likes) {
                    $post->likes->transform(function ($like) {
                        if ($like->user && $like->user->imagen) {
                            $like->user->imagen_url = url('perfiles/' . $like->user->imagen);
                        }
                        return $like;
                    });
                }
                return $post;
            });

            return response()->json([
                'success' => true,
                'data' => $posts
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener posts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function filtropost(Request $request){
        $universidad = $request->universidad;
        $carrera = $request->carrera;

        try {
            // Construir el query base
            $query = Post::with(['user', 'comentarios.user', 'likes'])
                ->withCount(['comentarios', 'likes']);

            // Aplicar filtros solo si no están vacíos
            if (!empty($universidad) || !empty($carrera)) {
                $query->whereHas('user', function ($q) use ($universidad, $carrera) {
                    if (!empty($universidad)) {
                        $q->where('universidad_id', $universidad);
                    }
                    if (!empty($carrera)) {
                        $q->where('carrera_id', $carrera);
                    }
                });
            }

            $posts = $query->latest()->paginate(20);


            $posts->getCollection()->transform(function ($post) {
                if ($post->imagen) {
                    $post->imagen_url = url('uploads/' . $post->imagen);
                }
                if ($post->archivo) {
                    $post->archivo_url = url('files/' . $post->archivo);
                }
                if ($post->user && $post->user->imagen) {
                    $post->user->imagen_url = url('perfiles/' . $post->user->imagen);
                }
                // Nota: En el feed mantenemos la carga simple para rendimiento.
                // Si necesitas anidamiento en el feed, habría que aplicar la lógica de show() aquí también.
                if ($post->comentarios) {
                    $post->comentarios->transform(function ($comentario) {
                        if ($comentario->user && $comentario->user->imagen) {
                            $comentario->user->imagen_url = url('perfiles/' . $comentario->user->imagen);
                        }
                        return $comentario;
                    });
                }
                if ($post->likes) {
                    $post->likes->transform(function ($like) {
                        if ($like->user && $like->user->imagen) {
                            $like->user->imagen_url = url('perfiles/' . $like->user->imagen);
                        }
                        return $like;
                    });
                }
                return $post;
            });

            return response()->json([
                'success' => true,
                'data' => $posts
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener posts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crea un nuevo post
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'descripcion' => 'nullable|string|max:500',
                'tipo' => 'required|in:imagen,musica,texto,archivo',
                'imagen' => 'required_if:tipo,imagen|image|max:20480',
                'archivo' => 'nullable|mimes:pdf,doc,docx,zip|max:5120',
                // Campos legacy
                'artista' => 'nullable|string|max:255',
                'titulo' => 'nullable|string|max:255',
                'album' => 'nullable|string|max:255',
                'texto' => 'nullable|string|max:5000',
                // Campos iTunes
                'music_source' => 'nullable|string|max:50',
                'itunes_track_id' => 'nullable|string|max:100',
                'itunes_track_name' => 'nullable|string|max:255',
                'itunes_artist_name' => 'nullable|string|max:255',
                'itunes_collection_name' => 'nullable|string|max:255',
                'itunes_artwork_url' => 'nullable|string|max:500',
                'itunes_preview_url' => 'nullable|string|max:500',
                'itunes_track_view_url' => 'nullable|string|max:500',
                'itunes_track_time_millis' => 'nullable|integer',
                'itunes_country' => 'nullable|string|max:10',
                'itunes_primary_genre_name' => 'nullable|string|max:100',
                'apple_music_url' => 'nullable|string|max:500',
                'spotify_web_url' => 'nullable|string|max:500',
                'artist_search_term' => 'nullable|string|max:255',
                'track_search_term' => 'nullable|string|max:255',
                // Campos legacy música móvil
                'musica_url' => 'nullable|string|max:500',
                'musica_imagen' => 'nullable|string|max:500',
                'musica_artista' => 'nullable|string|max:255',
                'musica_titulo' => 'nullable|string|max:255',
                'musica_album' => 'nullable|string|max:255',
                'musica_duracion' => 'nullable|string|max:20',
                'musica_genero' => 'nullable|string|max:100',
                'itunes_url' => 'nullable|string|max:500',
            ]);

            $post = new Post();
            $post->user_id = Auth::id();
            $post->tipo = $request->tipo;

            if ($request->filled('descripcion')) {
                $post->descripcion = $request->descripcion;
            }

            // === AQUÍ ENTRA LA OPTIMIZACIÓN WEBP ===
            if ($request->hasFile('imagen')) {
                $file = $request->file('imagen');
                $nombreBase = time() . '_' . pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                
                // Nombres de archivos con extensión WebP
                $nombreFull = $nombreBase . '_full.webp';
                $nombreMini = $nombreBase . '_feed.webp';

                // Iniciamos el motor gráfico
                $manager = new ImageManager(new Driver());
                $img = $manager->read($file);

                // 1. Crear y guardar la versión FULL (1200px)
                $imgFull = clone $img;
                $imgFull->scaleDown(width: 1200)
                        ->toWebp(85) // Calidad 85%
                        ->save(public_path('uploads/' . $nombreFull));

                // 2. Crear y guardar la versión MINI (600px para el feed)
                $imgMini = clone $img;
                $imgMini->scaleDown(width: 600)
                        ->toWebp(70) // Calidad 70%
                        ->save(public_path('uploads/' . $nombreMini));

                // 3. Guardar SOLO los nombres en la base de datos (como lo hacías antes)
                $post->imagen = $nombreFull;
                $post->imagen_mini = $nombreMini; 
                // Nota: Asegúrate de tener 'imagen_mini' en tu modelo Post
            }
            // ===========================================

            if ($request->hasFile('archivo')) {
                $file = $request->file('archivo');
                $nombreArchivo = time() . '_' . $file->getClientOriginalName();
                $file->move(public_path('files'), $nombreArchivo);
                $post->archivo = $nombreArchivo;
            }

            // Asignación de campos según tipo (igual que tu versión original)
            if ($request->tipo === 'musica') {
                if ($request->filled('music_source')) $post->music_source = $request->music_source;
                if ($request->filled('itunes_track_id')) $post->itunes_track_id = $request->itunes_track_id;
                if ($request->filled('itunes_track_name')) $post->itunes_track_name = $request->itunes_track_name;
                if ($request->filled('itunes_artist_name')) $post->itunes_artist_name = $request->itunes_artist_name;
                if ($request->filled('itunes_collection_name')) $post->itunes_collection_name = $request->itunes_collection_name;
                if ($request->filled('itunes_artwork_url')) $post->itunes_artwork_url = $request->itunes_artwork_url;
                if ($request->filled('itunes_preview_url')) $post->itunes_preview_url = $request->itunes_preview_url;
                if ($request->filled('itunes_track_view_url')) $post->itunes_track_view_url = $request->itunes_track_view_url;
                if ($request->filled('itunes_track_time_millis')) $post->itunes_track_time_millis = $request->itunes_track_time_millis;
                if ($request->filled('itunes_country')) $post->itunes_country = $request->itunes_country;
                if ($request->filled('itunes_primary_genre_name')) $post->itunes_primary_genre_name = $request->itunes_primary_genre_name;
                if ($request->filled('apple_music_url')) $post->apple_music_url = $request->apple_music_url;
                if ($request->filled('spotify_web_url')) $post->spotify_web_url = $request->spotify_web_url;
                if ($request->filled('artist_search_term')) $post->artist_search_term = $request->artist_search_term;
                if ($request->filled('track_search_term')) $post->track_search_term = $request->track_search_term;

                // Legacy fallback
                if ($request->filled('musica_titulo') && !$request->filled('itunes_track_name')) $post->itunes_track_name = $request->musica_titulo;
                if ($request->filled('musica_artista') && !$request->filled('itunes_artist_name')) $post->itunes_artist_name = $request->musica_artista;
                if ($request->filled('musica_album') && !$request->filled('itunes_collection_name')) $post->itunes_collection_name = $request->musica_album;
                if ($request->filled('musica_imagen') && !$request->filled('itunes_artwork_url')) $post->itunes_artwork_url = $request->musica_imagen;
                if ($request->filled('musica_url') && !$request->filled('itunes_preview_url')) $post->itunes_preview_url = $request->musica_url;
                if ($request->filled('itunes_url') && !$request->filled('itunes_track_view_url')) $post->itunes_track_view_url = $request->itunes_url;
                if ($request->filled('musica_duracion') && !$request->filled('itunes_track_time_millis')) {
                    $duracion = $request->musica_duracion;
                    if (is_numeric($duracion)) $post->itunes_track_time_millis = $duracion;
                }
                if ($request->filled('musica_genero') && !$request->filled('itunes_primary_genre_name')) $post->itunes_primary_genre_name = $request->musica_genero;
                if ($request->filled('titulo')) $post->titulo = $request->titulo;

            } elseif ($request->tipo === 'imagen' || $request->tipo === 'archivo') {
                if ($request->filled('titulo')) $post->titulo = $request->titulo;
            } elseif ($request->tipo === 'texto') {
                if ($request->filled('texto')) $post->texto = $request->texto;
                if ($request->filled('titulo')) $post->titulo = $request->titulo;
            }

            $post->save();

            ProcessMentionsJob::dispatchSync(
                Auth::id(),
                trim(implode(' ', array_filter([
                    $post->titulo,
                    $post->descripcion,
                    $post->texto,
                ]))),
                'post',
                $post->id
            );

            // Cargar relaciones básicas para respuesta rápida (sin árbol completo aquí necesariamente)
            $post->load(['user', 'comentarios', 'likes']);
            $post->loadCount(['comentarios', 'likes']);

            // Armar las URLs completas para la respuesta del API
            if ($post->imagen) {
                $post->imagen_url = url('uploads/' . $post->imagen);
                // Si agregaste imagen_mini, mándala también en la respuesta
                if ($post->imagen_mini) {
                    $post->imagen_mini_url = url('uploads/' . $post->imagen_mini);
                }
            }
            if ($post->archivo) $post->archivo_url = url('files/' . $post->archivo);
            if ($post->user && $post->user->imagen) $post->user->imagen_url = url('perfiles/' . $post->user->imagen);

            return response()->json([
                'success' => true,
                'data' => $post,
                'message' => 'Post creado exitosamente'
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear post',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Obtiene un post específico con todos sus detalles
     * CORREGIDO: Ahora carga los comentarios de forma jerárquica (padres e hijos)
     */
    public function show(Post $post)
    {
        try {
            // 1. Cargar relaciones del post (usuario y likes), PERO NO los comentarios planos
            $post->load(['user', 'likes.user']);
            $post->loadCount(['comentarios', 'likes']);

            // 2. Cargar manualmente los comentarios con estructura de árbol
            // Solo traemos los comentarios RAÍZ (parent_id = null) y cargamos sus hijos (children)
            $comentarios = $post->comentarios()
                ->whereNull('parent_id')
                ->with(['user', 'children.user' => function($q) {
                    // Ordenar respuestas cronológicamente (la más vieja primero para leer el hilo)
                    $q->orderBy('created_at', 'asc');
                }])
                ->orderBy('created_at', 'desc') // Comentarios nuevos primero arriba
                ->get();

            // 3. Transformar los comentarios para agregar URLs de imágenes recursivamente
            $comentarios->transform(function ($comentario) {
                return $this->formatCommentTree($comentario);
            });

            // 4. Asignar la colección jerárquica al objeto post
            $post->setRelation('comentarios', $comentarios);

            // 5. Transformaciones del Post principal
            if ($post->imagen) {
                $post->imagen_url = url('uploads/' . $post->imagen);
            }
            if ($post->imagen_mini) {
                $post->imagen_mini_url = url('uploads/' . $post->imagen_mini);
            }
            if ($post->archivo) {
                $post->archivo_url = url('files/' . $post->archivo);
            }
            if ($post->user && $post->user->imagen) {
                $post->user->setAttribute('imagen_url', url('perfiles/' . $post->user->imagen));
            }

            // Transformar likes del post
            if ($post->likes) {
                $post->likes->transform(function ($like) {
                    if ($like->user && $like->user->imagen) {
                        $like->user->setAttribute('imagen_url', url('perfiles/' . $like->user->imagen));
                    }
                    return $like;
                });
            }

            return response()->json([
                'success' => true,
                'data' => $post
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener post',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Método auxiliar privado para formatear recursivamente el árbol de comentarios
     * Asegura que todos los niveles (padres e hijos) tengan la URL de la imagen del usuario
     */
    private function formatCommentTree($comentario)
    {
        // Formatear usuario del comentario actual
        if ($comentario->user && $comentario->user->imagen) {
            $comentario->user->imagen_url = url('perfiles/' . $comentario->user->imagen);
        }

        // Procesar hijos recursivamente si existen
        if ($comentario->children && $comentario->children->count() > 0) {
            $comentario->children->transform(function ($child) {
                return $this->formatCommentTree($child);
            });
        }

        return $comentario;
    }

    /**
     * Devuelve los posts de un usuario específico
     */
    public function userPosts($userId)
    {
        try {
            $posts = Post::where('user_id', $userId)
                ->with(['user', 'comentarios.user', 'likes.user'])
                ->withCount(['comentarios', 'likes'])
                ->latest()
                ->paginate(20);

            $posts->getCollection()->transform(function ($post) {
                if ($post->imagen) $post->imagen_url = url('uploads/' . $post->imagen);
                if ($post->archivo) $post->archivo_url = url('files/' . $post->archivo);
                if ($post->user && $post->user->imagen) $post->user->imagen_url = url('perfiles/' . $post->user->imagen);
                
                // Nota: Misma lógica simplificada que en index(). 
                // Si el perfil de usuario requiere ver hilos, cambiar esto.
                if ($post->comentarios) {
                    $post->comentarios->transform(function ($comentario) {
                        if ($comentario->user && $comentario->user->imagen) {
                            $comentario->user->imagen_url = url('perfiles/' . $comentario->user->imagen);
                        }
                        return $comentario;
                    });
                }
                if ($post->likes) {
                    $post->likes->transform(function ($like) {
                        if ($like->user && $like->user->imagen) {
                            $like->user->imagen_url = url('perfiles/' . $like->user->imagen);
                        }
                        return $like;
                    });
                }
                return $post;
            });

            return response()->json([
                'success' => true,
                'data' => $posts
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener posts del usuario',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Elimina un post específico
     */
    public function destroy(Post $post)
    {
        try {
            if ($post->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No autorizado'
                ], 403);
            }

            $post->delete();

            return response()->json([
                'success' => true,
                'message' => 'Post eliminado exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar post',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}