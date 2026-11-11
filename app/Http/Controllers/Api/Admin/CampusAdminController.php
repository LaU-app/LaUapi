<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Feria;
use App\Models\Universidad;
use App\Services\Marketplace\CampusGeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Panel del equipo: ubicación y área de entrega de cada campus, y ferias con anuncio por campus. */
class CampusAdminController extends Controller
{
    public function campuses(): JsonResponse
    {
        $campuses = Universidad::query()
            ->select(['id', 'nombre', 'siglas', 'latitud', 'longitud', 'radio_entrega_m', 'direccion'])
            ->withCount(['products as products_count' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('nombre')->get();

        return response()->json(['success' => true, 'data' => $campuses]);
    }

    public function updateLocation(Request $request, Universidad $universidad): JsonResponse
    {
        $data = $request->validate([
            'latitud' => 'required|numeric|between:'.CampusGeo::LAT_MIN.','.CampusGeo::LAT_MAX,
            'longitud' => 'required|numeric|between:'.CampusGeo::LNG_MIN.','.CampusGeo::LNG_MAX,
            'radio_entrega_m' => 'required|integer|between:100,2000',
            'direccion' => 'nullable|string|max:255',
        ], [
            'latitud.between' => 'La ubicación tiene que estar en El Salvador.',
            'longitud.between' => 'La ubicación tiene que estar en El Salvador.',
            'radio_entrega_m.between' => 'El radio de entrega va de 100 a 2000 m.',
        ]);

        $universidad->forceFill($data + ['ubicado_en' => now()])->save();

        return response()->json([
            'success' => true,
            'message' => 'Ubicación de '.CampusGeo::label($universidad).' guardada.',
            'data' => $universidad->fresh()->only(['id', 'nombre', 'siglas', 'latitud', 'longitud', 'radio_entrega_m', 'direccion'])
                + ['products_count' => $universidad->products()->where('status', 'active')->count()],
        ]);
    }

    public function ferias(): JsonResponse
    {
        $ferias = Feria::with('universidad:id,nombre,siglas')
            ->orderByRaw('CASE WHEN termina_en >= ? THEN 0 ELSE 1 END', [now()])
            ->orderBy('inicia_en')
            ->limit(200)->get();

        return response()->json(['success' => true, 'data' => $ferias]);
    }

    public function storeFeria(Request $request): JsonResponse
    {
        $feria = DB::transaction(fn () => $this->saveFeria(new Feria, $this->validateFeria($request)));

        return response()->json(['success' => true, 'message' => 'Feria creada.', 'data' => $feria], 201);
    }

    public function updateFeria(Request $request, Feria $feria): JsonResponse
    {
        $feria = DB::transaction(fn () => $this->saveFeria($feria, $this->validateFeria($request)));

        return response()->json(['success' => true, 'message' => 'Feria actualizada.', 'data' => $feria]);
    }

    public function destroyFeria(Feria $feria): JsonResponse
    {
        DB::transaction(function () use ($feria) {
            $feria->banner?->delete();
            $feria->delete();
        });

        return response()->json(['success' => true, 'message' => 'Feria eliminada.']);
    }

    /** Las fechas del formulario son hora de El Salvador; la base guarda en la zona de la app (UTC). */
    public const LOCAL_TZ = 'America/El_Salvador';

    private function validateFeria(Request $request): array
    {
        $data = $request->validate([
            'universidad_id' => 'required|integer|exists:universidades,id',
            'titulo' => 'required|string|max:120',
            'descripcion' => 'nullable|string|max:1000',
            'lugar' => 'nullable|string|max:160',
            'inicia_en' => 'required|date',
            'termina_en' => 'required|date|after:inicia_en',
            'latitud' => 'nullable|numeric|between:'.CampusGeo::LAT_MIN.','.CampusGeo::LAT_MAX.'|required_with:longitud',
            'longitud' => 'nullable|numeric|between:'.CampusGeo::LNG_MIN.','.CampusGeo::LNG_MAX.'|required_with:latitud',
            'crear_anuncio' => 'sometimes|boolean',
        ], [
            'termina_en.after' => 'La feria tiene que terminar después de empezar.',
        ]);

        // Sin zona explícita ("2026-11-20T10:00"), la hora es la de El Salvador.
        foreach (['inicia_en', 'termina_en'] as $field) {
            $data[$field] = Carbon::parse($data[$field], self::LOCAL_TZ)->setTimezone(config('app.timezone'));
        }

        return $data;
    }

    /** Guarda la feria y su anuncio: el anuncio solo lo ven estudiantes de ese campus. */
    private function saveFeria(Feria $feria, array $data): Feria
    {
        $withBanner = (bool) ($data['crear_anuncio'] ?? $feria->banner_id !== null);
        unset($data['crear_anuncio']);
        $feria->fill($data)->save();

        if ($withBanner) {
            $campus = Universidad::find($feria->universidad_id);
            $starts = Carbon::parse($feria->inicia_en)->setTimezone(self::LOCAL_TZ);
            $banner = $feria->banner ?? new Banner;
            $banner->fill([
                'title' => Str::limit($feria->titulo, 120, ''),
                'content' => Str::limit(trim(implode(' · ', array_filter([
                    $feria->lugar ? $feria->lugar.', '.CampusGeo::label($campus) : CampusGeo::label($campus),
                    $starts->locale('es')->translatedFormat('j \d\e F, g:i a'),
                    $feria->descripcion,
                ]))), 500, '…'),
                'type' => Banner::TYPE_INFO,
                'action_text' => 'Ver en el mapa',
                'action_url' => '/tabs/marketplace/mapa',
                'is_active' => true,
                // Se anuncia desde una semana antes hasta que termina.
                'start_date' => $starts->copy()->subDays(7)->max(now()),
                'end_date' => $feria->termina_en,
                'universidad_id' => $feria->universidad_id,
            ])->save();
            $feria->forceFill(['banner_id' => $banner->id])->save();
        } elseif ($feria->banner_id) {
            $feria->banner?->delete();
            $feria->forceFill(['banner_id' => null])->save();
        }

        return $feria->fresh('universidad:id,nombre,siglas');
    }
}
