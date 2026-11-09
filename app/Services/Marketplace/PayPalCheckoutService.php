<?php

namespace App\Services\Marketplace;

use App\Models\AssistantDecision;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\PayPal\PayPalClient;
use App\Services\PayPal\PayPalException;
use App\Services\PayPal\SellerStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pago con PayPal (Orders v2, intent CAPTURE) de productos de comercios verificados.
 *
 * - Producto, precio, existencias y total los calcula el backend; el navegador solo envía
 *   qué producto y cuántas unidades.
 * - Antes de cotizar, crear y capturar se confirma con PayPal que el comercio puede cobrar.
 * - La orden consultada a PayPal es la fuente de verdad: comprador, comercio, monto y moneda
 *   se validan antes y después de capturar. El pedido de LaU solo existe si PayPal confirma
 *   la captura como COMPLETED.
 */
class PayPalCheckoutService
{
    public const CURRENCY = 'USD';

    public const MAX_QUANTITY = 10;

    public function __construct(private readonly PayPalClient $paypal) {}

    /**
     * Resumen que el estudiante confirma antes de pagar: comercio, producto, precio y total.
     *
     * @return array{product: array, store: array, unit_price: string, quantity: int, total: string, currency: string, merchant_id: string}
     */
    public function quote(User $buyer, Product $product, int $quantity): array
    {
        $this->assertPurchasable($buyer, $product, $quantity);
        $merchantId = $this->verifiedMerchantId($product->store);

        return [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'image_url' => $product->images()->first()?->url,
            ],
            'store' => [
                'name' => $product->store->name,
                'slug' => $product->store->slug,
                'logo_url' => $product->store->logo_url,
                'is_verified' => true,
            ],
            'unit_price' => self::money(self::cents($product->price)),
            'quantity' => $quantity,
            'total' => self::money(self::cents($product->price) * $quantity),
            'currency' => self::CURRENCY,
            // Público: el SDK de PayPal lo necesita para mostrar el botón del comercio correcto.
            'merchant_id' => $merchantId,
        ];
    }

    /**
     * Crea la orden en PayPal. $attemptId hace idempotente la creación ante reintentos.
     *
     * @return array{order_id: string, status: string, total: string, currency: string}
     */
    public function createOrder(User $buyer, Product $product, int $quantity, string $attemptId, ?int $decisionId = null): array
    {
        $quote = $this->quote($buyer, $product, $quantity);
        $money = fn (string $value) => ['currency_code' => self::CURRENCY, 'value' => $value];

        $order = $this->paypal->createOrder([
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => self::reference($product->id),
                'custom_id' => self::customId($buyer->id, $product->id, $quantity),
                'description' => Str::limit('LaU Marketplace · '.$product->store->name, 127, ''),
                'amount' => $money($quote['total']) + ['breakdown' => ['item_total' => $money($quote['total'])]],
                'payee' => ['merchant_id' => $quote['merchant_id']],
                'items' => [[
                    'name' => Str::limit($product->name, 127, ''),
                    'sku' => self::reference($product->id),
                    'quantity' => (string) $quantity,
                    'unit_amount' => $money($quote['unit_price']),
                    'category' => 'PHYSICAL_GOODS',
                ]],
            ]],
            'application_context' => [
                'brand_name' => 'LaU Marketplace',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
            ],
        ], 'lau-mkt-create-u'.$buyer->id.'-'.$attemptId);

        $orderId = $order['id'] ?? null;
        if (! is_string($orderId) || ! PayPalClient::isValidOrderId($orderId)) {
            throw PayPalException::unexpected('Crear orden', 'sin ID de orden válido');
        }

        $this->assertOrderMatches($order, $quote['merchant_id'], $buyer->id, $product->id, $quantity, $quote['total'], payeeRequired: false);

        $status = (string) ($order['status'] ?? '');
        if (! in_array($status, ['CREATED', 'PAYER_ACTION_REQUIRED'], true)) {
            throw new CheckoutException('unexpected_order_state', 'PayPal devolvió la orden en un estado inesperado. Intentá de nuevo.', 502, ["status={$status}"]);
        }

        if ($decisionId) {
            AssistantDecision::whereKey($decisionId)->where('user_id', $buyer->id)
                ->update(['selected_product_id' => $product->id, 'paypal_order_id' => $orderId]);
        }

        return ['order_id' => $orderId, 'status' => $status, 'total' => $quote['total'], 'currency' => self::CURRENCY];
    }

    /**
     * Verifica con PayPal y captura. Solo con la captura COMPLETED se crea el pedido pagado.
     *
     * @return array{order_id: string, capture_id: string, status: string, amount: string, currency: string, store: string, order_code: string, lau_order_id: int}
     */
    public function capture(User $buyer, string $orderId): array
    {
        if (! PayPalClient::isValidOrderId($orderId)) {
            throw new CheckoutException('order_not_found', 'No encontramos ese pago.', 404);
        }

        if (Order::where('paypal_order_id', $orderId)->where('buyer_id', $buyer->id)->exists()) {
            throw new CheckoutException('already_captured', 'Este pago ya se había completado; no se cobró de nuevo.', 409);
        }

        // Evita dos capturas simultáneas de la misma orden desde este servidor.
        $lock = Cache::lock('paypal-capture:'.$orderId, 60);
        if (! $lock->get()) {
            throw new CheckoutException('capture_in_progress', 'Ya estamos procesando este pago. Esperá unos segundos.', 409);
        }

        try {
            try {
                $order = $this->paypal->showOrder($orderId);
            } catch (PayPalException $e) {
                if ($e->httpStatus === 404) {
                    throw new CheckoutException('order_not_found', 'No encontramos ese pago.', 404);
                }
                throw $e;
            }

            [$productId, $quantity] = $this->purchaseFrom($order, $buyer->id);
            $product = Product::withTrashed()->with('store')->find($productId);
            if (! $product || ! $product->store) {
                throw new CheckoutException('order_not_found', 'No encontramos ese pago.', 404, ['producto o comercio inexistente']);
            }

            $merchantId = $this->verifiedMerchantId($product->store);
            $total = self::money(self::cents($product->price) * $quantity);
            $this->assertOrderMatches($order, $merchantId, $buyer->id, $productId, $quantity, $total);

            $status = (string) ($order['status'] ?? '');
            if ($status === 'COMPLETED') {
                throw new CheckoutException('already_captured', 'Este pago ya se había completado; no se cobró de nuevo.', 409);
            }
            if ($status !== 'APPROVED') {
                throw new CheckoutException('not_approved', 'El pago todavía no fue aprobado en PayPal.', 409, ["status={$status}"]);
            }

            $result = DB::transaction(function () use ($buyer, $product, $quantity, $orderId, $merchantId, $total) {
                // Existencias bloqueadas mientras PayPal captura: si no alcanza, no se cobra.
                $locked = Product::whereKey($product->id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== 'active' || $locked->stock < $quantity) {
                    throw new CheckoutException('out_of_stock', 'Ese producto se agotó antes de completar el pago. No se te cobró.', 409);
                }

                try {
                    $captured = $this->paypal->captureOrder($orderId, 'lau-mkt-capture-'.$orderId);
                } catch (PayPalException $e) {
                    throw $this->captureError($e);
                }

                $this->assertOrderMatches($captured, $merchantId, $buyer->id, $product->id, $quantity, $total);
                $capture = $this->completedCapture($captured, $total);

                $lauOrder = Order::create([
                    'uuid_cliente' => (string) Str::uuid(),
                    'code' => Order::generateCode(),
                    'buyer_id' => $buyer->id,
                    'seller_id' => $product->user_id,
                    'store_id' => $product->store_id,
                    'total' => $total,
                    'status' => 'pending',
                    'payment_method' => 'paypal',
                    'payment_status' => 'paid',
                    'paypal_order_id' => $orderId,
                    'paypal_capture_id' => $capture['id'],
                    'paid_at' => now(),
                    'meeting_point' => $product->delivery_point,
                ]);
                $lauOrder->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $product->price,
                    'quantity' => $quantity,
                    'image_filename' => $product->images()->value('filename'),
                ]);
                Product::whereKey($product->id)->decrement('stock', $quantity);

                AssistantDecision::where('paypal_order_id', $orderId)->where('user_id', $buyer->id)
                    ->update(['order_id' => $lauOrder->id]);

                return [
                    'order_id' => $orderId,
                    'capture_id' => $capture['id'],
                    'status' => 'COMPLETED',
                    'amount' => $total,
                    'currency' => self::CURRENCY,
                    'store' => $product->store->name,
                    'order_code' => $lauOrder->code,
                    'lau_order_id' => $lauOrder->id,
                ];
            });

            return $result;
        } finally {
            $lock->release();
        }
    }

    /** Merchant ID que PayPal confirma ahora mismo para el comercio (y se guarda la lectura). */
    private function verifiedMerchantId(?Store $store): string
    {
        if (! $store || ! $store->accepts_paypal || ! $store->is_active) {
            throw new CheckoutException('store_not_ready', 'Este comercio todavía no acepta pagos con PayPal.', 409);
        }

        try {
            $status = $this->paypal->sellerStatus($store->paypal_tracking_id);
        } catch (PayPalException $e) {
            throw $e;
        } catch (Throwable) {
            throw new CheckoutException('store_not_ready', 'No pudimos confirmar el comercio con PayPal.', 503);
        }

        if ($status->state !== SellerStatus::ENABLED || $status->merchantId === null) {
            $store->forceFill(['paypal_status' => 'pending', 'paypal_checked_at' => now()])->save();
            throw new CheckoutException('store_not_ready', 'Este comercio todavía no puede recibir pagos con PayPal.', 409, $status->reasons);
        }

        if ($status->merchantId !== $store->paypal_merchant_id) {
            $store->forceFill(['paypal_merchant_id' => $status->merchantId])->save();
        }

        return $status->merchantId;
    }

    private function assertPurchasable(User $buyer, Product $product, int $quantity): void
    {
        $product->loadMissing('store');

        if ($product->trashed() || $product->status !== 'active') {
            throw new CheckoutException('product_unavailable', 'Este producto ya no está disponible.', 409);
        }
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new CheckoutException('invalid_quantity', 'Elegí entre 1 y '.self::MAX_QUANTITY.' unidades.', 422);
        }
        if ($product->stock < $quantity) {
            throw new CheckoutException('out_of_stock', 'No hay suficientes existencias. Quedan '.$product->stock.'.', 409);
        }
        if ($product->user_id === $buyer->id) {
            throw new CheckoutException('own_product', 'No podés comprar tus propios productos.', 422);
        }
    }

    /** Comprador, producto y cantidad salen del custom_id que LaU puso al crear la orden. */
    private function purchaseFrom(array $order, int $buyerId): array
    {
        $customId = $order['purchase_units'][0]['custom_id'] ?? '';
        if (! is_string($customId) || preg_match('/^lau\|u(\d+)\|p(\d+)\|q(\d+)$/', $customId, $m) !== 1 || (int) $m[1] !== $buyerId) {
            throw new CheckoutException('order_not_found', 'No encontramos ese pago.', 404, ['custom_id ajeno']);
        }

        return [(int) $m[2], (int) $m[3]];
    }

    private function assertOrderMatches(array $order, string $merchantId, int $buyerId, int $productId, int $quantity, string $total, bool $payeeRequired = true): void
    {
        $units = $order['purchase_units'] ?? null;
        if (! is_array($units) || count($units) !== 1 || ! is_array($units[0] ?? null)) {
            throw new CheckoutException('order_not_found', 'No encontramos ese pago.', 404, ['purchase_units inesperadas']);
        }
        $unit = $units[0];

        if (($unit['reference_id'] ?? null) !== self::reference($productId)
            || ($unit['custom_id'] ?? null) !== self::customId($buyerId, $productId, $quantity)) {
            throw new CheckoutException('order_not_found', 'No encontramos ese pago.', 404, ['referencia o comprador distintos']);
        }

        $payee = $unit['payee']['merchant_id'] ?? null;
        if (($payee !== null || $payeeRequired) && $payee !== $merchantId) {
            throw new CheckoutException('seller_mismatch', 'El comercio de este pago no coincide con el comercio verificado.', 409, ['payee distinto']);
        }

        self::assertMoney($unit['amount'] ?? null, $total, 'amount');
    }

    private function completedCapture(array $captured, string $total): array
    {
        $capture = $captured['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (! is_array($capture) || ! is_string($capture['id'] ?? null) || preg_match('/^[A-Z0-9]{8,64}$/', $capture['id']) !== 1) {
            throw PayPalException::unexpected('Capturar orden', 'sin captura');
        }

        self::assertMoney($capture['amount'] ?? null, $total, 'captura');

        // Solo se informa como pagado lo que PayPal confirma como COMPLETED.
        if (($captured['status'] ?? null) !== 'COMPLETED' || ($capture['status'] ?? null) !== 'COMPLETED') {
            $reason = is_string($capture['status_details']['reason'] ?? null) ? $capture['status_details']['reason'] : 'sin motivo';
            throw new CheckoutException(
                'capture_pending',
                'PayPal recibió el pago pero lo dejó pendiente; todavía no está completado.',
                202,
                ['captura='.($capture['status'] ?? '?'), "motivo={$reason}", "capture_id={$capture['id']}"],
            );
        }

        return $capture;
    }

    private function captureError(PayPalException $e): Throwable
    {
        return match (true) {
            $e->hasIssue('ORDER_ALREADY_CAPTURED') || $e->hasIssue('DUPLICATE_INVOICE_ID')
                => new CheckoutException('already_captured', 'Este pago ya se había completado; no se cobró de nuevo.', 409),
            $e->hasIssue('ORDER_NOT_APPROVED')
                => new CheckoutException('not_approved', 'El pago todavía no fue aprobado en PayPal.', 409),
            $e->hasIssue('INSTRUMENT_DECLINED')
                => new CheckoutException('instrument_declined', 'PayPal rechazó el método de pago. Elegí otro e intentá de nuevo.', 422),
            default => $e,
        };
    }

    private static function assertMoney(mixed $money, string $expected, string $where): void
    {
        if (! is_array($money) || ($money['currency_code'] ?? null) !== self::CURRENCY || ($money['value'] ?? null) !== $expected) {
            throw new CheckoutException('amount_mismatch', 'El monto o la moneda del pago no coinciden con el producto.', 409, ["{$where} distinto de {$expected} ".self::CURRENCY]);
        }
    }

    /** Precio en centavos enteros: los totales nunca se calculan con flotantes. */
    public static function cents(mixed $price): int
    {
        [$whole, $fraction] = array_pad(explode('.', number_format((float) $price, 2, '.', '')), 2, '00');

        return ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public static function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function reference(int $productId): string
    {
        return 'LAU-P'.$productId;
    }

    private static function customId(int $buyerId, int $productId, int $quantity): string
    {
        return "lau|u{$buyerId}|p{$productId}|q{$quantity}";
    }
}
