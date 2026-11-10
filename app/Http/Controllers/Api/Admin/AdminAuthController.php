<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\su_ad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Acceso del equipo de LaU (superusuarios) al panel de comercios desde la app.
 * Emite un token corto (8 h) que solo vale en /api/lau-admin/*.
 */
class AdminAuthController extends Controller
{
    public const TOKEN_HOURS = 8;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|max:190',
            'password' => 'required|string|max:200',
        ]);

        $admin = su_ad::where('email', $data['email'])->first();

        if (! $admin || ! $admin->is_admin || ! Hash::check($data['password'], $admin->password)) {
            return response()->json(['success' => false, 'message' => 'Las credenciales no coinciden.'], 422);
        }

        // Una sola sesión del panel por superusuario: iniciar sesión cierra la anterior.
        $admin->tokens()->where('name', 'lau-admin-panel')->delete();
        $expiresAt = now()->addHours(self::TOKEN_HOURS);
        $token = $admin->createToken('lau-admin-panel', ['lau-admin'], $expiresAt)->plainTextToken;
        $admin->forceFill(['last_login' => now()])->save();

        return response()->json(['success' => true, 'data' => [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'admin' => $this->present($admin),
        ]]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->present($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['success' => true, 'message' => 'Sesión cerrada.']);
    }

    private function present(su_ad $admin): array
    {
        return ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email];
    }
}
