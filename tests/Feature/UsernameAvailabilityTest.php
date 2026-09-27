<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsernameAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_considera_no_disponible_un_username_con_mayusculas_o_espacios_equivalentes(): void
    {
        User::factory()->create(['username' => 'usuario_prueba']);

        $this->postJson('/api/auth/check-username', [
            'username' => '  Usuario_Prueba  ',
        ])
            ->assertOk()
            ->assertJson([
                'available' => false,
                'username' => 'usuario_prueba',
            ]);
    }

    public function test_rechaza_username_vacio(): void
    {
        $this->postJson('/api/auth/check-username', ['username' => '   '])
            ->assertUnprocessable()
            ->assertJson([
                'available' => false,
            ]);
    }
}