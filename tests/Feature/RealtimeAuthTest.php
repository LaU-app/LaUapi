<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RealtimeAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        \Tests\Support\StudySchema::migrate();
    }

    public function test_study_channel_is_private_to_its_owner(): void
    {
        $user = User::create(['name' => 'Ana', 'username' => 'ana-live', 'email' => 'ana-live@test.dev', 'password' => 'unused']);
        Sanctum::actingAs($user);
        $this->postJson('/api/realtime/auth', ['socket_id' => '123.456', 'channel_name' => 'private-study.999'])->assertUnprocessable();
        $this->postJson('/api/realtime/auth', ['socket_id' => '123.456', 'channel_name' => 'private-study.'.$user->id])->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_realtime_auth_requires_a_user(): void
    {
        $this->postJson('/api/realtime/auth', ['socket_id' => '123.456', 'channel_name' => 'private-study.1'])->assertUnauthorized();
    }
}
