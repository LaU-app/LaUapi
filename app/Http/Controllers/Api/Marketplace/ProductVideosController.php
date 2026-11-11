<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Models\Product;
use App\Services\Marketplace\ProductVideosService;
use Illuminate\Http\JsonResponse;

/** Reseñas y videos de YouTube sobre un producto (filtrados por Jev). */
class ProductVideosController extends MarketplaceController
{
    public function __invoke(Product $product, ProductVideosService $videos): JsonResponse
    {
        abort_if($product->status !== 'active', 404);

        return $this->respond($videos->for($product->load('category:id,name')));
    }
}
