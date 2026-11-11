<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** El chat abre conversaciones nuevas con el id del vendedor: /users/{id} tiene que encontrarlo. */
class UserLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_resolve_by_username_and_by_id(): void
    {
        $seller = User::factory()->create(['name' => 'Gabriela Martínez', 'username' => 'gaby_mtz']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/users/gaby_mtz')->assertOk()->assertJsonPath('data.name', 'Gabriela Martínez');
        $this->getJson('/api/users/'.$seller->id)->assertOk()->assertJsonPath('data.username', 'gaby_mtz');
        $this->getJson('/api/users/999999')->assertNotFound();
    }
}
