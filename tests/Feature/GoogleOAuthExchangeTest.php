<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** El client secret de Google vive solo en el servidor: la app web canjea el código aquí. */
class GoogleOAuthExchangeTest extends TestCase
{
    private const SECRET = 'fake-google-secret-GGG';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.frontend_url' => 'https://lauapp.es',
            'services.google_oauth' => ['client_id' => 'web-client-id', 'client_secret' => self::SECRET, 'extra_redirects' => []],
        ]);
        Http::preventStrayRequests();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => '4/0AbCdEf',
            'code_verifier' => str_repeat('v', 64),
            'redirect_uri' => 'https://lauapp.es/auth/callback',
        ], $overrides);
    }

    public function test_exchanges_the_code_server_side_without_returning_the_secret(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token', 'id_token' => 'id.jwt', 'expires_in' => 3600])]);

        $response = $this->postJson('/api/auth/oauth/google/token', $this->payload())->assertOk()
            ->assertJsonPath('data.access_token', 'ya29.token')
            ->assertJsonPath('data.id_token', 'id.jwt');

        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        Http::assertSent(fn (Request $r) => $r['client_secret'] === self::SECRET && $r['client_id'] === 'web-client-id'
            && $r['code_verifier'] === str_repeat('v', 64) && $r['grant_type'] === 'authorization_code');
    }

    public function test_rejects_redirects_outside_the_allowlist(): void
    {
        Http::fake();
        $this->postJson('/api/auth/oauth/google/token', $this->payload(['redirect_uri' => 'https://evil.test/auth/callback']))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_google_rejection_is_an_error(): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->postJson('/api/auth/oauth/google/token', $this->payload())->assertUnauthorized();
    }

    public function test_disabled_without_secret(): void
    {
        config(['services.google_oauth.client_secret' => null]);
        Http::fake();
        $this->postJson('/api/auth/oauth/google/token', $this->payload())->assertStatus(503);
    }
}
