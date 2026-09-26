<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Http\Controllers\Controller;

abstract class MarketplaceController extends Controller
{
    protected function respond($data, ?string $message = null, int $status = 200)
    {
        $payload = ['success' => true, 'data' => $data];
        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload, $status);
    }
}
