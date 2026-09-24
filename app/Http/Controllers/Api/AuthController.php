<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Rules\EmailDomain;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Exception;

/**
 * Controlador de autenticación para la API de SivarSocial
 * Maneja el login, registro, logout y obtención de datos del usuario autenticado
 */
class AuthController extends Controller
{
    /**
     * Método de login para la API
     * Autentica al usuario y devuelve un token Sanctum para acceso a la API
     */
    public function login(Request $request)
    {
        // Valido que me envíen email y password
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Busco al usuario por email en la base de datos
        $user = User::where('email', $request->email)->first();

        // Verifico que el usuario exista y que la contraseña coincida
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Las credenciales son incorrectas.'
            ], 401);
        }

        // Si las credenciales son correctas, creo un token de acceso para la API móvil
        $token = $user->createToken('mobile-app')->plainTextToken;

        // Devuelvo la respuesta con los datos del usuario y el token
        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    // Construyo la URL completa de la imagen de perfil o uso una por defecto
                    'imagen_url' => $user->imagen ? asset('perfiles/' . $user->imagen) : asset('img/usuario.svg'),
                    'profession' => $user->profession,
                    'insignia' => $user->insignia
                ],
                'token' => $token
            ],
            'message' => 'Login Exitoso'
        ]);
    }

    /**
     * Login/Registro con Redes Sociales (Google/Microsoft)
     */

    public function socialLogin(Request $request)
    {
        $request->validate([
            'provider' => 'required|in:google,microsoft',
            'token' => 'required|string',
            'username' => 'nullable|string|max:15|unique:users,username',
            'name' => 'nullable|string|max:255',
            'universidad_id' => 'nullable|integer',
            'carrera_id' => 'nullable|integer',
        ]);

        $provider = $request->provider;
        $token = $request->token;
        $socialUser = null;

        try {
            // Validación de Google/Microsoft
            if ($provider === 'google') {
                $response = Http::withToken($token)->get('https://www.googleapis.com/oauth2/v3/userinfo');
                if ($response->failed()) return response()->json(['success' => false, 'message' => 'Token de Google inválido'], 401);
                $data = $response->json();
                $socialUser = (object)['email' => $data['email'], 'name' => $data['name'], 'avatar' => $data['picture'] ?? null];
            } elseif ($provider === 'microsoft') {
                $response = Http::withToken($token)->get('https://graph.microsoft.com/v1.0/me');
                if ($response->failed()) return response()->json(['success' => false, 'message' => 'Error Microsoft: ' . $response->body()], 401);
                $data = $response->json();
                $socialUser = (object)['email' => $data['mail'] ?? $data['userPrincipalName'], 'name' => $data['displayName'], 'avatar' => null];
            }

            // DETECTAR UNIVERSIDAD POR DOMINIO 
            $email = $socialUser->email;
            $emailParts = explode('@', $email);
            $universidad = null;

            if (count($emailParts) === 2) {
                $domain = $emailParts[1]; // ues.edu.sv, utec.edu.sv
                $universidad = \App\Models\Universidad::where('dominio', $domain)->first();
            }

            $user = User::where('email', $socialUser->email)->first();

            if (!$user) {
                // SI ES NUEVO Y NO MANDÓ LOS DATOS EXTRA -> Detenemos y pedimos registro
                if (!$request->filled('username')) {
                    $response = [
                        'success' => true,
                        'requires_registration' => true,
                        'data' => [
                            'name' => $socialUser->name,
                            'email' => $socialUser->email
                        ],
                        'message' => 'Faltan datos para completar el registro.'
                    ];

                    // ========== AGREGAR UNIVERSIDAD DETECTADA A LA RESPUESTA ==========
                    if ($universidad) {
                        $response['universidad'] = [
                            'id' => $universidad->id,
                            'nombre' => $universidad->nombre,
                            'dominio' => $universidad->dominio
                        ];
                    }
                    // ==================================================================

                    return response()->json($response);
                }

                // SI ES NUEVO Y SÍ MANDÓ LOS DATOS EXTRA -> Lo creamos
                $user = User::create([
                    'name' => $request->name ?? $socialUser->name,
                    'email' => $socialUser->email,
                    'username' => $request->username,
                    'password' => Hash::make(Str::random(24)),
                    'universidad_id' => $request->universidad_id,
                    'carrera_id' => $request->carrera_id,
                ]);
            }

            // Generar token de sesión
            $token = $user->createToken('mobile-app')->plainTextToken;

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'email' => $user->email,
                        'imagen_url' => $user->imagen_url,
                        'universidad_id' => $user->universidad_id,
                        'carrera_id' => $user->carrera_id
                    ],
                    'token' => $token,
                    'is_new_user' => $user->wasRecentlyCreated
                ],
                'message' => 'Login exitoso'
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error en el servidor: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Método de registro para nuevos usuarios de la API
     * Crea un nuevo usuario y devuelve un token para acceso inmediato
     */
    public function register(RegisterRequest $request)
    {
        try {
            // Las validaciones ya se hicieron en RegisterRequest (incluyendo las condicionales según el rol)
            $validated = $request->validated();

            // Crear usuario con el rol
            $user = User::create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'], // aspirante, estudiante, egresado
                'universidad_id' => $validated['universidad_id'] ?? null,
                'carrera_id' => $validated['carrera_id'] ?? null,
            ]);

            // Cargo las relaciones de universidad y carrera si existen
            $user->load(['universidad', 'carrera']);

            // Genero un token inmediatamente para que pueda usar la app sin hacer login adicional
            $token = $user->createToken('mobile-app')->plainTextToken;

            // Devuelvo respuesta exitosa con el usuario creado y su token
            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user,
                    'token' => $token
                ],
                'message' => 'Usuario registrado exitosamente'
            ], 201);
        } catch (ValidationException $e) {
            // Si hay errores de validación, los devuelvo en formato JSON para la API
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        }
    }

    /**
     * Método de logout para la API
     * Elimina el token actual del usuario para cerrar la sesión
     */
    public function logout(Request $request)
    {
        // Elimino el token actual que está usando el usuario para cerrar su sesión
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout exitoso'
        ]);
    }

    /**
     * Método para obtener los datos del usuario autenticado
     * Devuelve la información del usuario que está usando la API
     */
    public function me(Request $request)
    {
        // Obtengo el usuario autenticado a través del token
        $user = $request->user();

        // Agrego la URL completa de la imagen de perfil para que la app móvil pueda mostrarla
        $user->imagen_url = $user->imagen ? asset('perfiles/' . $user->imagen) : asset('img/usuario.svg');

        return response()->json([
            'success' => true,
            'data' => $user
        ]);
    }
    public function edit(Request $request)
    {
        try {
            // Validación de campos
            $request->validate([
                'id' => 'required',
                'name' => 'required|string|max:255',
                'username' => 'required|string|max:255',
                'email' => 'required|string|email|max:255',
                'profession' => 'nullable|string|max:255',
                'univerdidad_id' => 'nullable|exists:universidades,id',
                'carrera_id' => 'nullable|exists:carreras,id',
                'gender' => 'nullable',
                'imagen' => 'nullable|image|mimes:jpeg,png,jpg|max:20480' // Validar imagen de perfil

            ]);

            $user = User::find($request->id);
            if (!$user) {
                throw new Exception('Usuario no encontrado');
            }
            $username = User::where('username', $request->username)->first();
            if ($username && $username->id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'El username ya está en uso por otro usuario'
                ], 422);
            }

            // Actualizar los campos de texto
            $user->name = $request->name;
            $user->username = $request->username;
            $user->email = $request->email;
            if ($request->has('profession')) {
                $user->profession = $request->profession;
            }
            if ($request->has('universidad_id')) {
                $user->universidad_id = $request->universidad_id;
            }
            if ($request->has('carrera_id')) {
                $user->carrera_id = $request->carrera_id;
            }
            if ($request->has('gender')) {
                $user->gender = $request->gender;
            }

            // Procesar imagen de perfil si se envió una nueva
            if ($request->hasFile('imagen')) {
                $imagen = $request->file('imagen');
                $nombreImagen = Str::uuid() . ".jpg";

                $manager = new ImageManager(new Driver());

                try {
                    // Leer la imagen
                    $imagenServidor = $manager->read($imagen);

                    // Obtener dimensiones originales
                    $width = $imagenServidor->width();
                    $height = $imagenServidor->height();

                    // Tamaño objetivo para perfiles
                    $targetSize = 400;

                    // Mantener proporciones originales
                    $scale = min($targetSize / $width, $targetSize / $height);
                    $newWidth = (int)($width * $scale);
                    $newHeight = (int)($height * $scale);

                    // Redimensionar manteniendo proporciones
                    $imagenServidor->scale($newWidth, $newHeight);

                    // Guardar con calidad alta para perfiles
                    $imagenPath = public_path('perfiles') . '/' . $nombreImagen;
                    $imagenServidor->save($imagenPath, 90);

                    // Eliminar imagen antigua si existe
                    if ($user->imagen) {
                        $oldImagePath = public_path('perfiles/' . $user->imagen);
                        if (file_exists($oldImagePath)) {
                            unlink($oldImagePath);
                        }
                    }

                    // Actualizar el campo imagen
                    $user->imagen = $nombreImagen;
                } catch (\Exception $e) {
                    // Si falla Intervention Image, usar método tradicional
                    $imagen->move(public_path('perfiles'), $nombreImagen);

                    // Eliminar imagen antigua si existe
                    if ($user->imagen) {
                        $oldImagePath = public_path('perfiles/' . $user->imagen);
                        if (file_exists($oldImagePath)) {
                            unlink($oldImagePath);
                        }
                    }

                    $user->imagen = $nombreImagen;
                }
            }

            // Guardar cambios en la base de datos
            $user->save();

            // Agregar URL completa de la imagen de perfil
            $user->imagen_url = $user->imagen ? asset('perfiles/' . $user->imagen) : asset('img/usuario.svg');

            return response()->json([
                'success' => true,
                'data' => $user,
                'message' => 'Usuario actualizado exitosamente'
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Verificar disponibilidad de username
     * Endpoint público para validar username en tiempo real durante el registro
     */
    public function checkUsername(Request $request)
    {
        $exists = User::where('username', $request->username)->exists();
        return response()->json(['available' => !$exists]);
    }

    /**
     * Verificar disponibilidad de email
     * Endpoint público para validar email en tiempo real durante el registro
     */
    public function checkEmail(Request $request)
    {
        $exists = User::where('email', $request->email)->exists();
        return response()->json(['available' => !$exists]);
    }
}
