<?php

namespace App\Http\Middleware;

use App\Models\su_ad;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Solo superusuarios del panel de LaU, con un token emitido para el panel. */
class EnsureLauAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (! $admin instanceof su_ad || ! $admin->is_admin || ! $admin->tokenCan('lau-admin')) {
            return response()->json(['success' => false, 'message' => 'Acceso solo para el equipo de LaU.'], 403);
        }

        return $next($request);
    }
}
