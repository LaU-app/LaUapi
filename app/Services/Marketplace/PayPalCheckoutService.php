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
 * Pago con PayPal (Orders v2, intent CAPTURE) de productos de comercios verificados:
 * un producto, o varios del mismo comercio (carrito). Un pago es siempre de un solo comercio.
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

    /** Máximo de productos distintos en un mismo pago (carrito de un comercio). */
    public const MAX_LINES = 10;

    /**
     * Resumen que el estudiante confirma antes de pagar: comercio, producto, precio y total.
     *
     * @return array{product: array, store: array, unit_price: string, quantity: int, total: string, currency: string, merchant_id: string, items: list<array>}
     */
    public function quote(User $buyer, Product $product, int $quantity): array
    {
        return $this->quoteLines($buyer, [[$product, $quantity]]);
    }

    /**
     * Resumen de varios productos de un mismo comercio (carrito). Un pago = un comercio.
     *
     * @param  list<array{0: Product, 1: int}>  $lines
     */
    public function quoteLines(User $buyer, array $lines): array
    {
        $lines = $this->assertLines($buyer, $lines);
        [$first, $firstQuantity] = $lines[0];
        $merchantId = $this->verifiedMerchantId($first->store);

        $items = array_map(fn (array $line) => [
            'product_id' => $line[0]->id,
            'name' => $line[0]->name,
            'image_url' => $line[0]->images()->first()?->url,
            'unit_price' => self::money(self::cents($line[0]->price)),
            'quantity' => $line[1],
            'subtotal' => self::money(self::cents($line[0]->price) * $line[1]),
        ], $lines);

        return [
            // product / unit_price / quantity: el primer producto (pago de un solo producto).
            'product' => ['id' => $first->id, 'name' => $first->name, 'image_url' => $items[0]['image_url']],
            'store' => [
                'name' => $first->store->name,
                'slug' => $first->store->slug,
                'logo_url' => $first->store->logo_url,
                'is_verified' => true,
            ],
            'unit_price' => $items[0]['unit_price'],
            'quantity' => $firstQuantity,
            'items' => $items,
            'total' => self::money(self::linesCents($lines)),
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
        return $this->createOrderLines($buyer, [[$product, $quantity]], $attemptId, $decisionId);
    }

    /** @param  list<array{0: Product, 1: int}>  $lines */
    public function createOrderLines(User $buyer, array $lines, string $attemptId, ?int $decisionId = null): array
    {
        $quote = $this->quoteLines($buyer, $lines);
        $store = $lines[0][0]->store;
        $refs = self::refs($lines);
        $money = fn (string $value) => ['currency_code' => self::CURRENCY, 'value' => $value];

        $order = $this->paypal->createOrder([
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => self::reference($refs, $store->id),
                'custom_id' => self::customId($buyer->id, $refs),
                'description' => Str::limit('LaU Marketplace · '.$store->name, 127, ''),
                'amount' => $money($quote['total']) + ['breakdown' => ['item_total' => $money($quote['total'])]],
                'payee' => ['merchant_id' => $quote['merchant_id']],
                'items' => array_map(fn (array $item) => [
                    'name' => Str::limit($item['name'], 127, ''),
                    'sku' => 'LAU-P'.$item['product_id'],
                    'quantity' => (string) $item['quantity'],
                    'unit_amount' => $money($item['unit_price']),
                    'category' => 'PHYSICAL_GOODS',
                ], $quote['items']),
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

        $this->assertOrderMatches($order, $quote['merchant_id'], $buyer->id, $refs, $store->id, $quote['total'], payeeRequired: false);

        $status = (string) ($order['status'] ?? '');
        if (! in_array($status, ['CREATED', 'PAYER_ACTION_REQUIRED'], true)) {
            throw new CheckoutException('unexpected_order_state', 'PayPal devolvió la orden en un estado inesperado. Intentá de nuevo.', 502, ["status={$status}"]);
        }

        if ($decisionId) {
            AssistantDecision::whereKey($decisionId)->where('user_id', $buyer->id)
                ->update(['selected_product_id' => $lines[0][0]->id, 'paypal_order_id' => $orderId]);
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

            $refs = $this->purchaseFrom($order, $buyer->id);
            $products = Product::withTrashed()->with('store')->whereKey(array_keys($refs))->get()->keyBy('id');
            $lines = [];
            foreach ($refs as $productId => $quantity) {
                $product = $products->get($productId);
                if (! $product || ! $product->store) {
                    throw new CheckoutException('order_not_found', 'No encontramos ese pago.', 404, ['producto o comercio inexistente']);
                }
                $lines[] = [$product, $quantity];
            }
            $store = $lines[0][0]->store;
            if (collect($lines)->contains(fn ($line) => $line[0]->store_id !== $store->id)) {
                throw new CheckoutException('order_not_found', 'No encontramos ese pago.', 404, ['productos de varios comercios']);
            }

            $merchantId = $this->verifiedMerchantId($store);
            $total = self::money(self::linesCents($lines));
            $this->assertOrderMatches($order, $merchantId, $buyer->id, $refs, $store->id, $total);

            $status = (string) ($order['status'] ?? '');
            if ($status === 'COMPLETED') {
                throw new CheckoutException('already_captured', 'Este pago ya se había completado; no se cobró de nuevo.', 409);
            }
            if ($status !== 'APPROVED') {
                throw new CheckoutException('not_approved', 'El pago todavía no fue aprobado en PayPal.', 409, ["status={$status}"]);
            }

            $result = DB::transaction(function () use ($buyer, $lines, $refs, $store, $orderId, $merchantId, $total) {
                // Existencias bloqueadas mientras PayPal captura: si no alcanza, no se cobra.
                $locked = Product::whereKey(array_keys($refs))->lockForUpdate()->get()->keyBy('id');
                foreach ($refs as $productId => $quantity) {
                    $row = $locked->get($productId);
                    if (! $row || $row->status !== 'active' || $row->stock < $quantity) {
                        $message = count($refs) > 1
                            ? ($row?->name ?? 'Un producto').' se agotó antes de completar el pago. No se te cobró.'
                            : 'Ese producto se agotó antes de completar el pago. No se te cobró.';
                        throw new CheckoutException('out_of_stock', $message, 409);
                    }
                }

                try {
                    $captured = $this->paypal->captureOrder($orderId, 'lau-mkt-capture-'.$orderId);
                } catch (PayPalException $e) {
                    throw $this->captureError($e);
                }

                $this->assertOrderMatches($captured, $merchantId, $buyer->id, $refs, $store->id, $total);
                $capture = $this->completedCapture($captured, $total);

                $lauOrder = Order::create([
                    'uuid_cliente' => (string) Str::uuid(),
                    'code' => Order::generateCode(),
                    'buyer_id' => $buyer->id,
                    'seller_id' => $lines[0][0]->user_id,
                    'store_id' => $store->id,
                    'total' => $total,
                    'status' => 'pending',
                    'payment_method' => 'paypal',
                    'payment_status' => 'paid',
                    'paypal_order_id' => $orderId,
                    'paypal_capture_id' => $capture['id'],
                    'paid_at' => now(),
                    'meeting_point' => $lines[0][0]->delivery_point,
                ]);
                foreach ($lines as [$product, $quantity]) {
                    $lauOrder->items()->create([
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'unit_price' => $product->price,
                        'quantity' => $quantity,
                        'image_filename' => $product->images()->value('filename'),
                    ]);
                    Product::whereKey($product->id)->decrement('stock', $quantity);
                }

                AssistantDecision::where('paypal_order_id', $orderId)->where('user_id', $buyer->id)
                    ->update(['order_id' => $lauOrder->id]);

                return [
                    'order_id' => $orderId,
                    'capture_id' => $capture['id'],
                    'status' => 'COMPLETED',
                    'amount' => $total,
                    'currency' => self::CURRENCY,
                    'store' => $store->name,
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

    /**
     * Valida cada línea y que todas sean del mismo comercio. Devuelve las líneas normalizadas.
     *
     * @param  list<array{0: Product, 1: int}>  $lines
     * @return list<array{0: Product, 1: int}>
     */
    private function assertLines(User $buyer, array $lines): array
    {
        $lines = array_values($lines);
        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw new CheckoutException('invalid_quantity', 'Podés pagar hasta '.self::MAX_LINES.' productos distintos a la vez.', 422);
        }

        $seen = [];
        foreach ($lines as [$product, $quantity]) {
            $this->assertPurchasable($buyer, $product, $quantity);
            if (isset($seen[$product->id])) {
                throw new CheckoutException('invalid_quantity', 'Ese producto está repetido en el pago.', 422);
            }
            $seen[$product->id] = true;
        }

        $storeId = $lines[0][0]->store_id;
        if (collect($lines)->contains(fn ($line) => $line[0]->store_id !== $storeId)) {
            throw new CheckoutException('mixed_stores', 'Cada pago con PayPal es para un solo comercio.', 422);
        }

        return $lines;
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

    /**
     * Comprador, productos y cantidades salen del custom_id que LaU puso al crear la orden.
     *
     * @return array<int, int> product_id => cantidad
     */
    private function purchaseFrom(array $order, int $buyerId): array
    {
        $customId = $order['purchase_units'][0]['custom_id'] ?? '';
        $notFound = fn () => new CheckoutException('order_not_found', 'No encontramos ese pago.', 404, ['custom_id ajeno']);
        if (! is_string($customId)) {
            throw $notFound();
        }

        // Un producto: lau|u7|p12|q2 — varios del mismo comercio: lau|u7|12x2,15x1
        if (preg_match('/^lau\|u(\d+)\|p(\d+)\|q(\d+)$/', $customId, $m) === 1) {
            $refs = [(int) $m[2] => (int) $m[3]];
        } elseif (preg_match('/^lau\|u(\d+)\|((?:\d+x\d+,){1,'.(self::MAX_LINES - 1).'}\d+x\d+)$/', $customId, $m) === 1) {
            $refs = [];
            foreach (explode(',', $m[2]) as $pair) {
                [$id, $quantity] = array_map('intval', explode('x', $pair));
                if (isset($refs[$id])) {
                    throw $notFound();
                }
                $refs[$id] = $quantity;
            }
        } else {
            throw $notFound();
        }

        if ((int) $m[1] !== $buyerId) {
            throw $notFound();
        }

        return $refs;
    }

    /** @param  array<int, int>  $refs */
    private function assertOrderMatches(array $order, string $merchantId, int $buyerId, array $refs, ?int $storeId, string $total, bool $payeeRequired = true): void
    {
        $units = $order['purchase_units'] ?? null;
        if (! is_array($units) || count($units) !== 1 || ! is_array($units[0] ?? null)) {
            throw new CheckoutException('order_not_found', 'No encontramos ese pago.', 404, ['purchase_units inesperadas']);
        }
        $unit = $units[0];

        if (($unit['reference_id'] ?? null) !== self::reference($refs, $storeId)
            || ($unit['custom_id'] ?? null) !== self::customId($buyerId, $refs)) {
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

    /** @param  list<array{0: Product, 1: int}>  $lines */
    private static function linesCents(array $lines): int
    {
        return array_sum(array_map(fn (array $line) => self::cents($line[0]->price) * $line[1], $lines));
    }

    /**
     * @param  list<array{0: Product, 1: int}>  $lines
     * @return array<int, int>
     */
    private static function refs(array $lines): array
    {
        $refs = [];
        foreach ($lines as [$product, $quantity]) {
            $refs[$product->id] = $quantity;
        }

        return $refs;
    }

    /** @param  array<int, int>  $refs */
    private static function reference(array $refs, ?int $storeId): string
    {
        return count($refs) === 1 ? 'LAU-P'.array_key_first($refs) : 'LAU-S'.$storeId;
    }

    /** @param  array<int, int>  $refs */
    private static function customId(int $buyerId, array $refs): string
    {
        if (count($refs) === 1) {
            $productId = array_key_first($refs);

            return "lau|u{$buyerId}|p{$productId}|q{$refs[$productId]}";
        }

        return "lau|u{$buyerId}|".implode(',', array_map(fn ($id, $quantity) => "{$id}x{$quantity}", array_keys($refs), $refs));
    }
}
