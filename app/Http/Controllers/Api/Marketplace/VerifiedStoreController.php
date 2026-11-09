<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Models\Store;
use Illuminate\Http\JsonResponse;

/** Directorio público de comercios verificados por LaU. */
class VerifiedStoreController extends MarketplaceController
{
    public function index(): JsonResponse
    {
        $stores = Store::where('is_active', true)
            ->where('verification_status', 'approved')
            ->with('category:id,name,slug,icon')
            ->withCount(['products as products_count' => fn ($q) => $q->where('status', 'active')->where('stock', '>', 0)])
            ->orderByRaw("CASE WHEN paypal_status = 'enabled' THEN 0 ELSE 1 END")
            ->orderBy('verified_at')
            ->limit(50)
            ->get();

        return $this->respond($stores);
    }
}
