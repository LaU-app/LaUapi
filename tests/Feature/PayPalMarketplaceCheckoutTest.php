<?php

namespace Tests\Feature;

use App\Models\AssistantDecision;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

/** Pago con PayPal de productos de comercios verificados. Todo simulado con Http::fake. */
class PayPalMarketplaceCheckoutTest extends TestCase
{
    use MarketplaceFixtures;
    use RefreshDatabase;

    private const ORDER = '5O190127TN364715T';

    private const CAPTURE = '3C679366HH908993F';

    private const ORDERS_URL = self::HOST.'/v2/checkout/orders';

    private const ORDER_URL = self::ORDERS_URL.'/'.self::ORDER;

    private const CAPTURE_URL = self::ORDER_URL.'/capture';

    private const ATTEMPT = '7b0f3c9e-2f1a-4c55-9d8e-1a2b3c4d5e6f';

    private User $buyer;

    private Store $store;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFakeServices();

        $this->buyer = User::factory()->create();
        $this->store = $this->verifiedStore();
        $this->product = $this->productFor($this->store, ['price' => '18.50', 'stock' => 5]);
        Sanctum::actingAs($this->buyer);
    }

    // ---------- Cotización y creación ----------

    public function test_quote_shows_store_product_and_total_from_the_backend(): void
    {
        Http::fake($this->paypalSellerFakes());

        $this->postJson('/api/marketplace/paypal/quote', ['product_id' => $this->product->id, 'quantity' => 2])
            ->assertOk()
            ->assertJsonPath('data.unit_price', '18.50')
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.total', '37.00')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.store.name', 'Papelería Campus')
            ->assertJsonPath('data.merchant_id', self::MERCHANT);
    }

    public function test_create_order_uses_backend_price_and_verified_payee(): void
    {
        Http::fake($this->paypalSellerFakes() + [self::ORDERS_URL => Http::response($this->order('CREATED', quantity: 2), 201)]);

        $this->postJson('/api/marketplace/paypal/orders', [
            'product_id' => $this->product->id,
            'quantity' => 2,
            'checkout_attempt_id' => self::ATTEMPT,
            // El navegador no decide precio ni comercio: se ignoran.
            'amount' => '0.01', 'currency' => 'EUR', 'merchant_id' => 'ATTACKER',
        ])->assertCreated()
            ->assertJsonPath('data.order_id', self::ORDER)
            ->assertJsonPath('data.total', '37.00');

        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::ORDERS_URL) {
                return false;
            }
            $unit = $request['purchase_units'][0];

            return $request['intent'] === 'CAPTURE'
                && $request->hasHeader('PayPal-Request-Id', 'lau-mkt-create-u'.$this->buyer->id.'-'.self::ATTEMPT)
                && $unit['amount'] === ['currency_code' => 'USD', 'value' => '37.00', 'breakdown' => ['item_total' => ['currency_code' => 'USD', 'value' => '37.00']]]
                && $unit['items'][0]['unit_amount']['value'] === '18.50'
                && $unit['items'][0]['quantity'] === '2'
                && $unit['payee']['merchant_id'] === self::MERCHANT
                && $unit['custom_id'] === 'lau|u'.$this->buyer->id.'|p'.$this->product->id.'|q2';
        });
    }

    public function test_create_links_the_smart_shopping_decision(): void
    {
        Http::fake($this->paypalSellerFakes() + [self::ORDERS_URL => Http::response($this->order('CREATED'), 201)]);
        $decision = AssistantDecision::create([
            'user_id' => $this->buyer->id, 'query' => 'calculadora', 'outcome' => 'recommendation',
            'model' => 'jev-1.14.0', 'answers' => [], 'candidates' => [],
        ]);

        $this->postJson('/api/marketplace/paypal/orders', [
            'product_id' => $this->product->id, 'checkout_attempt_id' => self::ATTEMPT, 'assistant_decision_id' => $decision->id,
        ])->assertCreated();

        $this->assertSame(self::ORDER, $decision->fresh()->paypal_order_id);
        $this->assertSame($this->product->id, $decision->fresh()->selected_product_id);
    }

    public function test_store_without_lau_verification_cannot_charge(): void
    {
        $this->store->forceFill(['verification_status' => 'submitted'])->save();
        Http::fake();

        $this->postJson('/api/marketplace/paypal/quote', ['product_id' => $this->product->id])
            ->assertStatus(409)->assertJsonPath('code', 'store_not_ready');
        Http::assertNothingSent();
    }

    public function test_store_no_longer_enabled_in_paypal_cannot_charge_and_is_marked_pending(): void
    {
        Http::fake($this->paypalSellerFakes($this->sellerData(['primary_email_confirmed' => false])));

        $this->postJson('/api/marketplace/paypal/orders', ['product_id' => $this->product->id, 'checkout_attempt_id' => self::ATTEMPT])
            ->assertStatus(409)->assertJsonPath('code', 'store_not_ready');

        $this->assertSame('pending', $this->store->fresh()->paypal_status);
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::ORDERS_URL));
    }

    public function test_cannot_buy_more_than_stock_or_own_products(): void
    {
        Http::fake();

        $this->postJson('/api/marketplace/paypal/quote', ['product_id' => $this->product->id, 'quantity' => 9])
            ->assertStatus(409)->assertJsonPath('code', 'out_of_stock');

        Sanctum::actingAs($this->store->user);
        $this->postJson('/api/marketplace/paypal/quote', ['product_id' => $this->product->id])
            ->assertStatus(422)->assertJsonPath('code', 'own_product');
    }

    public function test_create_requires_attempt_id(): void
    {
        Http::fake();

        $this->postJson('/api/marketplace/paypal/orders', ['product_id' => $this->product->id])->assertUnprocessable();
        Http::assertNothingSent();
    }

    // ---------- Captura ----------

    public function test_capture_creates_paid_order_and_discounts_stock(): void
    {
        Http::fake($this->paypalSellerFakes() + [
            self::CAPTURE_URL => Http::response($this->order('COMPLETED'), 201),
            self::ORDER_URL => Http::response($this->order('APPROVED')),
        ]);

        $response = $this->postJson('/api/marketplace/paypal/orders/'.self::ORDER.'/capture')->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.capture_id', self::CAPTURE)
            ->assertJsonPath('data.amount', '18.50')
            ->assertJsonPath('data.store', 'Papelería Campus');
        $this->assertNoSecrets($response->getContent());

        $order = Order::where('paypal_order_id', self::ORDER)->firstOrFail();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('paypal', $order->payment_method);
        $this->assertSame(self::CAPTURE, $order->paypal_capture_id);
        $this->assertSame('18.50', $order->total);
        $this->assertSame($order->code, $response->json('data.order_code'));
        $this->assertSame(4, $this->product->fresh()->stock);

        Http::assertSent(fn (Request $r) => $r->url() === self::CAPTURE_URL && $r->body() === '{}'
            && $r->hasHeader('PayPal-Request-Id', 'lau-mkt-capture-'.self::ORDER));
    }

    // ---------- Carrito: varios productos del mismo comercio en un solo pago ----------

    public function test_cart_of_one_store_is_quoted_created_and_captured_as_one_paid_order(): void
    {
        $cuaderno = $this->productFor($this->store, ['price' => '3.25', 'stock' => 10]);
        $items = [['product_id' => $this->product->id, 'quantity' => 2], ['product_id' => $cuaderno->id, 'quantity' => 3]];
        $customId = 'lau|u'.$this->buyer->id.'|'.$this->product->id.'x2,'.$cuaderno->id.'x3';
        $unit = [
            'reference_id' => 'LAU-S'.$this->store->id,
            'custom_id' => $customId,
            'amount' => ['currency_code' => 'USD', 'value' => '46.75'],
            'payee' => ['merchant_id' => self::MERCHANT],
        ];
        $paypalOrder = fn (string $status) => ['id' => self::ORDER, 'status' => $status, 'purchase_units' => [$status === 'COMPLETED'
            ? $unit + ['payments' => ['captures' => [['id' => self::CAPTURE, 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '46.75']]]]]
            : $unit]];

        Http::fake($this->paypalSellerFakes() + [
            self::CAPTURE_URL => Http::response($paypalOrder('COMPLETED'), 201),
            self::ORDER_URL => Http::response($paypalOrder('APPROVED')),
            self::ORDERS_URL => Http::response($paypalOrder('CREATED'), 201),
        ]);

        $this->postJson('/api/marketplace/paypal/quote', ['items' => $items])->assertOk()
            ->assertJsonPath('data.total', '46.75')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.1.subtotal', '9.75');

        $this->postJson('/api/marketplace/paypal/orders', ['items' => $items, 'checkout_attempt_id' => self::ATTEMPT])
            ->assertCreated()->assertJsonPath('data.total', '46.75');
        Http::assertSent(fn (Request $r) => $r->url() === self::ORDERS_URL
            && $r['purchase_units'][0]['custom_id'] === $customId
            && count($r['purchase_units'][0]['items']) === 2
            && $r['purchase_units'][0]['items'][1]['quantity'] === '3');

        $this->postJson('/api/marketplace/paypal/orders/'.self::ORDER.'/capture')->assertOk()->assertJsonPath('data.amount', '46.75');

        $order = Order::where('paypal_order_id', self::ORDER)->with('items')->firstOrFail();
        $this->assertSame('46.75', $order->total);
        $this->assertCount(2, $order->items);
        $this->assertSame(3, $this->product->fresh()->stock);
        $this->assertSame(7, $cuaderno->fresh()->stock);
    }

    public function test_one_paypal_payment_cannot_mix_stores(): void
    {
        Http::fake($this->paypalSellerFakes());
        $other = $this->verifiedStore(['slug' => 'otra-tienda', 'name' => 'Otra tienda', 'paypal_tracking_id' => 'lau-store-otra']);
        $foreign = $this->productFor($other);

        $this->postJson('/api/marketplace/paypal/quote', ['items' => [
            ['product_id' => $this->product->id, 'quantity' => 1], ['product_id' => $foreign->id, 'quantity' => 1],
        ]])->assertUnprocessable()->assertJsonPath('code', 'mixed_stores');
    }

    public function test_paid_orders_cannot_be_cancelled_like_reservations(): void
    {
        Http::fake($this->paypalSellerFakes() + [
            self::CAPTURE_URL => Http::response($this->order('COMPLETED'), 201),
            self::ORDER_URL => Http::response($this->order('APPROVED')),
        ]);
        $orderId = $this->postJson('/api/marketplace/paypal/orders/'.self::ORDER.'/capture')->assertOk()->json('data.lau_order_id');

        $this->postJson("/api/marketplace/orders/{$orderId}/cancel")->assertUnprocessable();
        $this->assertSame('pending', Order::find($orderId)->status);
        $this->assertSame(4, $this->product->fresh()->stock);
    }

    public function test_capture_without_approval_does_not_charge(): void
    {
        Http::fake($this->paypalSellerFakes() + [self::ORDER_URL => Http::response($this->order('PAYER_ACTION_REQUIRED'))]);

        $this->postJson('/api/marketplace/paypal/orders/'.self::ORDER.'/capture')
            ->assertStatus(409)->assertJsonPath('code', 'not_approved');

        Http::assertNotSent(fn (Request $r) => $r->url() === self::CAPTURE_URL);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_capture_rejects_unexpected_amount_or_currency(): void
    {
        Http::fake($this->paypalSellerFakes() + [self::ORDER_URL => Http::response($this->order('APPROVED', amount: '1.00'))]);
        $this->postJson('/api/marketplace/paypal/orders/'.self::ORDER.'/capture')
            ->assertStatus(409)->assertJsonPath('code', 'amount_mismatch');

        Http::fake($this->paypalSellerFakes() + [self::ORDER_URL => Http::response($this->order('APPROVED', currency: 'EUR'))]);
        $this->postJson('/api/marketplace/paypal/orders/'.self::ORDER.'/capture')
            ->assertStatus(409)->assertJsonPath('code', 'amount_mismatch');

        Http::assertNotSent(fn (Request $r) => $r->url() === self::CAPTURE_URL);
    }

    public function test_capture_rejects_order_for_another_merchant(): void
    {
        Http::fake($this->paypalSellerFakes() + [self::ORDER_URL => Http::response($this->order('APPROVED', payee: 'OTHERMERCHANT1'))]);

        $this->postJson('/api/marketplace/paypal/orders/'.self::ORDER.'/capture')
            ->assertStatus(409)->assertJsonPath('code', 'seller_mismatch');
    }

    public function test_capture_hides_orders_created_by_another_buyer(): void
    {
        Http::fake($this->paypalSellerFakes() + [self::ORDER_URL => Http::response($this->order('APPROVED', buyerId: 999999))]);

        $this->postJson('/api/marketplace/paypal/orders/'.self::ORDER.'/capture')
            ->assertNotFound()->assertJsonPath('code', 'order_not_found');
    }

    public function test_duplicate_capture_is_prevented(): void
    {
        Http::fake($this->paypalSellerFakes() + [
            self::CAPTURE_URL => Http::response($this->order('COMPLETED'), 201),
            self::ORDER_URL => Http::response($this->order('APPROVED')),
        ]);

        $this->postJson('/api/marketplace/paypal/orders/'.self::ORDER.'/capture')->assertOk();
        $this->postJson('/api/marketplace/paypal/orders/'.self::ORDER.'/capture')
            ->assertStatus(409)->assertJsonPath('code', 'already_captured');

        $this->assertSame(1, Http::recorded()->filter(fn ($pair) => $pair[0]->url() === self::CAPTURE_URL)->count());
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(4, $this->product->fresh()->stock);
    }

    public function test_paypal_already_captured_issue_is_mapped(): void
    {
        Http::fake($this->paypalSellerFakes() + [
            self::CAPTURE_URL => Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]], 422),
            self::ORDER_URL => Http::response($this->order('APPROVED')),
        ]);

        $this->postJson('/api/marketplace/paypal/orders/'.self::ORDER.'/capture')
            ->assertStatus(409)->assertJsonPath('code', 'already_captured');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_out_of_stock_at_capture_time_does_not_charge(): void
    {
        $this->product->update(['stock' => 0]);
        Http::fake($this->paypalSellerFakes() + [self::ORDER_URL => Http::response($this->order('APPROVED'))]);

        $this->postJson('/api/marketplace/paypal/orders/'.self::ORDER.'/capture')
            ->assertStatus(409)->assertJsonPath('code', 'out_of_stock');
        Http::assertNotSent(fn (Request $r) => $r->url() === self::CAPTURE_URL);
    }

    public function test_pending_capture_is_not_reported_as_paid(): void
    {
        Http::fake($this->paypalSellerFakes() + [
            self::CAPTURE_URL => Http::response($this->order('COMPLETED', captureStatus: 'PENDING'), 201),
            self::ORDER_URL => Http::response($this->order('APPROVED')),
        ]);

        $this->postJson('/api/marketplace/paypal/orders/'.self::ORDER.'/capture')
            ->assertStatus(202)->assertJsonPath('success', false)->assertJsonPath('code', 'capture_pending');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $this->product->fresh()->stock);
    }

    public function test_paypal_errors_are_sanitized_in_response_and_log(): void
    {
        Log::spy();
        Http::fake($this->paypalSellerFakes() + [
            self::CAPTURE_URL => Http::response(['name' => 'INTERNAL_SERVER_ERROR', 'debug_id' => 'srv9', 'message' => 'Bearer '.self::TOKEN], 500),
            self::ORDER_URL => Http::response($this->order('APPROVED')),
        ]);

        $response = $this->postJson('/api/marketplace/paypal/orders/'.self::ORDER.'/capture')->assertStatus(502);

        $response->assertExactJson([
            'success' => false, 'code' => 'paypal_error',
            'message' => 'PayPal no pudo completar la operación. Intentá de nuevo en un momento.',
        ]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains(json_encode($context), 'HTTP 500')
            && ! str_contains(json_encode($context), self::TOKEN) && ! str_contains(json_encode($context), self::SECRET));
    }

    public function test_capture_route_rejects_malformed_order_id(): void
    {
        Http::fake();

        $this->postJson('/api/marketplace/paypal/orders/abc-123/capture')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_store_json_exposes_badges_but_not_paypal_identifiers(): void
    {
        $json = $this->getJson('/api/marketplace/products/'.$this->product->id)->assertOk()->getContent();

        $this->assertStringContainsString('"is_verified":true', $json);
        $this->assertStringContainsString('"accepts_paypal":true', $json);
        $this->assertStringNotContainsString(self::TRACKING, $json);
        $this->assertStringNotContainsString(self::MERCHANT, $json);
    }

    private function order(
        string $status,
        int $quantity = 1,
        ?string $amount = null,
        string $currency = 'USD',
        string $payee = self::MERCHANT,
        ?int $buyerId = null,
        string $captureStatus = 'COMPLETED',
    ): array {
        $amount ??= number_format(18.50 * $quantity, 2, '.', '');
        $unit = [
            'reference_id' => 'LAU-P'.$this->product->id,
            'custom_id' => 'lau|u'.($buyerId ?? $this->buyer->id).'|p'.$this->product->id.'|q'.$quantity,
            'amount' => ['currency_code' => $currency, 'value' => $amount],
            'payee' => ['merchant_id' => $payee],
        ];
        if ($status === 'COMPLETED') {
            $unit['payments'] = ['captures' => [[
                'id' => self::CAPTURE, 'status' => $captureStatus,
                'amount' => ['currency_code' => $currency, 'value' => $amount],
            ]]];
        }

        return ['id' => self::ORDER, 'status' => $status, 'purchase_units' => [$unit]];
    }
}
