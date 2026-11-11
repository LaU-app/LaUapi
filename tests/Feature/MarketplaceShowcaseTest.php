<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Database\Seeders\MarketplaceShowcaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MarketplaceShowcaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_showcase_seeder_is_idempotent(): void
    {
        $this->seed(MarketplaceShowcaseSeeder::class);
        $this->seed(MarketplaceShowcaseSeeder::class);

        $this->assertSame(1, Store::where('slug', 'papeleria-campus-central')->count());
        $store = Store::where('slug', 'papeleria-campus-central')->first();
        $this->assertSame('approved', $store->verification_status);
        $this->assertSame(MarketplaceShowcaseSeeder::MERCHANT_EMAIL, $store->user->email);
        $this->assertSame(7, $store->products()->count());
        $this->assertSame('submitted', Store::where('slug', 'tecnocampus-sv')->value('verification_status'));
    }

    public function test_demo_accounts_command_sets_a_shared_password_without_printing_it(): void
    {
        $this->seed(MarketplaceShowcaseSeeder::class);

        $this->artisan('marketplace:demo-accounts')
            ->expectsQuestion('Contraseña para ambas cuentas de demostración (mínimo 10 caracteres)', 'Demo-Jurado-2026')
            ->expectsQuestion('Repite la contraseña', 'Demo-Jurado-2026')
            ->expectsOutputToContain(MarketplaceShowcaseSeeder::STUDENT_EMAIL)
            ->doesntExpectOutputToContain('Demo-Jurado-2026')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('Demo-Jurado-2026', User::where('email', MarketplaceShowcaseSeeder::STUDENT_EMAIL)->value('password')));
        $this->assertTrue(Hash::check('Demo-Jurado-2026', User::where('email', MarketplaceShowcaseSeeder::MERCHANT_EMAIL)->value('password')));
    }

    public function test_demo_accounts_rejects_short_passwords(): void
    {
        $this->seed(MarketplaceShowcaseSeeder::class);

        $this->artisan('marketplace:demo-accounts')
            ->expectsQuestion('Contraseña para ambas cuentas de demostración (mínimo 10 caracteres)', 'corta')
            ->assertFailed();
    }
}
