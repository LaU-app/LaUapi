<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StudyRealtime;
use App\Services\StudyStatistics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    private function respond($data)
    {
        return response()->json(['success' => true, 'data' => $data]);
    }

    private function validateTask(Request $request, bool $creating): array
    {
        return $request->validate([
            'uuid_cliente' => $creating ? 'sometimes|uuid' : 'prohibited',
            'title' => ($creating ? 'required' : 'sometimes|required').'|string|max:255',
            'description' => 'nullable|string|max:10000',
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'status' => ['sometimes', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
            'due_date' => 'nullable|date',
            'estimated_pomodoros' => 'nullable|integer|between:1,100',
        ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'search' => 'sometimes|string|max:255',
            'sort_by' => ['sometimes', Rule::in(['created_at', 'due_date', 'priority', 'title'])],
            'sort_order' => ['sometimes', Rule::in(['asc', 'desc'])],
            'overdue' => 'sometimes|in:true,false,1,0',
            'page' => 'sometimes|integer|min:1',
        ]);

        $query = $request->user()->tasks()->withProgress();

        foreach (['status', 'priority'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        if (isset($data['search'])) {
            $query->where('title', 'like', '%'.$data['search'].'%');
        }

        if ($request->boolean('overdue')) {
            $query->where('due_date', '<', now())
                ->whereNotIn('status', ['completed', 'cancelled']);
        }

        $sortBy = $data['sort_by'] ?? 'created_at';
        $sortOrder = $data['sort_order'] ?? 'desc';

        if ($sortBy === 'priority') {
            $query->orderByRaw(
                "CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END {$sortOrder}"
            );
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        return $this->respond($query->orderBy('id')->paginate(20));
    }

    public function store(Request $request, StudyRealtime $realtime)
    {
        $data = $this->validateTask($request, true);
        $data['uuid_cliente'] = $data['uuid_cliente'] ?? (string) Str::uuid();

        $task = DB::transaction(function () use ($request, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            return $request->user()->tasks()->firstOrCreate(
                ['uuid_cliente' => $data['uuid_cliente']],
                $data
            );
        });

        $realtime->notify($request->user(), ['tasks', 'stats']);

        return $this->respond($task->fresh()->loadCount([
            'pomodoroSessions as completed_pomodoros' => fn ($q) => $q->where('status', 'completed'),
        ]));
    }

    public function show(Request $request, int $task)
    {
        $record = $request->user()->tasks()->withProgress()->findOrFail($task);

        $record->setRelation(
            'pomodoroSessions',
            $record->pomodoroSessions()->latest()->limit(20)->get()
        );

        return $this->respond($record);
    }

    public function update(Request $request, int $task, StudyRealtime $realtime)
    {
        $data = $this->validateTask($request, false);

        $record = DB::transaction(function () use ($request, $task, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            $record = $request->user()->tasks()->findOrFail($task);

            if (isset($data['status'])) {
                $data['completed_at'] = $data['status'] === 'completed' ? now() : null;
            }

            $record->update($data);

            return $request->user()->tasks()->withProgress()->findOrFail($task);
        });

        $realtime->notify($request->user(), ['tasks', 'stats']);

        return $this->respond($record);
    }

    public function destroy(Request $request, int $task, StudyRealtime $realtime)
    {
        $request->user()->tasks()->findOrFail($task)->delete();
        $realtime->notify($request->user(), ['tasks', 'stats']);

        return $this->respond(null);
    }

    public function stats(Request $request, StudyStatistics $study)
    {
        $user = $request->user();

        $counts = $user->tasks()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $summary = $study->summary($user);

        return $this->respond([
            ...array_fill_keys(['pending', 'in_progress', 'completed', 'cancelled'], 0),
            ...$counts->all(),
            ...$summary,
            'total' => $user->tasks()->count(),
            'total_pomodoros' => $summary['pomodoros_completed'],
            'overdue' => $user->tasks()
                ->where('due_date', '<', now())
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->count(),
        ]);
    }
}