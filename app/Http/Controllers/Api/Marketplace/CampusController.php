<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Models\Feria;
use App\Models\Product;
use App\Models\Universidad;
use App\Services\Marketplace\ProductWebLinksService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Campus en el mapa (dónde se vende y se entrega), ferias y el producto en la web por su foto. */
class CampusController extends MarketplaceController
{
    public const CAMPUS_COLUMNS = ['id', 'nombre', 'siglas', 'color_primario', 'latitud', 'longitud', 'radio_entrega_m', 'direccion'];

    public function map(Request $request): JsonResponse
    {
        $campuses = Universidad::query()
            ->select(self::CAMPUS_COLUMNS)
            ->withCount([
                'products as products_count' => fn ($q) => $q->where('status', 'active')->where('stock', '>', 0),
            ])
            ->with(['ferias' => fn ($q) => $q->vigentes()->limit(5)])
            ->orderBy('nombre')
            ->get();

        // Comercios verificados (con PayPal) que tienen productos activos en cada campus.
        $stores = Product::query()
            ->where('status', 'active')
            ->whereNotNull('store_id')
            ->whereHas('store', fn ($q) => $q->acceptingPaypal())
            ->selectRaw('universidad_id, count(distinct store_id) as total')
            ->groupBy('universidad_id')
            ->pluck('total', 'universidad_id');

        return $this->respond([
            'campuses' => $campuses->map(fn (Universidad $u) => $u->toArray() + ['stores_count' => (int) ($stores[$u->id] ?? 0)])->all(),
            'my_campus_id' => $request->user()->universidad_id,
        ]);
    }

    public function ferias(Request $request): JsonResponse
    {
        $data = $request->validate(['universidad_id' => 'sometimes|integer']);

        return $this->respond(
            Feria::vigentes()
                ->with('universidad:id,nombre,siglas')
                ->when(isset($data['universidad_id']), fn ($q) => $q->where('universidad_id', $data['universidad_id']))
                ->limit(30)->get()
        );
    }

    public function webLinks(Product $product, ProductWebLinksService $links): JsonResponse
    {
        abort_unless($product->status === 'active', 404);

        return $this->respond($links->for($product));
    }
}
