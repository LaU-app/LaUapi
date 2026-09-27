<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StudyRealtime;
use App\Services\StudySessionService;
use App\Services\StudyStatistics;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StudyController extends Controller
{
    private function respond($data)
    {
        return response()->json(['success' => true, 'data' => $data, 'server_now' => now()->toISOString()])->header('Cache-Control', 'private, no-store');
    }

    public function preferences(Request $request)
    {
        $p = $request->user()->studyPreference()->first();

        return $this->respond($p ?? ['focus_minutes' => 25, 'short_break_minutes' => 5, 'long_break_minutes' => 15, 'cycles_before_long_break' => 4, 'ranking_public' => false]);
    }

    public function updatePreferences(Request $request, StudyRealtime $realtime)
    {
        $data = $request->validate([
            'focus_minutes' => 'sometimes|required|integer|between:1,120',
            'short_break_minutes' => 'sometimes|required|integer|between:1,30',
            'long_break_minutes' => 'sometimes|required|integer|between:1,60',
            'cycles_before_long_break' => 'sometimes|required|integer|between:1,12',
            'ranking_public' => 'sometimes|required|boolean',
        ]);
        $preferences = DB::transaction(function () use ($request, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            return $request->user()->studyPreference()->updateOrCreate([], $data)->refresh();
        });
        $realtime->notify($request->user(), ['preferences', 'ranking']);

        return $this->respond($preferences);
    }

    public function start(Request $request, StudySessionService $service, StudyRealtime $realtime)
    {
        $data = $request->validate(['uuid_cliente' => 'required|uuid', 'task_id' => 'nullable|integer', 'activity' => 'nullable|string|max:255']);

        $session = $service->start($request->user(), $data);
        $realtime->notify($request->user(), ['timer', 'tasks']);

        return $this->respond($session);
    }

    public function finish(Request $request, StudySessionService $service, StudyRealtime $realtime)
    {
        $data = $request->validate([
            'session_uuid' => 'required|uuid', 'status' => ['required', Rule::in(['finished', 'cancelled'])],
            'intervals' => 'required_if:status,finished|array|max:1000',
            'intervals.*.start' => 'required|integer|min:0', 'intervals.*.end' => 'required|integer|min:0',
        ]);

        $session = $service->finish($request->user(), $data);
        $realtime->notify($request->user(), ['timer', 'tasks', 'stats', 'ranking']);

        return $this->respond($session);
    }

    public function cancel(Request $request, StudySessionService $service, StudyRealtime $realtime)
    {
        $data = $request->validate(['session_uuid' => 'required|uuid']);

        $session = $service->finish($request->user(), [...$data, 'status' => 'cancelled']);
        $realtime->notify($request->user(), ['timer', 'tasks', 'stats']);

        return $this->respond($session);
    }

    public function active(Request $request)
    {
        return $this->respond($request->user()->studySessions()->where('status', 'running')->first());
    }

    public function stats(Request $request, StudyStatistics $stats)
    {
        return $this->respond($stats->summary($request->user()));
    }

    public function history(Request $request)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'task_id' => 'sometimes|integer', 'status' => ['sometimes', Rule::in(['running', 'finished', 'completed', 'cancelled'])], 'period' => ['sometimes', Rule::in(['today', 'week', 'month'])]]);
        $query = $request->user()->studySessions()->with('task:id,title');
        if (isset($data['task_id'])) {
            $request->user()->tasks()->withTrashed()->findOrFail($data['task_id']);
            $query->where('task_id', $data['task_id']);
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (isset($data['period'])) {
            $now = CarbonImmutable::now(config('study.timezone'));
            $start = match ($data['period']) {
                'today' => $now->startOfDay(), 'week' => $now->startOfWeek(), 'month' => $now->startOfMonth()
            };
            $query->where('started_at', '>=', $start->utc());
        }

        return $this->respond($query->latest('started_at')->orderByDesc('id')->paginate(20));
    }

    public function leaderboard(Request $request)
    {
        $data = $request->validate(['period' => ['sometimes', Rule::in(['week', 'month'])], 'scope' => ['sometimes', Rule::in(['global', 'university', 'career'])]]);
        $user = $request->user();
        $period = $data['period'] ?? 'week';
        $scope = $data['scope'] ?? 'global';
        $now = CarbonImmutable::now(config('study.timezone'));
        $start = $period === 'week' ? $now->startOfWeek() : $now->startOfMonth();
        $totals = DB::table('study_intervals as i')->join('study_sessions as s', 's.id', '=', 'i.study_session_id')
            ->join('study_preferences as p', 'p.user_id', '=', 's.user_id')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('p.ranking_public', true)->whereBetween('i.studied_on', [$start->toDateString(), $now->toDateString()]);
        if ($scope !== 'global') {
            $column = $scope === 'university' ? 'universidad_id' : 'carrera_id';
            if (! $user->$column) {
                abort(422, 'Completa tu universidad o carrera en el perfil para usar este filtro.');
            }
            $totals->where('u.'.$column, $user->$column);
        }
        $totals->selectRaw('s.user_id, SUM(i.duration_seconds) as seconds')->groupBy('s.user_id');
        $ranked = DB::query()->fromSub($totals, 'totals')->selectRaw('user_id, seconds, ROW_NUMBER() OVER (ORDER BY seconds DESC, user_id ASC) as position');
        $rows = DB::query()->fromSub($ranked, 'ranking')->orderBy('position')->limit(50)->get();
        $users = User::whereIn('id', $rows->pluck('user_id'))->get(['id', 'username', 'imagen'])->keyBy('id');
        $entries = $rows->map(fn ($row) => ['id' => $row->user_id, 'username' => $users[$row->user_id]->username, 'avatar' => $users[$row->user_id]->imagen_url, 'seconds' => (int) $row->seconds, 'position' => (int) $row->position]);
        $me = DB::query()->fromSub($ranked, 'ranking')->where('user_id', $user->id)->first();

        return $this->respond(['entries' => $entries, 'me' => $me ? ['position' => (int) $me->position, 'seconds' => (int) $me->seconds] : null,
            'ranking_public' => (bool) $user->studyPreference?->ranking_public, 'period' => $period, 'scope' => $scope,
            'available_scopes' => array_values(array_filter(['global', $user->universidad_id ? 'university' : null, $user->carrera_id ? 'career' : null])), 'timezone' => config('study.timezone')]);
    }
}
