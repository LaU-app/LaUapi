<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudyScaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        \Tests\Support\StudySchema::migrate();
        $this->travelTo(CarbonImmutable::parse('2026-09-08 14:00:00', 'UTC'));
    }

    public function test_read_endpoints_remain_bounded_with_two_thousand_public_students(): void
    {
        $now = now()->toDateTimeString();
        foreach (array_chunk(range(1, 2000), 250) as $ids) {
            DB::table('users')->insert(array_map(fn ($id) => [
                'id' => $id, 'name' => 'Student '.$id, 'username' => 'student_'.$id,
                'email' => 'student_'.$id.'@scale.test', 'password' => 'unused',
                'created_at' => $now, 'updated_at' => $now,
            ], $ids));
            DB::table('study_preferences')->insert(array_map(fn ($id) => [
                'id' => $id, 'user_id' => $id, 'ranking_public' => true,
                'focus_minutes' => 25, 'short_break_minutes' => 5,
                'long_break_minutes' => 15, 'cycles_before_long_break' => 4,
                'created_at' => $now, 'updated_at' => $now,
            ], $ids));
            DB::table('study_sessions')->insert(array_map(fn ($id) => [
                'id' => $id, 'user_id' => $id, 'uuid_cliente' => sprintf('00000000-0000-4000-8000-%012d', $id),
                'status' => 'completed', 'target_seconds' => 1500, 'duration_seconds' => 600 + ($id % 901),
                'started_at' => $now, 'ended_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ], $ids));
            DB::table('study_intervals')->insert(array_map(fn ($id) => [
                'id' => $id, 'study_session_id' => $id, 'started_at' => $now, 'ended_at' => $now,
                'studied_on' => '2026-09-08', 'duration_seconds' => 600 + ($id % 901),
            ], $ids));
        }

        Sanctum::actingAs(User::findOrFail(1));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $this->getJson('/api/pomodoro/leaderboard')->assertOk()->assertJsonCount(50, 'data.entries');
        $rankingMs = (hrtime(true) - $started) / 1_000_000;
        $rankingQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $started = hrtime(true);
        $this->getJson('/api/pomodoro/stats')->assertOk()->assertJsonPath('data.today_seconds', 601);
        $statsMs = (hrtime(true) - $started) / 1_000_000;
        $statsQueries = count(DB::getQueryLog());

        fwrite(STDERR, sprintf("\nScale fixture: 2,000 users; ranking %.1f ms/%d queries; stats %.1f ms/%d queries\n", $rankingMs, $rankingQueries, $statsMs, $statsQueries));
        $this->assertLessThanOrEqual(6, $rankingQueries);
        $this->assertLessThanOrEqual(12, $statsQueries);
        $this->assertLessThan(5000, $rankingMs);
        $this->assertLessThan(5000, $statsMs);
    }
}
