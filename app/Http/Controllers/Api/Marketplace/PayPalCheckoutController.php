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
        $request->validate($this->lineRules());

        return $this->guard('quote', fn () => $this->respond(
            $this->checkout->quoteLines($request->user(), $this->lines($request))
        ));
    }

    public function store(Request $request): JsonResponse
    {
        // Solo productos, cantidades y el identificador del intento: precio y total los fija el servidor.
        $data = $request->validate($this->lineRules() + [
            'checkout_attempt_id' => 'required|uuid',
            'assistant_decision_id' => 'nullable|integer',
        ]);

        return $this->guard('create', fn () => $this->respond(
            $this->checkout->createOrderLines(
                $request->user(),
                $this->lines($request),
                $data['checkout_attempt_id'],
                $data['assistant_decision_id'] ?? null,
            ),
            'Orden creada.',
            201,
        ));
    }

    /** Un producto (product_id + quantity) o el carrito de un comercio (items[]). */
    private function lineRules(): array
    {
        $quantity = 'integer|min:1|max:'.PayPalCheckoutService::MAX_QUANTITY;

        return [
            'product_id' => 'required_without:items|integer',
            'quantity' => 'nullable|'.$quantity,
            'items' => 'nullable|array|min:1|max:'.PayPalCheckoutService::MAX_LINES,
            'items.*.product_id' => 'required|integer|distinct',
            'items.*.quantity' => 'required|'.$quantity,
        ];
    }

    /** @return list<array{0: Product, 1: int}> */
    private function lines(Request $request): array
    {
        $raw = $request->filled('items')
            ? $request->input('items')
            : [['product_id' => $request->input('product_id'), 'quantity' => $request->input('quantity', 1)]];

        return array_map(fn ($line) => [$this->product((int) $line['product_id']), (int) $line['quantity']], array_values($raw));
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
