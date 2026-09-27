<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Notification;
use App\Models\Post;
use App\Jobs\ProcessMentionsJob;
use App\Services\NotificationService;
use App\Services\MentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MentionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_extrae_nombres_normalizados_sin_duplicados(): void
    {
        $service = new MentionService();

        $this->assertSame(
            ['ana_1', 'juan'],
            $service->extractUsernames('Hola @Ana_1 y @JUAN, otra vez @ana_1')
        );
    }

    public function test_solo_devuelve_usuarios_con_seguimiento_mutuo(): void
    {
        $author = User::factory()->create(['username' => 'author']);
        $mutual = User::factory()->create(['username' => 'mutual']);
        $oneWay = User::factory()->create(['username' => 'one_way']);

        DB::table('followers')->insert([
            ['user_id' => $mutual->id, 'follower_id' => $author->id],
            ['user_id' => $author->id, 'follower_id' => $mutual->id],
            ['user_id' => $oneWay->id, 'follower_id' => $author->id],
        ]);

        $ids = (new MentionService())->validateMutualFriends(
            $author->id,
            ['mutual', 'one_way']
        );

        $this->assertSame([$mutual->id], $ids);
    }

    public function test_busqueda_de_amigos_requiere_autenticacion_y_filtra_relacion_mutua(): void
    {
        $author = User::factory()->create(['username' => 'author']);
        $mutual = User::factory()->create(['username' => 'mutual_friend']);
        $oneWay = User::factory()->create(['username' => 'mutual_other']);

        DB::table('followers')->insert([
            ['user_id' => $mutual->id, 'follower_id' => $author->id],
            ['user_id' => $author->id, 'follower_id' => $mutual->id],
            ['user_id' => $oneWay->id, 'follower_id' => $author->id],
        ]);

        $this->getJson('/api/users/search-friends?query=mutual')
            ->assertUnauthorized();

        Sanctum::actingAs($author);

        $this->getJson('/api/users/search-friends?query=mutual')
            ->assertOk()
            ->assertJsonPath('data.0.id', $mutual->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_guardar_menciones_es_idempotente(): void
    {
        $author = User::factory()->create();
        $mentioned = User::factory()->create();
        $service = new MentionService();

        $service->saveMentions($author->id, ['type' => 'post', 'id' => 42], [$mentioned->id]);
        $service->saveMentions($author->id, ['type' => 'post', 'id' => 42], [$mentioned->id]);

        $this->assertDatabaseCount('mentions', 1);
    }

    public function test_procesar_mencion_crea_notificacion(): void
    {
        $author = User::factory()->create(['username' => 'author']);
        $mentioned = User::factory()->create(['username' => 'mentioned']);

        DB::table('followers')->insert([
            ['user_id' => $mentioned->id, 'follower_id' => $author->id],
            ['user_id' => $author->id, 'follower_id' => $mentioned->id],
        ]);

        $post = Post::factory()->create(['user_id' => $author->id]);

        (new ProcessMentionsJob(
            $author->id,
            'Hola @mentioned',
            'post',
            $post->id
        ))->handle(new MentionService(), new NotificationService());

        $this->assertDatabaseHas('mentions', [
            'mentioned_user_id' => $mentioned->id,
            'author_id' => $author->id,
            'mentionable_type' => 'post',
            'mentionable_id' => $post->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $mentioned->id,
            'from_user_id' => $author->id,
            'type' => Notification::TYPE_MENTION,
            'post_id' => $post->id,
        ]);
    }
}
