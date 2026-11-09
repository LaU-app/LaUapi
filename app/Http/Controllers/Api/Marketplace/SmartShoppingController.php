<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Services\Marketplace\SmartShoppingService;
use App\Services\TypeSafe\JevException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** "Compra inteligente con Jev": recomendaciones sobre productos reales de comercios verificados. */
class SmartShoppingController extends MarketplaceController
{
    public function __construct(private readonly SmartShoppingService $shopping) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query' => 'required|string|min:3|max:500',
            // Presupuesto de esta búsqueda; no se guarda como saldo.
            'max_budget' => 'nullable|numeric|min:0.5|max:10000',
        ], [
            'query.required' => 'Contanos qué necesitás.',
            'query.min' => 'Contanos un poco más de lo que necesitás.',
        ]);

        try {
            $plan = $this->shopping->plan(
                $request->user(),
                $data['query'],
                isset($data['max_budget']) ? (string) $data['max_budget'] : null,
            );
        } catch (JevException $e) {
            // Un fallo de Jev es un error, nunca una respuesta: no se inventan recomendaciones.
            Log::warning('Compra inteligente: Jev', ['reason' => $e->reason, 'status' => $e->httpStatus, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'code' => 'assistant_unavailable',
                'message' => 'La compra inteligente no está disponible en este momento. Podés buscar en el catálogo mientras tanto.',
            ], 503);
        }

        return $this->respond($plan);
    }
}
