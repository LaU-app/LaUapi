<?php

namespace Tests\Feature;

use App\Mail\MerchantApprovedMail;
use App\Models\AssistantDecision;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Models\su_ad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

/** Comercio: solicitud → revisión en el panel → conexión con PayPal → habilitado. */
class MerchantVerificationTest extends TestCase
{
    use MarketplaceFixtures;
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFakeServices();
        $this->withoutVite();
        Storage::fake('local');

        $this->owner = User::factory()->create();
        $this->store = $this->verifiedStore([
            'verification_status' => 'none', 'verified_at' => null,
            'paypal_tracking_id' => null, 'paypal_merchant_id' => null, 'paypal_status' => 'not_connected',
        ], $this->owner);
        Sanctum::actingAs($this->owner);
    }

    private function application(array $overrides = []): array
    {
        return array_merge([
            'legal_name' => 'Papelería Campus Central, S.A. de C.V.',
            'business_type' => 'sociedad',
            'nit' => '0614-010190-101-3',
            'nrc' => '123456-7',
            'legal_representative' => 'Ana María López',
            'contact_email' => 'ventas@papeleriacampus.test',
            'contact_phone' => '+503 2222-3333',
            'address' => 'Calle Arce 123, San Salvador',
            'website' => 'https://papeleriacampus.test',
            'offer_description' => 'Calculadoras científicas, cuadernos, kits de dibujo técnico y material de laboratorio para estudiantes.',
            'accepts_terms' => '1',
            'document' => UploadedFile::fake()->create('tarjeta-nit.pdf', 200, 'application/pdf'),
            'registration_document' => UploadedFile::fake()->create('escritura.pdf', 200, 'application/pdf'),
            'id_document' => UploadedFile::fake()->create('dui.jpg', 120, 'image/jpeg'),
        ], $overrides);
    }

    /** El equipo aprueba la solicitud de PayPal desde el panel de la app. */
    private function approvePayPalRequest(): void
    {
        $gateway = \App\Models\StorePaymentGateway::where('store_id', $this->store->id)->where('gateway', 'paypal')->sole();
        app(\App\Services\Marketplace\MerchantVerificationService::class)->reviewGateway($gateway, true, 'Revisora LaU <revisora@lau.test>');
    }

    private function admin(): su_ad
    {
        return su_ad::firstOrCreate(['email' => 'revisora@lau.test'], [
            'name' => 'Revisora LaU', 'password' => Hash::make('x'),
            'password_verific_modify' => Hash::make('y'), 'is_admin' => true, 'imagen' => 'img/usuario.svg', 'last_login' => now(),
        ]);
    }

    public function test_merchant_submits_a_legal_application_with_a_private_document(): void
    {
        $response = $this->post('/api/marketplace/my/verification', $this->application(), ['Accept' => 'application/json'])->assertCreated();

        $response->assertJsonPath('data.store.verification_status', 'submitted')
            ->assertJsonPath('data.verification.status', 'submitted')
            ->assertJsonPath('data.verification.has_document', true)
            ->assertJsonMissingPath('data.verification.document_path');

        $response->assertJsonPath('data.verification.documents.1.sent', true)
            ->assertJsonPath('data.verification.documents.2.sent', true)
            ->assertJsonMissingPath('data.verification.id_document_path')
            ->assertJsonPath('data.gateways', []);

        $verification = StoreVerification::firstOrFail();
        foreach (['document_path', 'registration_document_path', 'id_document_path'] as $column) {
            Storage::disk('local')->assertExists($verification->{$column});
            $this->assertStringNotContainsString('public', $verification->{$column});
        }
    }

    public function test_application_validates_salvadoran_identifiers_and_every_document(): void
    {
        $this->post('/api/marketplace/my/verification', $this->application([
            'nit' => '123', 'nrc' => '', 'document' => null, 'registration_document' => null, 'id_document' => null, 'accepts_terms' => null,
            // El NRC ya no es opcional para personas naturales en El Salvador.
            'business_type' => 'persona_natural',
        ]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nit', 'nrc', 'document', 'registration_document', 'id_document', 'accepts_terms']);
    }

    public function test_businesses_outside_el_salvador_use_their_own_tax_id(): void
    {
        $this->post('/api/marketplace/my/verification', $this->application([
            'country' => 'GT', 'nit' => '1234567-8', 'nrc' => '',
        ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.verification.country', 'GT')
            ->assertJsonPath('data.verification.country_label', 'Guatemala');
    }

    public function test_a_student_venture_cannot_ask_for_payment_gateways(): void
    {
        // Emprendimiento sin verificación: vende con apartado, sin pasarelas.
        $this->postJson('/api/marketplace/my/payment-gateways', ['gateway' => 'paypal'])
            ->assertUnprocessable()->assertJsonValidationErrors('gateway');
    }

    public function test_paypal_is_an_optional_separate_request(): void
    {
        $this->post('/api/marketplace/my/verification', $this->application(), ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.gateways', []);

        // Se puede pedir mientras LaU revisa los papeles; repetirlo no duplica la solicitud.
        $this->postJson('/api/marketplace/my/payment-gateways', ['gateway' => 'paypal'])->assertCreated()
            ->assertJsonPath('data.gateways.0.gateway', 'paypal')
            ->assertJsonPath('data.gateways.0.status', 'requested');
        $this->postJson('/api/marketplace/my/payment-gateways', ['gateway' => 'paypal'])->assertCreated();

        // Una pasarela que LaU todavía no integra se pide con su nombre.
        $this->postJson('/api/marketplace/my/payment-gateways', ['gateway' => 'otra'])
            ->assertUnprocessable()->assertJsonValidationErrors('gateway_name');
        $this->postJson('/api/marketplace/my/payment-gateways', ['gateway' => 'otra', 'gateway_name' => 'La de mi banco', 'note' => 'La usamos desde 2019.'])
            ->assertCreated();

        $this->assertSame(1, \App\Models\StorePaymentGateway::where('gateway', 'paypal')->count());
        $this->assertSame(2, \App\Models\StorePaymentGateway::count());
    }

    public function test_paypal_can_be_requested_with_the_application(): void
    {
        $this->post('/api/marketplace/my/verification', $this->application(['request_paypal' => '1']), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.gateways.0.gateway', 'paypal')
            ->assertJsonPath('data.gateways.0.status', 'requested');
    }

    public function test_cannot_connect_paypal_before_lau_approves(): void
    {
        Http::fake();
        $this->post('/api/marketplace/my/verification', $this->application(['request_paypal' => '1']), ['Accept' => 'application/json'])->assertCreated();

        $this->postJson('/api/marketplace/my/paypal/connect')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_a_verified_business_without_an_approved_paypal_request_cannot_connect(): void
    {
        Http::fake();
        $this->post('/api/marketplace/my/verification', $this->application(), ['Accept' => 'application/json'])->assertCreated();
        $this->store->forceFill(['verification_status' => 'approved'])->save();

        $this->postJson('/api/marketplace/my/paypal/connect')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_full_flow_admin_approval_paypal_connection_and_enabled_status(): void
    {
        $this->post('/api/marketplace/my/verification', $this->application(), ['Accept' => 'application/json'])->assertCreated();
        $verification = StoreVerification::firstOrFail();

        // Panel de LaU: revisión y aprobación.
        $this->actingAs($this->admin(), 'super')
            ->get(route('su.comercios'))->assertOk()->assertSee('Papelería Campus Central, S.A. de C.V.');
        $this->actingAs($this->admin(), 'super')
            ->get(route('su.comercios.show', $verification))->assertOk()->assertSee('0614-010190-101-3');
        $this->actingAs($this->admin(), 'super')
            ->post(route('su.comercios.approve', $verification), ['notes' => 'Documentos en orden.'])->assertRedirect();

        $this->assertSame('approved', $this->store->fresh()->verification_status);
        $this->assertSame('Revisora LaU <revisora@lau.test>', $verification->fresh()->reviewed_by);

        // Aparte: el negocio pide PayPal y el equipo lo aprueba.
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/marketplace/my/payment-gateways', ['gateway' => 'paypal'])->assertCreated();
        $this->approvePayPalRequest();

        // Comercio: conectar PayPal (Partner Referrals con return_url a la app).
        Http::fake([
            self::HOST.'/v1/oauth2/token' => Http::response(['access_token' => self::TOKEN, 'expires_in' => 100, 'scope' => '']),
            self::HOST.'/v2/customer/partner-referrals' => Http::response(['links' => [
                ['href' => 'https://www.sandbox.paypal.com/bizsignup/partner/entry?referralToken=abc', 'rel' => 'action_url'],
            ]], 201),
        ]);
        Sanctum::actingAs($this->owner);
        $connect = $this->postJson('/api/marketplace/my/paypal/connect')->assertOk()
            ->assertJsonPath('data.action_url', 'https://www.sandbox.paypal.com/bizsignup/partner/entry?referralToken=abc')
            ->assertJsonPath('data.store.paypal_status', 'pending');
        $this->assertNoSecrets($connect->getContent());

        $tracking = $this->store->fresh()->paypal_tracking_id;
        $this->assertMatchesRegularExpression('/^lau-store-'.$this->store->id.'-[a-z0-9]{6}$/', $tracking);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/partner-referrals')
            && $r['tracking_id'] === $tracking
            && $r['partner_config_override']['return_url'] === 'https://lauapp.es/tabs/marketplace/empresa?paypal=retorno');

        // De vuelta en LaU: PayPal confirma el estado (fuente de verdad).
        $partners = self::HOST.'/v1/customer/partners/'.self::PARTNER.'/merchant-integrations';
        Http::fake([
            self::HOST.'/v1/oauth2/token' => Http::response(['access_token' => self::TOKEN, 'expires_in' => 100, 'scope' => '']),
            $partners.'?tracking_id='.$tracking => Http::response(['merchant_id' => self::MERCHANT]),
            $partners.'/'.self::MERCHANT => Http::response($this->sellerData(['tracking_id' => $tracking])),
        ]);
        $this->postJson('/api/marketplace/my/paypal/refresh')->assertOk()
            ->assertJsonPath('data.paypal.state', 'habilitado')
            ->assertJsonPath('data.store.accepts_paypal', true)
            ->assertJsonPath('data.paypal.checks.0.ok', true);

        $this->assertSame(self::MERCHANT, $this->store->fresh()->paypal_merchant_id);
        $this->getJson('/api/marketplace/verified-stores')->assertOk()
            ->assertJsonPath('data.0.name', 'Papelería Campus')
            ->assertJsonPath('data.0.accepts_paypal', true);
    }

    public function test_approval_emails_the_merchant_from_lau(): void
    {
        Mail::fake();
        $this->post('/api/marketplace/my/verification', $this->application(), ['Accept' => 'application/json'])->assertCreated();
        $verification = StoreVerification::firstOrFail();

        $this->actingAs($this->admin(), 'super')
            ->post(route('su.comercios.approve', $verification))
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(MerchantApprovedMail::class, function (MerchantApprovedMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('ventas@papeleriacampus.test')
                && $mail->hasCc($this->owner->email)
                && $mail->hasReplyTo('lauapp.es@gmail.com')
                && str_contains($mail->envelope()->subject, 'Papelería Campus')
                && str_contains($html, 'https://lauapp.es/tabs/marketplace/empresa')
                && str_contains($html, 'Abrir mi panel')
                && ! str_contains($html, 'Conectar PayPal');
        });
    }

    public function test_a_mail_failure_does_not_undo_the_approval(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp caído'));
        $this->post('/api/marketplace/my/verification', $this->application(), ['Accept' => 'application/json'])->assertCreated();
        $verification = StoreVerification::firstOrFail();

        $this->actingAs($this->admin(), 'super')
            ->post(route('su.comercios.approve', $verification))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('approved', $this->store->fresh()->verification_status);
    }

    public function test_rejection_returns_notes_to_the_merchant_and_allows_resubmission(): void
    {
        $this->post('/api/marketplace/my/verification', $this->application(), ['Accept' => 'application/json'])->assertCreated();
        $verification = StoreVerification::firstOrFail();

        $this->actingAs($this->admin(), 'super')
            ->post(route('su.comercios.reject', $verification), ['notes' => 'El NIT no coincide con la escritura.'])->assertRedirect();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/marketplace/my/verification')->assertOk()
            ->assertJsonPath('data.store.verification_status', 'rejected')
            ->assertJsonPath('data.verification.review_notes', 'El NIT no coincide con la escritura.');

        // Reenvío sin volver a subir documentos: se conservan los anteriores.
        $this->post('/api/marketplace/my/verification', $this->application(['document' => null, 'registration_document' => null, 'id_document' => null]), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.verification.status', 'submitted');
    }

    public function test_suspension_removes_the_badge_immediately(): void
    {
        $this->store->forceFill(['verification_status' => 'approved', 'paypal_status' => 'enabled', 'paypal_merchant_id' => self::MERCHANT, 'paypal_tracking_id' => self::TRACKING])->save();
        $verification = new StoreVerification(array_merge(collect($this->application())->except(['document', 'registration_document', 'id_document', 'accepts_terms'])->all(), ['status' => 'approved', 'document_path' => 'x.pdf']));
        $verification->store()->associate($this->store)->save();

        $this->actingAs($this->admin(), 'super')
            ->post(route('su.comercios.suspend', $verification), ['notes' => 'Reportes de productos no entregados.'])->assertRedirect();

        $this->assertFalse($this->store->fresh()->accepts_paypal);
        $this->assertFalse($this->store->fresh()->is_verified);
    }

    public function test_admin_pages_require_the_admin_guard(): void
    {
        $this->get(route('su.comercios'))->assertRedirect();
        $this->get(route('su.decisiones'))->assertRedirect();
    }

    public function test_admin_decision_log_shows_jev_probabilities(): void
    {
        AssistantDecision::create([
            'user_id' => $this->owner->id, 'query' => 'Necesito una calculadora para Cálculo', 'outcome' => 'recommendation',
            'model' => 'jev-1.14.0', 'max_budget' => '25.00', 'budget_source' => 'field',
            'answers' => ['understand' => ['purchase_intent' => ['type' => 'noul', 'noul' => 0.96], 'item_named' => ['type' => 'noul', 'noul' => 0.9]]],
            'candidates' => [['product_id' => 1, 'price' => '18.50', 'match' => 0.95, 'fit' => 2.8, 'academic' => 1.9, 'within_budget' => true]],
        ]);

        $this->actingAs($this->admin(), 'super')->get(route('su.decisiones'))->assertOk()
            ->assertSee('Necesito una calculadora para Cálculo')
            ->assertSee('96 %')
            ->assertSee('jev-1.14.0');
    }
}
