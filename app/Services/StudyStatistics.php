<?php

namespace App\Services;

use App\Models\StudyInterval;
use App\Models\User;
use Carbon\CarbonImmutable;

class StudyStatistics
{
    public function summary(User $user): array
    {
        $today = CarbonImmutable::now(config('study.timezone'))->startOfDay();
        $week = $today->startOfWeek();
        $month = $today->startOfMonth();
        $daily = StudyInterval::query()->whereHas('session', fn ($q) => $q->where('user_id', $user->id));
        $sum = fn ($from) => (int) (clone $daily)->whereBetween('studied_on', [$from->toDateString(), $today->toDateString()])->sum('duration_seconds');
        $days = (clone $daily)->whereBetween('studied_on', [$week->toDateString(), $week->addDays(6)->toDateString()])
            ->selectRaw('studied_on, SUM(duration_seconds) as seconds')->groupBy('studied_on')->pluck('seconds', 'studied_on');
        $qualifying = (clone $daily)->selectRaw('studied_on, SUM(duration_seconds) as seconds')->groupBy('studied_on')
            ->havingRaw('SUM(duration_seconds) >= ?', [config('study.streak_minimum_seconds')])->orderBy('studied_on')->pluck('studied_on');
        $best = $run = 0;
        $previous = null;
        foreach ($qualifying as $day) {
            $date = CarbonImmutable::parse($day, config('study.timezone'));
            $run = $previous && $previous->addDay()->isSameDay($date) ? $run + 1 : 1;
            $best = max($best, $run);
            $previous = $date;
        }
        $current = $previous && $previous->greaterThanOrEqualTo($today->subDay()) ? $run : 0;
        $completed = $user->studySessions()->where('status', 'completed');
        $weekSeconds = $sum($week);

        return [
            'timezone' => config('study.timezone'), 'today_seconds' => $sum($today),
            'week_seconds' => $weekSeconds, 'month_seconds' => $sum($month),
            'sessions_completed' => $user->studySessions()->whereIn('status', ['completed', 'finished'])->where('duration_seconds', '>', 0)->count(),
            'pomodoros_completed' => (clone $completed)->count(),
            'pomodoros_today' => (clone $completed)->where('ended_at', '>=', $today->utc())->count(),
            'pomodoros_this_week' => (clone $completed)->where('ended_at', '>=', $week->utc())->count(),
            'current_streak' => $current, 'best_streak' => $best,
            'streak_minimum_seconds' => config('study.streak_minimum_seconds'),
            'daily_average_seconds' => (int) round($weekSeconds / ($today->dayOfWeekIso)),
            'weekly' => collect(range(0, 6))->map(fn ($n) => [
                'date' => $week->addDays($n)->toDateString(),
                'seconds' => (int) ($days[$week->addDays($n)->toDateString()] ?? 0),
            ])->all(),
        ];
    }
}
