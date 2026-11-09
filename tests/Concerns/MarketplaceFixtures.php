<?php

namespace Tests\Concerns;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\User;
use App\Services\PayPal\PayPalClient;
use Illuminate\Support\Facades\Http;

/** Datos ficticios compartidos: comercio verificado con PayPal, productos y respuestas de PayPal simuladas. */
trait MarketplaceFixtures
{
    protected const CLIENT_ID = 'fake-client-id-AAA';

    protected const SECRET = 'fake-secret-SSS';

    protected const TOKEN = 'fake-access-token-TTT';

    protected const PARTNER = 'FAKEPARTNER123';

    protected const MERCHANT = 'FAKEMERCHANT9';

    protected const TRACKING = 'lau-store-1-abc123';

    protected const HOST = 'https://api-m.sandbox.paypal.com';

    protected function configureFakeServices(): void
    {
        config([
            'services.paypal' => [
                'mode' => 'sandbox',
                'client_id' => self::CLIENT_ID,
                'client_secret' => self::SECRET,
                'platform_account_id' => self::PARTNER,
                'bn_code' => null,
            ],
            'services.typesafe' => [
                'api_key' => 'fake-typesafe-key-KKK',
                'model' => 'jev-latest',
                'endpoint' => 'https://api.typesafe.ai/v1/systemone',
                'timeout' => 5,
            ],
            'services.frontend_url' => 'https://lauapp.es',
        ]);

        Http::preventStrayRequests();
    }

    protected function category(string $slug = 'electronica', string $name = 'Electrónica'): ProductCategory
    {
        return ProductCategory::firstOrCreate(['slug' => $slug], [
            'name' => $name, 'icon' => 'hardware-chip-outline', 'description' => 'Calculadoras y equipo electrónico.', 'sort_order' => 1,
        ]);
    }

    protected function verifiedStore(array $overrides = [], ?User $owner = null): Store
    {
        $owner ??= User::factory()->create();
        $store = new Store(['name' => 'Papelería Campus', 'slug' => Store::uniqueSlug('Papelería Campus'), 'is_active' => true]);
        $store->user()->associate($owner);
        $store->forceFill(array_merge([
            'verification_status' => 'approved',
            'verified_at' => now(),
            'paypal_tracking_id' => self::TRACKING,
            'paypal_merchant_id' => self::MERCHANT,
            'paypal_status' => 'enabled',
        ], $overrides))->save();

        return $store;
    }

    protected function productFor(Store $store, array $overrides = []): Product
    {
        $product = new Product(array_merge([
            'store_id' => $store->id,
            'category_id' => $this->category()->id,
            'name' => 'Calculadora científica 417 funciones',
            'description' => 'Ideal para Cálculo I y Física. Pantalla de dos líneas.',
            'price' => '18.50',
            'stock' => 5,
            'status' => 'active',
            'delivery_point' => 'Biblioteca central',
        ], $overrides));
        $product->user_id = $store->user_id;
        $product->save();

        return $product;
    }

    /** Respuestas de PayPal para OAuth y estado del vendedor; se combinan con las de Orders. */
    protected function paypalSellerFakes(?array $seller = null): array
    {
        $partners = self::HOST.'/v1/customer/partners/'.self::PARTNER.'/merchant-integrations';

        return [
            self::HOST.'/v1/oauth2/token' => Http::response([
                'access_token' => self::TOKEN, 'expires_in' => 32400,
                'scope' => PayPalClient::SCOPE_PARTNER_REFERRALS.' '.PayPalClient::SCOPE_SELLER_STATUS,
            ]),
            $partners.'?tracking_id='.self::TRACKING => Http::response(['merchant_id' => self::MERCHANT, 'tracking_id' => self::TRACKING]),
            $partners.'/'.self::MERCHANT => Http::response($seller ?? $this->sellerData()),
        ];
    }

    protected function sellerData(array $overrides = []): array
    {
        return array_merge([
            'merchant_id' => self::MERCHANT,
            'tracking_id' => self::TRACKING,
            'payments_receivable' => true,
            'primary_email_confirmed' => true,
            'oauth_integrations' => [[
                'integration_type' => 'OAUTH_THIRD_PARTY',
                'oauth_third_party' => [['partner_client_id' => self::CLIENT_ID, 'scopes' => PayPalClient::REQUIRED_SELLER_SCOPES]],
            ]],
        ], $overrides);
    }

    protected function assertNoSecrets(string $output): void
    {
        foreach ([self::CLIENT_ID, self::SECRET, self::TOKEN, 'fake-typesafe-key-KKK'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }
}
