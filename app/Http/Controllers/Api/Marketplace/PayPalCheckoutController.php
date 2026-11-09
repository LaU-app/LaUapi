<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Models\Product;
use App\Services\Marketplace\CheckoutException;
use App\Services\Marketplace\PayPalCheckoutService;
use App\Services\PayPal\PayPalException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pago con PayPal de productos de comercios verificados. Respuestas pequeñas: nunca se
 * devuelven tokens, credenciales ni las respuestas completas de PayPal.
 */
class PayPalCheckoutController extends MarketplaceController
{
    public function __construct(private readonly PayPalCheckoutService $checkout) {}

    /** Resumen que el estudiante confirma: comercio, producto, precio, cantidad y total. */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer',
            'quantity' => 'nullable|integer|min:1|max:'.PayPalCheckoutService::MAX_QUANTITY,
        ]);

        return $this->guard('quote', fn () => $this->respond(
            $this->checkout->quote($request->user(), $this->product($data['product_id']), (int) ($data['quantity'] ?? 1))
        ));
    }

    public function store(Request $request): JsonResponse
    {
        // Solo producto, cantidad y el identificador del intento: precio y total los fija el servidor.
        $data = $request->validate([
            'product_id' => 'required|integer',
            'quantity' => 'nullable|integer|min:1|max:'.PayPalCheckoutService::MAX_QUANTITY,
            'checkout_attempt_id' => 'required|uuid',
            'assistant_decision_id' => 'nullable|integer',
        ]);

        return $this->guard('create', fn () => $this->respond(
            $this->checkout->createOrder(
                $request->user(),
                $this->product($data['product_id']),
                (int) ($data['quantity'] ?? 1),
                $data['checkout_attempt_id'],
                $data['assistant_decision_id'] ?? null,
            ),
            'Orden creada.',
            201,
        ));
    }

    public function capture(Request $request, string $paypalOrderId): JsonResponse
    {
        return $this->guard('capture', fn () => $this->respond(
            $this->checkout->capture($request->user(), $paypalOrderId),
            'Pago completado.',
        ));
    }

    private function product(int $id): Product
    {
        $product = Product::with('store')->find($id);
        if (! $product) {
            throw new CheckoutException('product_unavailable', 'Este producto ya no está disponible.', 404);
        }

        return $product;
    }

    private function guard(string $step, Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (CheckoutException $e) {
            if ($e->details !== []) {
                Log::info('PayPal checkout: '.$step, ['code' => $e->errorCode, 'details' => $e->details]);
            }

            return $this->fail($e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (PayPalException $e) {
            // El mensaje ya viene sanitizado (sin tokens, credenciales ni cuerpos completos).
            Log::warning('PayPal checkout: '.$step, ['error' => $e->getMessage()]);

            return $e->httpStatus === null
                ? $this->fail('paypal_unavailable', 'El pago con PayPal no está disponible en este momento.', 503)
                : $this->fail('paypal_error', 'PayPal no pudo completar la operación. Intentá de nuevo en un momento.', 502);
        }
    }

    private function fail(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'code' => $code, 'message' => $message], $status);
    }
}
