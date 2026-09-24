<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = User::with(['universidad', 'carrera'])
                ->select(['id', 'name', 'username', 'imagen', 'insignia', 'universidad_id', 'carrera_id'])
                ->withCount('followers')
                ->withExists([
                    'followers as is_following' => fn ($q) => $q->where('follower_id', Auth::id()),
                ]);

            if ($request->has('search') && !empty($request->search)) {
                $searchTerm = '%'.$request->search.'%';

                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'LIKE', $searchTerm)
                        ->orWhere('username', 'LIKE', $searchTerm);
                });
            }

            if ($request->has('universidad_id') && !empty($request->universidad_id)) {
                $query->where('universidad_id', $request->universidad_id);
            }

            if ($request->has('carrera_id') && !empty($request->carrera_id)) {
                $query->where('carrera_id', $request->carrera_id);
            }

            if ($request->has('order') && !empty($request->order)) {
                switch ($request->order) {
                    case 'ABC':
                        $query->orderBy('name', 'asc');
                        break;
                    case 'CBA':
                        $query->orderBy('name', 'desc');
                        break;
                    case 'ASC':
                        $query->orderBy('id', 'asc');
                        break;
                    case 'DESC':
                        $query->orderBy('id', 'desc');
                        break;
                }
            } else {
                $query->orderBy('id', 'desc');
            }

            $users = $query->paginate(20);

            $users->getCollection()->transform(function ($user) {
                if ($user->imagen) {
                    $user->imagen_url = url('perfiles/'.$user->imagen);
                }

                return $user;
            });

            return response()->json([
                'success' => true,
                'data' => $users,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener usuarios',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function foreignUser($usuario_username)
    {
        try {
            $user = User::with(['universidad', 'carrera'])
                ->where('username', $usuario_username)
                ->select(['id', 'name', 'username', 'imagen', 'insignia', 'universidad_id', 'carrera_id'])
                ->firstOrFail();

            if ($user->imagen) {
                $user->imagen_url = url('perfiles/'.$user->imagen);
            }

            return response()->json([
                'success' => true,
                'data' => $user,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo obtener el usuario',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    public function show(User $user)
    {
        try {
            $user->load(['universidad', 'carrera']);

            $user->load(['posts' => function ($query) {
                $query->with(['comentarios', 'likes'])->latest();
            }]);

            $user->loadCount(['posts', 'followers', 'following']);

            if ($user->imagen) {
                $user->imagen_url = url('perfiles/'.$user->imagen);
            }

            return response()->json([
                'success' => true,
                'data' => $user,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener usuario',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function search(Request $request)
    {
        try {
            $query = $request->input('q');

            $users = User::with(['universidad', 'carrera'])
                ->where('name', 'like', "%{$query}%")
                ->orWhere('username', 'like', "%{$query}%")
                ->select(['id', 'name', 'username', 'imagen', 'insignia', 'universidad_id', 'carrera_id'])
                ->take(10)
                ->get();

            $users->transform(function ($user) {
                if ($user->imagen) {
                    $user->imagen_url = url('perfiles/'.$user->imagen);
                }

                return $user;
            });

            return response()->json([
                'success' => true,
                'data' => $users,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error en búsqueda',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function posts($userIdentifier)
    {
        try {
            $user = is_numeric($userIdentifier)
                ? User::find($userIdentifier)
                : User::where('username', $userIdentifier)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado.',
                ], 404);
            }

            $posts = Post::where('user_id', $user->id)
                ->with(['user', 'comentarios.user', 'likes'])
                ->withCount(['comentarios', 'likes'])
                ->latest()
                ->paginate(20);

            $posts->getCollection()->transform(function ($post) {
                if ($post->imagen) {
                    $post->imagen_url = url('uploads/'.$post->imagen);
                }

                if ($post->archivo) {
                    $post->archivo_url = url('files/'.$post->archivo);
                }

                if ($post->user && $post->user->imagen) {
                    $post->user->setAttribute('imagen_url', url('perfiles/'.$post->user->imagen));
                }

                if ($post->comentarios) {
                    $post->comentarios->transform(function ($comentario) {
                        if ($comentario->user && $comentario->user->imagen) {
                            $comentario->user->setAttribute(
                                'imagen_url',
                                url('perfiles/'.$comentario->user->imagen)
                            );
                        }

                        return $comentario;
                    });
                }

                return $post;
            });

            return response()->json([
                'success' => true,
                'data' => $posts,
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'name' => $user->name,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener posts del usuario',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function stats($userIdentifier)
    {
        try {
            $user = is_numeric($userIdentifier)
                ? User::find($userIdentifier)
                : User::where('username', $userIdentifier)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado.',
                ], 404);
            }

            $user->loadCount(['posts', 'followers', 'following']);

            $totalLikes = $user->posts()->withCount('likes')->get()->sum('likes_count');
            $totalComments = $user->posts()->withCount('comentarios')->get()->sum('comentarios_count');

            $isFollowing = false;
            if (Auth::check() && Auth::id() !== $user->id) {
                $isFollowing = $user->followers()
                    ->where('follower_id', Auth::id())
                    ->exists();
            }

            $profileImageUrl = $user->imagen
                ? url('perfiles/'.$user->imagen)
                : null;

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'name' => $user->name,
                    'imagen' => $user->imagen,
                    'imagen_url' => $profileImageUrl,
                    'insignia' => $user->insignia,
                    'posts_count' => $user->posts_count,
                    'followers_count' => $user->followers_count,
                    'following_count' => $user->following_count,
                    'total_likes_received' => $totalLikes,
                    'total_comments_received' => $totalComments,
                    'is_following' => $isFollowing,
                    'universidad' => $user->universidad,
                    'carrera' => $user->carrera,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas del usuario',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}