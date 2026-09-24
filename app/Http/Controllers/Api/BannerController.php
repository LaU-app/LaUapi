<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BannerController extends Controller
{
    /**
     * Devuelve el banner activo que el usuario autenticado aún no ha visto.
     */
    public function getActive(Request $request)
    {
        $user = $request->user();

        // 1. Vemos cuántos banners hay en total sin filtros
        $totalBanners = Banner::count();

        // 2. Vemos cuántos pasan la prueba de "scopeActive" (fechas y estado)
        $activeBanners = Banner::active()->get();

        // 3. Tu consulta real (la que falla)
        $banner = Banner::active()
            ->whereDoesntHave('viewedByUsers', function ($query) use ($user) {
                $query->where('banner_user_views.user_id', $user->id); 
            })
            ->first();

        // Si es null, enviamos el reporte médico al frontend
        if (!$banner) {
            return response()->json([
                'error_detectado' => 'El banner se filtró y quedó en null',
                '1_total_banners_bd' => $totalBanners,
                '2_banners_activos' => $activeBanners,
                '3_usuario_id' => $user->id
            ], 200);
        }

        return response()->json($banner, 200);
    }

    /**
     * Marca un banner específico como visto por el usuario autenticado.
     */
    public function markViewed(Request $request, Banner $banner)
    {
        $user = $request->user();

        // Usamos el método que ya creaste en tu modelo Banner.php
        $banner->markAsViewedBy($user->id);

        return response()->json([
            'success' => true,
            'message' => 'Banner marcado como visto correctamente'
        ], 200);
    }
}