<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Canje del código de autorización de Google (flujo web con PKCE). El client secret solo vive
 * en el servidor; la app recibe el access token y sigue con /auth/social-login como antes.
 * Nunca se registran códigos ni tokens.
 */
class GoogleOAuthController extends Controller
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:2048',
            'code_verifier' => 'required|string|min:43|max:128',
            'redirect_uri' => 'required|string|max:255',
        ]);

        $config = config('services.google_oauth');
        if (! $config['client_secret']) {
            return response()->json(['success' => false, 'message' => 'El inicio de sesión con Google no está disponible.'], 503);
        }

        $allowed = array_merge([config('services.frontend_url').'/auth/callback'], $config['extra_redirects']);
        if (! in_array($data['redirect_uri'], $allowed, true)) {
            return response()->json(['success' => false, 'message' => 'Origen de inicio de sesión no permitido.'], 422);
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->post(self::TOKEN_ENDPOINT, [
                'grant_type' => 'authorization_code',
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'redirect_uri' => $data['redirect_uri'],
                'code' => $data['code'],
                'code_verifier' => $data['code_verifier'],
            ]);
        } catch (ConnectionException) {
            return response()->json(['success' => false, 'message' => 'No pudimos conectar con Google. Intentá de nuevo.'], 502);
        }

        if ($response->failed() || ! $response->json('access_token')) {
            Log::warning('Google OAuth: canje rechazado', ['status' => $response->status(), 'error' => $response->json('error')]);

            return response()->json(['success' => false, 'message' => 'Google rechazó el inicio de sesión. Intentá de nuevo.'], 401);
        }

        return response()->json(['success' => true, 'data' => [
            'access_token' => $response->json('access_token'),
            'id_token' => $response->json('id_token'),
        ]]);
    }
}
