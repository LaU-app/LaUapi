<?php

namespace App\Services\PayPal;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de PayPal para LaU Marketplace: vinculación de comercios (Partner Referrals),
 * estado del vendedor y Orders v2.
 * Flujo: https://developer.paypal.com/platforms/seller-onboarding/before-payment/
 *
 * El entorno lo decide PAYPAL_MODE (sandbox por defecto); los hosts son fijos por entorno.
 * No registra ni devuelve tokens, credenciales, encabezados ni respuestas completas.
 */
class PayPalClient
{
    public const HOSTS = [
        'sandbox' => 'https://api-m.sandbox.paypal.com',
        'live' => 'https://api-m.paypal.com',
    ];

    // Features que la plataforma pide a la tienda; deben estar activas en la app Platform.
    public const FEATURES = ['PAYMENT', 'REFUND'];

    public const PRODUCTS = ['EXPRESS_CHECKOUT'];

    // Permisos que la tienda debe otorgar a esta plataforma por PAYMENT y REFUND.
    public const REQUIRED_SELLER_SCOPES = [
        'https://uri.paypal.com/services/payments/realtimepayment',
        'https://uri.paypal.com/services/payments/payment/authcapture',
        'https://uri.paypal.com/services/payments/refund',
    ];

    // Scopes que necesita el token de la plataforma para cada paso.
    public const SCOPE_PARTNER_REFERRALS = 'https://uri.paypal.com/services/customer/partner-referrals/readwrite';

    public const SCOPE_SELLER_STATUS = 'https://uri.paypal.com/services/customer/partner';

    private const TIMEOUT_SECONDS = 20;

    private array $config;

    /** Token en memoria solo durante este proceso; nunca se guarda ni se imprime. */
    private ?array $token = null;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('services.paypal', []);
    }

    /** Entorno activo: cualquier valor distinto de "live" usa Sandbox. */
    public function mode(): string
    {
        return ($this->config['mode'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
    }

    public function host(): string
    {
        return self::HOSTS[$this->mode()];
    }

    public static function isValidTrackingId(string $trackingId): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,127}$/', $trackingId) === 1;
    }

    /**
     * Paso 1: OAuth client_credentials. Devuelve solo metadatos no sensibles del token.
     *
     * @return array{scopes: list<string>, expires_in: int}
     */
    public function checkCredentials(): array
    {
        $token = $this->token();

        return ['scopes' => $token['scopes'], 'expires_in' => $token['expires_in']];
    }

    public function hasBnCode(): bool
    {
        return filled($this->config['bn_code'] ?? null);
    }

    public function hasPlatformAccountId(): bool
    {
        return filled($this->config['platform_account_id'] ?? null);
    }

    /**
     * Paso 2: crea un Partner Referral y devuelve el action_url (de un solo uso) para la tienda.
     */
    public function createSellerReferral(string $trackingId, ?string $returnUrl = null): string
    {
        $this->assertTrackingId($trackingId);
        $step = 'Partner Referrals';

        // Al terminar en PayPal, el comercio vuelve a LaU; sin return_url aterriza en su panel de PayPal.
        $override = $returnUrl ? ['partner_config_override' => ['return_url' => $returnUrl]] : [];

        $response = $this->send($step, fn () => $this->api()->post($this->host().'/v2/customer/partner-referrals', $override + [
            'tracking_id' => $trackingId,
            'operations' => [[
                'operation' => 'API_INTEGRATION',
                'api_integration_preference' => [
                    'rest_api_integration' => [
                        'integration_method' => 'PAYPAL',
                        'integration_type' => 'THIRD_PARTY',
                        'third_party_details' => ['features' => self::FEATURES],
                    ],
                ],
            ]],
            'products' => self::PRODUCTS,
            'legal_consents' => [['type' => 'SHARE_DATA_CONSENT', 'granted' => true]],
        ]));

        if ($response->failed()) {
            throw PayPalException::fromResponse($step, $response, 'referral');
        }

        $actionUrl = collect((array) $response->json('links', []))
            ->first(fn ($link) => is_array($link) && ($link['rel'] ?? null) === 'action_url')['href'] ?? null;

        if (! is_string($actionUrl) || ! self::isPayPalUrl($actionUrl)) {
            throw PayPalException::unexpected($step, 'no trae un enlace action_url de PayPal');
        }

        return $actionUrl;
    }

    /**
     * Paso 3: consulta a PayPal el estado de la tienda vinculada con ese tracking_id.
     */
    public function sellerStatus(string $trackingId): SellerStatus
    {
        $this->assertTrackingId($trackingId);
        $clientId = $this->requireConfig('client_id');
        $partnerId = $this->requireConfig('platform_account_id');
        $base = $this->host().'/v1/customer/partners/'.rawurlencode($partnerId).'/merchant-integrations';

        $step = 'Búsqueda por tracking_id';
        $lookup = $this->send($step, fn () => $this->api()->get($base, ['tracking_id' => $trackingId]));

        if ($lookup->status() === 404) {
            return SellerStatus::notLinkedYet($trackingId);
        }
        if ($lookup->failed()) {
            throw PayPalException::fromResponse($step, $lookup, 'seller');
        }

        $merchantId = $lookup->json('merchant_id');
        if (! is_string($merchantId) || $merchantId === '') {
            return SellerStatus::notLinkedYet($trackingId);
        }
        if (preg_match('/^[A-Za-z0-9]{1,64}$/', $merchantId) !== 1) {
            throw PayPalException::unexpected($step, 'merchant_id con formato inválido');
        }

        $step = 'Estado del vendedor';
        $status = $this->send($step, fn () => $this->api()->get($base.'/'.$merchantId));

        if ($status->failed()) {
            throw PayPalException::fromResponse($step, $status, 'seller');
        }

        $data = $status->json();
        if (! is_array($data)) {
            throw PayPalException::unexpected($step, 'el cuerpo no es JSON');
        }

        return SellerStatus::fromPayPal($trackingId, $merchantId, $data, $clientId, self::REQUIRED_SELLER_SCOPES);
    }

    public static function isValidOrderId(string $orderId): bool
    {
        return preg_match('/^[A-Z0-9]{8,64}$/', $orderId) === 1;
    }

    /**
     * Orders v2: crea una orden. $requestId es la clave de idempotencia (PayPal-Request-Id).
     */
    public function createOrder(array $payload, string $requestId): array
    {
        $step = 'Crear orden';

        $response = $this->send($step, fn () => $this->api()
            ->withHeaders(['PayPal-Request-Id' => $requestId, 'Prefer' => 'return=representation'])
            ->post($this->host().'/v2/checkout/orders', $payload));

        return $this->orderJson($step, $response);
    }

    /** Orders v2: consulta una orden (fuente de verdad antes de capturar). */
    public function showOrder(string $orderId): array
    {
        $this->assertOrderId($orderId);
        $step = 'Consultar orden';

        $response = $this->send($step, fn () => $this->api()->get($this->host().'/v2/checkout/orders/'.$orderId));

        return $this->orderJson($step, $response);
    }

    /** Orders v2: captura una orden aprobada. $requestId es la clave de idempotencia de la captura. */
    public function captureOrder(string $orderId, string $requestId): array
    {
        $this->assertOrderId($orderId);
        $step = 'Capturar orden';

        $response = $this->send($step, fn () => $this->api()
            ->withHeaders(['PayPal-Request-Id' => $requestId, 'Prefer' => 'return=representation'])
            ->withBody('{}', 'application/json')
            ->post($this->host().'/v2/checkout/orders/'.$orderId.'/capture'));

        return $this->orderJson($step, $response);
    }

    private function orderJson(string $step, Response $response): array
    {
        if ($response->failed()) {
            throw PayPalException::fromResponse($step, $response, 'orders');
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw PayPalException::unexpected($step, 'el cuerpo no es JSON');
        }

        return $data;
    }

    private function assertOrderId(string $orderId): void
    {
        if (! self::isValidOrderId($orderId)) {
            throw new PayPalException('ID de orden de PayPal inválido.', 'Usa el ID que devolvió la creación de la orden (letras mayúsculas y números).');
        }
    }

    private function token(): array
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $clientId = $this->requireConfig('client_id');
        $secret = $this->requireConfig('client_secret');
        $step = 'OAuth client_credentials';

        $response = $this->send($step, fn () => Http::asForm()
            ->acceptJson()
            ->withBasicAuth($clientId, $secret)
            ->timeout(self::TIMEOUT_SECONDS)
            ->post($this->host().'/v1/oauth2/token', ['grant_type' => 'client_credentials']));

        if ($response->failed()) {
            throw PayPalException::fromResponse($step, $response, 'oauth');
        }

        $accessToken = $response->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            throw PayPalException::unexpected($step, 'no trae access_token');
        }

        $scope = $response->json('scope');

        return $this->token = [
            'access_token' => $accessToken,
            'scopes' => is_string($scope) ? array_values(array_filter(explode(' ', $scope))) : [],
            'expires_in' => (int) $response->json('expires_in', 0),
        ];
    }

    private function api(): PendingRequest
    {
        $request = Http::withToken($this->token()['access_token'])
            ->acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT_SECONDS);

        if ($this->hasBnCode()) {
            $request = $request->withHeaders(['PayPal-Partner-Attribution-Id' => (string) $this->config['bn_code']]);
        }

        return $request;
    }

    /** Ejecuta la petición sin dejar escapar excepciones del cliente HTTP (que podrían incluir la URL o detalles). */
    private function send(string $step, Closure $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw PayPalException::connection($step);
        }
    }

    private function requireConfig(string $key): string
    {
        $value = $this->config[$key] ?? null;
        if (filled($value)) {
            return trim((string) $value);
        }

        [$envVar, $howToGet] = match ($key) {
            'client_id' => ['PAYPAL_CLIENT_ID', 'Cópialo de developer.paypal.com > Apps & Credentials (entorno de PAYPAL_MODE) > tu app Platform.'],
            'client_secret' => ['PAYPAL_CLIENT_SECRET', 'Cópialo de developer.paypal.com > Apps & Credentials (entorno de PAYPAL_MODE) > tu app Platform > Secret.'],
            'platform_account_id' => ['PAYPAL_PLATFORM_ACCOUNT_ID', 'Es el Account ID (Merchant ID) de la cuenta Business dueña de la app: en Sandbox, developer.paypal.com > Testing Tools > Sandbox Accounts > esa cuenta > Account ID.'],
            default => [strtoupper($key), ''],
        };

        throw PayPalException::missingConfig($envVar, $howToGet);
    }

    private function assertTrackingId(string $trackingId): void
    {
        if (! self::isValidTrackingId($trackingId)) {
            throw new PayPalException(
                'tracking_id inválido.',
                'Usa de 1 a 127 caracteres: letras, números, punto, guion o guion bajo (por ejemplo lau-store-12-k3x9).'
            );
        }
    }

    private static function isPayPalUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');

        return ($parts['scheme'] ?? null) === 'https'
            && ($host === 'paypal.com' || str_ends_with($host, '.paypal.com'));
    }
}
