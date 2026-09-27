<?php

namespace App\Services;

use App\Models\StudySession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudySessionService
{
    public function start(User $user, array $data): StudySession
    {
        return DB::transaction(function () use ($user, $data) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = $user->studySessions()->where('uuid_cliente', $data['uuid_cliente'])->first();
            if ($existing) {
                return $existing;
            }
            if ($user->studySessions()->where('status', 'running')->exists()) {
                abort(409, 'Ya tienes una sesión activa. Recupérala o cancélala antes de iniciar otra.');
            }
            $task = isset($data['task_id']) ? $user->tasks()->findOrFail($data['task_id']) : null;
            if ($task && in_array($task->status, ['completed', 'cancelled'])) {
                throw ValidationException::withMessages(['task_id' => 'Esta tarea ya está cerrada.']);
            }
            $preferences = $user->studyPreference()->first();
            $session = $user->studySessions()->create([
                'uuid_cliente' => $data['uuid_cliente'], 'task_id' => $task?->id,
                'activity' => $data['activity'] ?? $task?->title,
                'target_seconds' => ($preferences?->focus_minutes ?? 25) * 60,
                'started_at' => now()->startOfSecond(), 'status' => 'running',
            ]);
            if ($task) {
                $task->update(['status' => 'in_progress']);
            }

            return $session;
        });
    }

    public function finish(User $user, array $data): StudySession
    {
        return DB::transaction(function () use ($user, $data) {
            // Serialize all sessions for a user, including requests from other devices.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $session = $user->studySessions()->where('uuid_cliente', $data['session_uuid'])->lockForUpdate()->firstOrFail();
            if ($session->status !== 'running') {
                return $session->load('task');
            }
            $cancelled = ($data['status'] ?? 'finished') === 'cancelled';
            $intervals = $cancelled ? [] : ($data['intervals'] ?? []);
            $previousEnd = $session->started_at->timestamp;
            $duration = 0;
            foreach ($intervals as $interval) {
                [$start, $end] = [$interval['start'], $interval['end']];
                if ($start < $previousEnd || $end <= $start || $end > now()->timestamp) {
                    throw ValidationException::withMessages(['intervals' => 'Los intervalos deben ser reales, ordenados, sin solaparse y anteriores al momento actual.']);
                }
                $duration += $end - $start;
                $previousEnd = $end;
            }
            if ($duration > $session->target_seconds) {
                throw ValidationException::withMessages(['intervals' => 'El tiempo supera la duración de enfoque de esta sesión.']);
            }
            foreach ($intervals as $interval) {
                $cursor = CarbonImmutable::createFromTimestampUTC($interval['start'])->setTimezone(config('study.timezone'));
                $end = CarbonImmutable::createFromTimestampUTC($interval['end']);
                while ($cursor->lessThan($end)) {
                    $sliceEnd = $cursor->addDay()->startOfDay()->min($end);
                    $session->intervals()->create([
                        'started_at' => $cursor->utc(), 'ended_at' => $sliceEnd->utc(),
                        'studied_on' => $cursor->toDateString(),
                        'duration_seconds' => $sliceEnd->timestamp - $cursor->timestamp,
                    ]);
                    $cursor = $sliceEnd->setTimezone(config('study.timezone'));
                }
            }
            $session->update([
                'duration_seconds' => $duration,
                'ended_at' => $intervals ? CarbonImmutable::createFromTimestampUTC($previousEnd) : now(),
                'status' => $cancelled ? 'cancelled' : ($duration === $session->target_seconds ? 'completed' : 'finished'),
            ]);
            $task = $session->task;
            if ($task && ! $task->trashed() && $session->status === 'completed' && ! in_array($task->status, ['completed', 'cancelled'])) {
                $completed = $task->pomodoroSessions()->where('status', 'completed')->count();
                if ($task->estimated_pomodoros && $completed >= $task->estimated_pomodoros) {
                    $task->update(['status' => 'completed', 'completed_at' => $session->ended_at]);
                }
            }

            return $session->refresh()->load('task');
        });
    }
}
