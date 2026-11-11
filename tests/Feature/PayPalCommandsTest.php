<?php

namespace Tests\Feature;

use App\Console\Commands\PayPalSellerStatus;
use App\Services\PayPal\PayPalClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Todo es simulado con Http::fake y credenciales ficticias: ninguna prueba habla con PayPal.
 */
class PayPalCommandsTest extends TestCase
{
    private const CLIENT_ID = 'fake-client-id-AAA';

    private const SECRET = 'fake-secret-SSS';

    private const TOKEN = 'fake-access-token-TTT';

    private const PARTNER = 'FAKEPARTNER123';

    private const MERCHANT = 'FAKEMERCHANT9';

    private const TRACKING = 'lau-sandbox-tienda-01';

    private const ACTION_URL = 'https://www.sandbox.paypal.com/bizsignup/partner/entry?referralToken=fake-referral';

    private const OAUTH_URL = 'https://api-m.sandbox.paypal.com/v1/oauth2/token';

    private const LOOKUP_URL = 'https://api-m.sandbox.paypal.com/v1/customer/partners/'.self::PARTNER.'/merchant-integrations?tracking_id='.self::TRACKING;

    private const STATUS_URL = 'https://api-m.sandbox.paypal.com/v1/customer/partners/'.self::PARTNER.'/merchant-integrations/'.self::MERCHANT;

    protected function setUp(): void
    {
        parent::setUp();

        // Nunca usar las credenciales reales del .env en pruebas.
        config(['services.paypal' => [
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::SECRET,
            'platform_account_id' => self::PARTNER,
            'bn_code' => 'FAKE_BN_CODE',
        ]]);

        Http::preventStrayRequests();
    }

    // ---------- Paso 1: OAuth ----------

    public function test_check_succeeds_and_reports_scopes_without_leaking_secrets(): void
    {
        Http::fake([self::OAUTH_URL => $this->tokenResponse()]);

        [$code, $output] = $this->runCommand('paypal:check');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('OK: OAuth client_credentials aceptado', $output);
        $this->assertStringContainsString('[OK] '.PayPalClient::SCOPE_PARTNER_REFERRALS, $output);
        $this->assertStringContainsString('[OK] '.PayPalClient::SCOPE_SELLER_STATUS, $output);
        $this->assertNoSecrets($output);

        Http::assertSent(fn (Request $request) => $request->url() === self::OAUTH_URL
            && $request->method() === 'POST'
            && $request['grant_type'] === 'client_credentials'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode(self::CLIENT_ID.':'.self::SECRET)));
    }

    public function test_check_warns_when_platform_scopes_are_missing(): void
    {
        Http::fake([self::OAUTH_URL => $this->tokenResponse(scope: 'openid')]);

        [$code, $output] = $this->runCommand('paypal:check');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('[FALTA] '.PayPalClient::SCOPE_PARTNER_REFERRALS, $output);
        $this->assertStringContainsString('tipo Platform', $output);
    }

    public function test_check_reports_rejected_credentials_sanitized(): void
    {
        Http::fake([self::OAUTH_URL => Http::response([
            'error' => 'invalid_client',
            'error_description' => 'Client Authentication failed',
        ], 401, ['PayPal-Debug-Id' => 'dbg123'])]);

        [$code, $output] = $this->runCommand('paypal:check');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('HTTP 401 invalid_client', $output);
        $this->assertStringContainsString('debug_id: dbg123', $output);
        $this->assertStringContainsString('PAYPAL_CLIENT_SECRET', $output);
        $this->assertNoSecrets($output);
    }

    public function test_check_names_the_missing_variable_without_calling_paypal(): void
    {
        config(['services.paypal.client_secret' => '']);
        Http::fake();

        [$code, $output] = $this->runCommand('paypal:check');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Falta PAYPAL_CLIENT_SECRET', $output);
        Http::assertNothingSent();
    }

    public function test_connection_failure_is_reported_without_details(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 6 '.self::SECRET)]);

        [$code, $output] = $this->runCommand('paypal:check');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('no se pudo conectar con PayPal', $output);
        $this->assertNoSecrets($output);
    }

    // ---------- Paso 2: enlace Partner Referrals ----------

    public function test_onboard_link_prints_action_url_and_sends_expected_referral(): void
    {
        Http::fake([
            self::OAUTH_URL => $this->tokenResponse(),
            'https://api-m.sandbox.paypal.com/v2/customer/partner-referrals' => Http::response(['links' => [
                ['href' => 'https://api-m.sandbox.paypal.com/v2/customer/partner-referrals/fake-id', 'rel' => 'self', 'method' => 'GET'],
                ['href' => self::ACTION_URL, 'rel' => 'action_url', 'method' => 'GET'],
            ]], 201),
        ]);

        [$code, $output] = $this->runCommand('paypal:onboard-link', ['tracking_id' => self::TRACKING]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString(self::ACTION_URL, $output);
        $this->assertStringContainsString('php artisan paypal:seller-status '.self::TRACKING, $output);
        $this->assertNoSecrets($output);

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://api-m.sandbox.paypal.com/v2/customer/partner-referrals') {
                return false;
            }
            $integration = $request['operations'][0]['api_integration_preference']['rest_api_integration'];

            return $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
                && $request->hasHeader('PayPal-Partner-Attribution-Id', 'FAKE_BN_CODE')
                && $request['tracking_id'] === self::TRACKING
                && $request['operations'][0]['operation'] === 'API_INTEGRATION'
                && $integration['integration_type'] === 'THIRD_PARTY'
                && $integration['third_party_details']['features'] === ['PAYMENT', 'REFUND']
                && $request['products'] === ['EXPRESS_CHECKOUT']
                && $request['legal_consents'] === [['type' => 'SHARE_DATA_CONSENT', 'granted' => true]];
        });
    }

    public function test_onboard_link_omits_bn_header_when_not_configured(): void
    {
        config(['services.paypal.bn_code' => null]);
        Http::fake([
            self::OAUTH_URL => $this->tokenResponse(),
            'https://api-m.sandbox.paypal.com/v2/customer/partner-referrals' => Http::response(['links' => [
                ['href' => self::ACTION_URL, 'rel' => 'action_url'],
            ]], 201),
        ]);

        [$code] = $this->runCommand('paypal:onboard-link', ['tracking_id' => self::TRACKING]);

        $this->assertSame(0, $code);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/partner-referrals')
            && ! $request->hasHeader('PayPal-Partner-Attribution-Id'));
    }

    public function test_onboard_link_explains_permission_rejection(): void
    {
        Http::fake([
            self::OAUTH_URL => $this->tokenResponse(),
            'https://api-m.sandbox.paypal.com/v2/customer/partner-referrals' => Http::response([
                'name' => 'NOT_AUTHORIZED',
                'message' => 'Authorization failed due to insufficient permissions.',
                'debug_id' => 'abc999',
                'details' => [['issue' => 'PERMISSION_DENIED']],
            ], 403),
        ]);

        [$code, $output] = $this->runCommand('paypal:onboard-link', ['tracking_id' => self::TRACKING]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('HTTP 403 NOT_AUTHORIZED', $output);
        $this->assertStringContainsString('Issues: PERMISSION_DENIED', $output);
        $this->assertStringContainsString('paypal:check', $output);
        $this->assertNoSecrets($output);
    }

    public function test_onboard_link_rejects_non_paypal_action_url(): void
    {
        Http::fake([
            self::OAUTH_URL => $this->tokenResponse(),
            'https://api-m.sandbox.paypal.com/v2/customer/partner-referrals' => Http::response(['links' => [
                ['href' => 'https://evil.example.com/paypal.com', 'rel' => 'action_url'],
            ]], 201),
        ]);

        [$code, $output] = $this->runCommand('paypal:onboard-link', ['tracking_id' => self::TRACKING]);

        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('evil.example.com', $output);
    }

    public function test_onboard_link_rejects_invalid_tracking_id_before_calling_paypal(): void
    {
        Http::fake();

        [$code, $output] = $this->runCommand('paypal:onboard-link', ['tracking_id' => 'tienda con espacios']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('tracking_id inválido', $output);
        Http::assertNothingSent();
    }

    // ---------- Paso 3: estado del vendedor ----------

    public function test_status_is_pending_when_paypal_has_no_seller_for_tracking_id(): void
    {
        Http::fake([
            self::OAUTH_URL => $this->tokenResponse(),
            self::LOOKUP_URL => Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404),
        ]);

        [$code, $output] = $this->runCommand('paypal:seller-status', ['tracking_id' => self::TRACKING]);

        $this->assertSame(PayPalSellerStatus::PENDING, $code);
        $this->assertStringContainsString('Estado: PENDIENTE', $output);
        $this->assertStringContainsString('(todavía no asignado)', $output);
    }

    public function test_status_is_enabled_only_when_paypal_confirms_everything(): void
    {
        $this->fakeSeller($this->sellerData());

        [$code, $output] = $this->runCommand('paypal:seller-status', ['tracking_id' => self::TRACKING]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Merchant ID en PayPal: '.self::MERCHANT, $output);
        $this->assertStringContainsString('Estado: HABILITADO', $output);
        $this->assertStringNotContainsString('[NO]', $output);
        $this->assertNoSecrets($output);
        $this->assertStringNotContainsString('seller@example.com', $output);

        Http::assertSent(fn (Request $request) => $request->url() === self::LOOKUP_URL
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        Http::assertSent(fn (Request $request) => $request->url() === self::STATUS_URL);
    }

    public function test_status_is_pending_when_email_not_confirmed(): void
    {
        $this->fakeSeller($this->sellerData(['primary_email_confirmed' => false]));

        [$code, $output] = $this->runCommand('paypal:seller-status', ['tracking_id' => self::TRACKING]);

        $this->assertSame(PayPalSellerStatus::PENDING, $code);
        $this->assertStringContainsString('[NO] Correo principal confirmado', $output);
        $this->assertStringContainsString('Estado: PENDIENTE', $output);
    }

    public function test_status_is_pending_when_payments_not_receivable(): void
    {
        $this->fakeSeller($this->sellerData(['payments_receivable' => false]));

        [$code, $output] = $this->runCommand('paypal:seller-status', ['tracking_id' => self::TRACKING]);

        $this->assertSame(PayPalSellerStatus::PENDING, $code);
        $this->assertStringContainsString('[NO] PayPal confirma que puede recibir pagos', $output);
    }

    public function test_status_ignores_permissions_granted_to_another_platform(): void
    {
        $data = $this->sellerData();
        $data['oauth_integrations'][0]['oauth_third_party'][0]['partner_client_id'] = 'otra-plataforma';
        $this->fakeSeller($data);

        [$code, $output] = $this->runCommand('paypal:seller-status', ['tracking_id' => self::TRACKING]);

        $this->assertSame(PayPalSellerStatus::PENDING, $code);
        $this->assertStringContainsString('no ha otorgado permisos a esta plataforma', $output);
    }

    public function test_status_lists_missing_scopes(): void
    {
        $data = $this->sellerData();
        $data['oauth_integrations'][0]['oauth_third_party'][0]['scopes'] = [
            'https://uri.paypal.com/services/payments/realtimepayment',
            'https://uri.paypal.com/services/payments/payment/authcapture',
        ];
        $this->fakeSeller($data);

        [$code, $output] = $this->runCommand('paypal:seller-status', ['tracking_id' => self::TRACKING]);

        $this->assertSame(PayPalSellerStatus::PENDING, $code);
        $this->assertStringContainsString('Faltan permisos: https://uri.paypal.com/services/payments/refund', $output);
    }

    public function test_status_is_error_when_paypal_denies_the_seller(): void
    {
        $this->fakeSeller($this->sellerData(['products' => [
            ['name' => 'PPCP_STANDARD', 'vetting_status' => 'DENIED'],
        ]]));

        [$code, $output] = $this->runCommand('paypal:seller-status', ['tracking_id' => self::TRACKING]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Estado: ERROR', $output);
        $this->assertStringContainsString('PPCP_STANDARD', $output);
    }

    public function test_status_is_error_when_paypal_fails(): void
    {
        Http::fake([
            self::OAUTH_URL => $this->tokenResponse(),
            self::LOOKUP_URL => Http::response(['merchant_id' => self::MERCHANT, 'tracking_id' => self::TRACKING]),
            self::STATUS_URL => Http::response(['name' => 'INTERNAL_SERVER_ERROR', 'debug_id' => 'srv1'], 500),
        ]);

        [$code, $output] = $this->runCommand('paypal:seller-status', ['tracking_id' => self::TRACKING]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('HTTP 500 INTERNAL_SERVER_ERROR', $output);
        $this->assertStringContainsString('Reintenta', $output);
    }

    public function test_status_explains_wrong_platform_account(): void
    {
        Http::fake([
            self::OAUTH_URL => $this->tokenResponse(),
            self::LOOKUP_URL => Http::response(['name' => 'PERMISSION_DENIED', 'debug_id' => 'perm1'], 403),
        ]);

        [$code, $output] = $this->runCommand('paypal:seller-status', ['tracking_id' => self::TRACKING]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('PAYPAL_PLATFORM_ACCOUNT_ID', $output);
        $this->assertStringContainsString('Account ID', $output);
    }

    public function test_status_requires_platform_account_id(): void
    {
        config(['services.paypal.platform_account_id' => null]);
        Http::fake();

        [$code, $output] = $this->runCommand('paypal:seller-status', ['tracking_id' => self::TRACKING]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Falta PAYPAL_PLATFORM_ACCOUNT_ID', $output);
        $this->assertStringContainsString('Sandbox Accounts', $output);
        Http::assertNothingSent();
    }

    // ---------- Utilidades ----------

    /** @return array{0:int, 1:string} */
    private function runCommand(string $command, array $arguments = []): array
    {
        $code = Artisan::call($command, $arguments);

        return [$code, Artisan::output()];
    }

    private function tokenResponse(string $scope = PayPalClient::SCOPE_PARTNER_REFERRALS.' '.PayPalClient::SCOPE_SELLER_STATUS.' openid')
    {
        return Http::response([
            'scope' => $scope,
            'access_token' => self::TOKEN,
            'token_type' => 'Bearer',
            'app_id' => 'APP-FAKE',
            'expires_in' => 32400,
            'nonce' => 'fake-nonce',
        ]);
    }

    private function fakeSeller(array $statusData): void
    {
        Http::fake([
            self::OAUTH_URL => $this->tokenResponse(),
            self::LOOKUP_URL => Http::response(['merchant_id' => self::MERCHANT, 'tracking_id' => self::TRACKING]),
            self::STATUS_URL => Http::response($statusData),
        ]);
    }

    private function sellerData(array $overrides = []): array
    {
        return array_merge([
            'merchant_id' => self::MERCHANT,
            'tracking_id' => self::TRACKING,
            'products' => [
                ['name' => 'EXPRESS_CHECKOUT', 'status' => 'ACTIVE'],
                ['name' => 'PPCP_STANDARD', 'vetting_status' => 'SUBSCRIBED'],
            ],
            'payments_receivable' => true,
            'primary_email' => 'seller@example.com',
            'primary_email_confirmed' => true,
            'oauth_integrations' => [[
                'integration_type' => 'OAUTH_THIRD_PARTY',
                'integration_method' => 'PAYPAL',
                'oauth_third_party' => [[
                    'partner_client_id' => self::CLIENT_ID,
                    'merchant_client_id' => 'fake-merchant-client',
                    'scopes' => [
                        'https://uri.paypal.com/services/payments/realtimepayment',
                        'https://uri.paypal.com/services/reporting/search/read',
                        'https://uri.paypal.com/services/payments/refund',
                        'https://uri.paypal.com/services/payments/payment/authcapture',
                    ],
                ]],
            ]],
        ], $overrides);
    }

    private function assertNoSecrets(string $output): void
    {
        foreach ([self::CLIENT_ID, self::SECRET, self::TOKEN, 'FAKE_BN_CODE'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }
}
