<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AppUpdate;

class AppUpdateController extends Controller
{
    public function checkUpdate(Request $request)
    {
        // Buscamos la actualización que esté marcada como activa en tu VPS
        $latestUpdate = AppUpdate::where('is_active', true)->first();

        // Recibimos la versión actual de la app que nos manda el celular
        $appVersion = $request->query('app_version'); 

        // Si no hay actualizaciones activas, no hacemos nada
        if (!$latestUpdate) {
            return response()->json(['update_available' => false]);
        }

        // EL ESCUDO ANTI-DOWNGRADE:
        // Si la versión de la app es IGUAL o MAYOR a la del OTA, bloqueamos la actualización
        if ($appVersion && version_compare($appVersion, $latestUpdate->version, '>=')) {
            return response()->json(['update_available' => false]);
        }

        // Si llegamos aquí, significa que el OTA es mayor que la app, ¡así que actualizamos!
        return response()->json([
            'update_available' => true,
            'version' => $latestUpdate->version,
            'zip_url' => asset('storage/' . $latestUpdate->file_path)
        ]);
    }
}
