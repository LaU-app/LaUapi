<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Isolate the actual module migrations from unrelated legacy MySQL-only migrations.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        DB::purge('sqlite');
        \Tests\Support\StudySchema::migrate();
        $this->travelTo(CarbonImmutable::parse('2026-09-08 14:00:00', 'UTC'));
    }

    private function user(string $name = 'ana'): User
    {
        return User::create(['name' => $name, 'username' => $name, 'email' => $name.'@example.test', 'password' => 'testing-password']);
    }

    private function start(?int $taskId = null, ?string $uuid = null): array
    {
        return $this->postJson('/api/pomodoro/start', ['uuid_cliente' => $uuid ?? (string) Str::uuid(), 'task_id' => $taskId])->assertOk()->json('data');
    }

    private function complete(array $session, int $seconds = 1500): array
    {
        $start = CarbonImmutable::parse($session['started_at'])->timestamp;
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($start + $seconds));
        $payload = ['session_uuid' => $session['uuid_cliente'], 'status' => 'finished', 'intervals' => [['start' => $start, 'end' => $start + $seconds]]];
        $this->postJson('/api/pomodoro/complete', $payload)->assertOk()->assertJsonPath('data.duration_seconds', $seconds);

        return $payload;
    }

    public function test_three_pomodoros_complete_task_exactly_once(): void
    {
        Sanctum::actingAs($this->user());
        $task = $this->postJson('/api/tasks', ['uuid_cliente' => (string) Str::uuid(), 'title' => 'Parcial', 'estimated_pomodoros' => 3])->assertOk()->json('data');
        for ($i = 1; $i <= 3; $i++) {
            $payload = $this->complete($this->start($task['id']));
            $this->postJson('/api/pomodoro/complete', $payload)->assertOk();
            $this->getJson('/api/tasks/'.$task['id'])->assertOk()->assertJsonPath('data.completed_pomodoros', $i)
                ->assertJsonPath('data.status', $i === 3 ? 'completed' : 'in_progress');
            $this->travel(5)->minutes();
        }
        $this->assertDatabaseCount('study_sessions', 3);
        $this->getJson('/api/pomodoro/stats')->assertJsonPath('data.today_seconds', 4500)->assertJsonPath('data.pomodoros_completed', 3);
    }

    public function test_start_is_idempotent_and_only_one_session_is_active(): void
    {
        Sanctum::actingAs($this->user());
        $uuid = (string) Str::uuid();
        $first = $this->start(null, $uuid);
        $this->assertEquals($first['id'], $this->start(null, $uuid)['id']);
        $this->postJson('/api/pomodoro/start', ['uuid_cliente' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('study_sessions', 1);
    }

    public function test_pause_gaps_and_partial_sessions_do_not_complete_a_pomodoro(): void
    {
        Sanctum::actingAs($this->user());
        $session = $this->start();
        $start = CarbonImmutable::parse($session['started_at'])->timestamp;
        $this->travel(20)->minutes();
        $this->postJson('/api/pomodoro/complete', ['session_uuid' => $session['uuid_cliente'], 'status' => 'finished', 'intervals' => [
            ['start' => $start, 'end' => $start + 300], ['start' => $start + 900, 'end' => $start + 1200],
        ]])->assertOk()->assertJsonPath('data.duration_seconds', 600)->assertJsonPath('data.status', 'finished');
        $this->getJson('/api/pomodoro/stats')->assertJsonPath('data.today_seconds', 600)->assertJsonPath('data.sessions_completed', 1)->assertJsonPath('data.pomodoros_completed', 0);
    }

    public function test_invalid_intervals_are_rejected_without_partial_writes(): void
    {
        Sanctum::actingAs($this->user());
        $s = $this->start();
        $start = CarbonImmutable::parse($s['started_at'])->timestamp;
        $this->travel(30)->minutes();
        foreach ([
            [['start' => $start - 1, 'end' => $start + 10]],
            [['start' => $start, 'end' => $start + 1801]],
            [['start' => $start, 'end' => $start + 1501]],
            [['start' => $start, 'end' => $start + 100], ['start' => $start + 50, 'end' => $start + 150]],
        ] as $intervals) {
            $this->postJson('/api/pomodoro/complete', ['session_uuid' => $s['uuid_cliente'], 'status' => 'finished', 'intervals' => $intervals])->assertUnprocessable();
        }
        $this->assertDatabaseCount('study_intervals', 0);
        $this->assertDatabaseHas('study_sessions', ['id' => $s['id'], 'status' => 'running']);
    }

    public function test_cancel_is_idempotent_and_never_awards_time(): void
    {
        Sanctum::actingAs($this->user());
        $s = $this->start();
        $this->postJson('/api/pomodoro/cancel', ['session_uuid' => $s['uuid_cliente']])->assertOk();
        $this->postJson('/api/pomodoro/cancel', ['session_uuid' => $s['uuid_cliente']])->assertOk();
        $this->assertDatabaseCount('study_intervals', 0);
        $this->getJson('/api/pomodoro/stats')->assertJsonPath('data.today_seconds', 0);
    }

    public function test_private_by_default_and_opt_out_removes_all_public_data(): void
    {
        $ana = $this->user();
        Sanctum::actingAs($ana);
        $this->complete($this->start());
        $this->getJson('/api/pomodoro/preferences')->assertJsonPath('data.ranking_public', false);
        $this->getJson('/api/pomodoro/leaderboard')->assertJsonCount(0, 'data.entries')->assertJsonPath('data.me', null);
        $this->putJson('/api/pomodoro/preferences', ['ranking_public' => true])->assertOk();
        $response = $this->getJson('/api/pomodoro/leaderboard')->assertOk()->assertJsonPath('data.entries.0.username', 'ana')->assertJsonPath('data.me.position', 1);
        $this->assertEqualsCanonicalizing(['id', 'username', 'avatar', 'seconds', 'position'], array_keys($response->json('data.entries.0')));
        $this->putJson('/api/pomodoro/preferences', ['ranking_public' => false])->assertOk();
        Sanctum::actingAs($this->user('bea'));
        $this->getJson('/api/pomodoro/leaderboard?period=month')->assertJsonCount(0, 'data.entries');
        Sanctum::actingAs($ana);
        $this->getJson('/api/pomodoro/stats')->assertJsonPath('data.today_seconds', 1500);
    }

    public function test_other_users_cannot_read_or_modify_tasks_or_sessions(): void
    {
        Sanctum::actingAs($this->user());
        $task = $this->postJson('/api/tasks', ['uuid_cliente' => (string) Str::uuid(), 'title' => 'Privada'])->json('data');
        $session = $this->start($task['id']);
        Sanctum::actingAs($this->user('bea'));
        $this->getJson('/api/tasks/'.$task['id'])->assertNotFound();
        $this->putJson('/api/tasks/'.$task['id'], ['title' => 'Cambio'])->assertNotFound();
        $this->deleteJson('/api/tasks/'.$task['id'])->assertNotFound();
        $this->getJson('/api/pomodoro/sessions?task_id='.$task['id'])->assertNotFound();
        $this->postJson('/api/pomodoro/cancel', ['session_uuid' => $session['uuid_cliente']])->assertNotFound();
        $this->postJson('/api/pomodoro/start', ['uuid_cliente' => (string) Str::uuid(), 'task_id' => $task['id']])->assertNotFound();
        $this->getJson('/api/pomodoro/active')->assertJsonPath('data', null);
        $this->getJson('/api/pomodoro/stats')->assertJsonPath('data.today_seconds', 0);
    }

    public function test_local_midnight_splits_minutes_between_days(): void
    {
        Sanctum::actingAs($this->user());
        $this->travelTo(CarbonImmutable::parse('2026-09-08 05:55:00', 'UTC'));
        $this->complete($this->start(), 600);
        $this->getJson('/api/pomodoro/stats')->assertOk()->assertJsonPath('data.today_seconds', 300)->assertJsonPath('data.week_seconds', 600)->assertJsonPath('data.month_seconds', 600)->assertJsonPath('data.current_streak', 0);
        $this->assertDatabaseCount('study_intervals', 2);
    }

    public function test_streak_keeps_yesterday_until_today_finishes(): void
    {
        Sanctum::actingAs($this->user());
        foreach ([5, 6, 7] as $day) {
            $this->travelTo(CarbonImmutable::parse('2026-09-0'.$day.' 14:00:00', 'UTC'));
            $this->complete($this->start(), 600);
        }
        $this->travelTo(CarbonImmutable::parse('2026-09-08 14:00:00', 'UTC'));
        $this->getJson('/api/pomodoro/stats')->assertJsonPath('data.current_streak', 3)->assertJsonPath('data.best_streak', 3);
        $this->travel(1)->days();
        $this->getJson('/api/pomodoro/stats')->assertJsonPath('data.current_streak', 0)->assertJsonPath('data.best_streak', 3);
    }

    public function test_preferences_persist_validate_and_apply_only_to_new_sessions(): void
    {
        Sanctum::actingAs($this->user());
        $s = $this->start();
        $this->putJson('/api/pomodoro/preferences', ['focus_minutes' => 30, 'short_break_minutes' => 7, 'long_break_minutes' => 20, 'cycles_before_long_break' => 3])->assertOk();
        $this->getJson('/api/pomodoro/active')->assertJsonPath('data.target_seconds', 1500);
        $this->getJson('/api/pomodoro/preferences')->assertJsonPath('data.focus_minutes', 30)->assertJsonPath('data.ranking_public', false);
        $this->putJson('/api/pomodoro/preferences', ['focus_minutes' => 0])->assertUnprocessable();
        $this->putJson('/api/pomodoro/preferences', ['cycles_before_long_break' => 13])->assertUnprocessable();
        $this->postJson('/api/pomodoro/cancel', ['session_uuid' => $s['uuid_cliente']])->assertOk();
        $this->assertSame(1800, $this->start()['target_seconds']);
    }

    public function test_deleted_task_keeps_study_history(): void
    {
        Sanctum::actingAs($this->user());
        $t = $this->postJson('/api/tasks', ['uuid_cliente' => (string) Str::uuid(), 'title' => 'Historia'])->json('data');
        $this->complete($this->start($t['id']));
        $this->deleteJson('/api/tasks/'.$t['id'])->assertOk();
        $this->getJson('/api/pomodoro/sessions')->assertJsonPath('data.data.0.task.title', 'Historia');
        $this->getJson('/api/pomodoro/stats')->assertJsonPath('data.today_seconds', 1500);
    }

    public function test_study_endpoints_require_authentication(): void
    {
        foreach (['stats', 'preferences', 'leaderboard', 'active', 'sessions'] as $path) {
            $this->getJson('/api/pomodoro/'.$path)->assertUnauthorized();
        }
    }

    public function test_ranking_filters_use_real_university_and_career_relationships(): void
    {
        $university = DB::table('universidades')->insertGetId(['nombre' => 'Universidad de prueba']);
        $otherUniversity = DB::table('universidades')->insertGetId(['nombre' => 'Otra universidad']);
        $career = DB::table('carreras')->insertGetId(['nombre' => 'Computación']);
        $ana = $this->user();
        $ana->update(['universidad_id' => $university, 'carrera_id' => $career]);
        Sanctum::actingAs($ana);
        $this->putJson('/api/pomodoro/preferences', ['ranking_public' => true])->assertOk();
        $this->complete($this->start(), 900);
        $bea = $this->user('bea');
        $bea->update(['universidad_id' => $otherUniversity, 'carrera_id' => $career]);
        Sanctum::actingAs($bea);
        $this->putJson('/api/pomodoro/preferences', ['ranking_public' => true])->assertOk();
        $this->complete($this->start(), 600);
        $this->getJson('/api/pomodoro/leaderboard')->assertJsonPath('data.me.position', 2)->assertJsonPath('data.entries.0.seconds', 900);
        $this->getJson('/api/pomodoro/leaderboard?scope=university')->assertJsonCount(1, 'data.entries')->assertJsonPath('data.me.position', 1);
        $this->getJson('/api/pomodoro/leaderboard?scope=career')->assertJsonCount(2, 'data.entries');
        Sanctum::actingAs($this->user('sin_perfil'));
        $this->getJson('/api/pomodoro/leaderboard?scope=university')->assertUnprocessable();
    }

    public function test_week_boundary_counts_only_the_monday_slice(): void
    {
        Sanctum::actingAs($this->user());
        $this->travelTo(CarbonImmutable::parse('2026-09-07 05:55:00', 'UTC'));
        $this->complete($this->start(), 600);
        $this->getJson('/api/pomodoro/stats')->assertJsonPath('data.week_seconds', 300)->assertJsonPath('data.month_seconds', 600);
        $this->putJson('/api/pomodoro/preferences', ['ranking_public' => true])->assertOk();
        $this->getJson('/api/pomodoro/leaderboard?period=week')->assertJsonPath('data.me.seconds', 300);
        $this->getJson('/api/pomodoro/leaderboard?period=month')->assertJsonPath('data.me.seconds', 600);
    }

    public function test_personal_rank_is_available_beyond_top_fifty_and_ties_are_stable(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $user = $this->user('student_'.$i);
            $user->studyPreference()->create(['ranking_public' => true]);
            $session = $user->studySessions()->create(['uuid_cliente' => (string) Str::uuid(), 'target_seconds' => 1500, 'duration_seconds' => 600, 'status' => 'finished', 'started_at' => now()->subMinutes(10), 'ended_at' => now()]);
            $session->intervals()->create(['started_at' => now()->subMinutes(10), 'ended_at' => now(), 'studied_on' => '2026-09-08', 'duration_seconds' => 600]);
        }
        Sanctum::actingAs($user);
        $this->getJson('/api/pomodoro/leaderboard')->assertJsonCount(50, 'data.entries')->assertJsonPath('data.entries.0.username', 'student_0')->assertJsonPath('data.me.position', 51);
    }
}
