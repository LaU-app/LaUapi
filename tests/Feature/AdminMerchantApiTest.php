<?php

namespace Tests\Feature;

use App\Mail\MerchantApprovedMail;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Models\su_ad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

/** Panel del equipo de LaU dentro de la app: acceso aislado y revisión de comercios. */
class AdminMerchantApiTest extends TestCase
{
    use MarketplaceFixtures;
    use RefreshDatabase;

    private const PASSWORD = 'clave-larga-de-prueba-2026';

    private su_ad $admin;

    private Store $store;

    private StoreVerification $verification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFakeServices();
        Storage::fake('local');

        $this->admin = su_ad::create([
            'name' => 'Revisora LaU', 'email' => 'revisora@lau.test', 'password' => Hash::make(self::PASSWORD),
            'password_verific_modify' => Hash::make('otra-clave-larga-2026'), 'is_admin' => true,
            'imagen' => 'img/usuario.svg', 'last_login' => now(),
        ]);

        $this->store = $this->verifiedStore(['verification_status' => 'submitted', 'verified_at' => null, 'paypal_status' => 'not_connected']);
        Storage::disk('local')->put('verificaciones-comercios/doc.pdf', '%PDF-1.4 prueba');
        $this->verification = $this->store->verification()->create([
            'legal_name' => 'Papelería Campus Central, S.A. de C.V.', 'business_type' => 'sociedad',
            'nit' => '0614-010190-101-3', 'nrc' => '123456-7', 'legal_representative' => 'Ana María López',
            'contact_email' => 'ventas@papeleriacampus.test', 'contact_phone' => '+503 2222-3333',
            'address' => 'Calle Arce 123, San Salvador', 'offer_description' => 'Calculadoras y cuadernos para estudiantes.',
            'document_path' => 'verificaciones-comercios/doc.pdf', 'document_original_name' => 'escritura.pdf',
            'status' => 'submitted', 'submitted_at' => now(),
        ]);
    }

    private function adminToken(): string
    {
        return $this->postJson('/api/lau-admin/login', ['email' => 'revisora@lau.test', 'password' => self::PASSWORD])
            ->assertOk()->json('data.token');
    }

    /** Cada petición resuelve el token de nuevo (el guard no recuerda al usuario anterior). */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_admin_logs_in_and_gets_a_short_lived_panel_token(): void
    {
        $response = $this->postJson('/api/lau-admin/login', ['email' => 'revisora@lau.test', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.admin.email', 'revisora@lau.test')
            ->assertJsonMissingPath('data.admin.password');

        $token = $this->admin->tokens()->sole();
        $this->assertSame(['lau-admin'], $token->abilities);
        $this->assertTrue($token->expires_at->between(now()->addHours(7), now()->addHours(9)));

        $this->as($response->json('data.token'))->getJson('/api/lau-admin/me')->assertOk()->assertJsonPath('data.name', 'Revisora LaU');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->postJson('/api/lau-admin/login', ['email' => 'revisora@lau.test', 'password' => 'incorrecta'])->assertUnprocessable();
        $this->assertSame(0, $this->admin->tokens()->count());
    }

    public function test_admin_token_does_not_work_on_student_routes(): void
    {
        $token = $this->adminToken();

        $this->as($token)->getJson('/api/auth/me')->assertUnauthorized();
        $this->as($token)->postJson('/api/marketplace/smart-shopping', ['query' => 'calculadora'])->assertUnauthorized();
    }

    public function test_student_token_does_not_work_on_admin_routes(): void
    {
        $student = User::factory()->create();
        $token = $student->createToken('app')->plainTextToken;

        $this->as($token)->getJson('/api/lau-admin/merchants')->assertUnauthorized();
        $this->as($token)->getJson('/api/auth/me')->assertOk();
    }

    public function test_lists_and_shows_applications(): void
    {
        $token = $this->adminToken();

        $this->as($token)->getJson('/api/lau-admin/merchants?estado=submitted')->assertOk()
            ->assertJsonPath('data.items.0.legal_name', 'Papelería Campus Central, S.A. de C.V.')
            ->assertJsonPath('data.counts.submitted', 1);

        $this->as($token)->getJson('/api/lau-admin/merchants/'.$this->verification->id)->assertOk()
            ->assertJsonPath('data.nrc', '123456-7')
            ->assertJsonPath('data.has_document', true)
            ->assertJsonMissingPath('data.document_path');

        $this->as($token)->get('/api/lau-admin/merchants/'.$this->verification->id.'/document')->assertOk()->assertDownload('escritura.pdf');
    }

    public function test_approve_sends_the_email_and_reject_requires_notes(): void
    {
        Mail::fake();
        $token = $this->adminToken();

        $this->as($token)->postJson('/api/lau-admin/merchants/'.$this->verification->id.'/reject', ['notes' => 'corto'])
            ->assertUnprocessable()->assertJsonValidationErrors('notes');

        $this->as($token)->postJson('/api/lau-admin/merchants/'.$this->verification->id.'/approve', ['notes' => 'Documentos en orden.'])
            ->assertOk()->assertJsonPath('emailed', true)->assertJsonPath('data.status', 'approved');

        $this->assertSame('approved', $this->store->fresh()->verification_status);
        $this->assertSame('Revisora LaU <revisora@lau.test>', $this->verification->fresh()->reviewed_by);
        Mail::assertSent(MerchantApprovedMail::class, fn ($mail) => $mail->hasTo('ventas@papeleriacampus.test'));
    }

    public function test_payment_gateways_are_reviewed_apart_from_the_verification(): void
    {
        Mail::fake();
        $token = $this->adminToken();
        $paypal = $this->store->paymentGateways()->create(['gateway' => 'paypal', 'status' => 'requested', 'requested_at' => now()]);
        $this->store->paymentGateways()->create(['gateway' => 'otra', 'gateway_name' => 'La de mi banco', 'status' => 'requested', 'requested_at' => now()]);

        $this->as($token)->getJson('/api/lau-admin/merchants?estado=pagos')->assertOk()
            ->assertJsonPath('data.counts.pagos', 1)
            ->assertJsonPath('data.items.0.pending_gateways', 2);

        // PayPal solo se aprueba para negocios ya verificados.
        $this->as($token)->postJson('/api/lau-admin/merchants/gateways/'.$paypal->id.'/approve')->assertUnprocessable();

        $this->as($token)->postJson('/api/lau-admin/merchants/'.$this->verification->id.'/approve')->assertOk();
        $this->as($token)->postJson('/api/lau-admin/merchants/gateways/'.$paypal->id.'/approve')->assertOk()
            ->assertJsonPath('data.gateways.1.label', 'PayPal')
            ->assertJsonPath('data.gateways.1.status', 'approved')
            ->assertJsonPath('data.gateways.0.label', 'La de mi banco');
        $this->assertSame('Revisora LaU <revisora@lau.test>', $paypal->fresh()->reviewed_by);
    }

    public function test_each_legal_document_downloads_by_kind(): void
    {
        Storage::disk('local')->put('verificaciones-comercios/dui.jpg', 'jpg');
        $this->verification->forceFill(['id_document_path' => 'verificaciones-comercios/dui.jpg', 'id_document_name' => 'dui.jpg'])->save();
        $token = $this->adminToken();

        $this->as($token)->getJson('/api/lau-admin/merchants/'.$this->verification->id)->assertOk()
            ->assertJsonPath('data.documents.0.sent', true)
            ->assertJsonPath('data.documents.1.sent', false)
            ->assertJsonPath('data.documents.2.sent', true);
        $this->as($token)->get('/api/lau-admin/merchants/'.$this->verification->id.'/documents/id_document')->assertOk()->assertDownload('dui.jpg');
        $this->as($token)->getJson('/api/lau-admin/merchants/'.$this->verification->id.'/documents/registration_document')->assertNotFound();
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = $this->adminToken();
        $this->as($token)->postJson('/api/lau-admin/logout')->assertOk();
        $this->as($token)->getJson('/api/lau-admin/me')->assertUnauthorized();
    }

    public function test_admin_password_command_changes_password_and_revokes_sessions(): void
    {
        $this->adminToken();

        $this->artisan('lau:admin-password', ['email' => 'revisora@lau.test'])
            ->expectsQuestion('Nueva contraseña (mínimo 12 caracteres)', 'nueva-clave-segura-2026')
            ->expectsQuestion('Repetila', 'nueva-clave-segura-2026')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('nueva-clave-segura-2026', $this->admin->fresh()->password));
        $this->assertSame(0, $this->admin->tokens()->count());
    }
}
