<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid_cliente',
        'user_id',
        'title',
        'description',
        'status',
        'priority',
        'due_date',
        'completed_at',
        'estimated_pomodoros',
        'completed_pomodoros',
        'synced_at',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'completed_at' => 'datetime',
        'synced_at' => 'datetime',
        'estimated_pomodoros' => 'integer',
        'completed_pomodoros' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pomodoroSessions()
    {
        return $this->hasMany(StudySession::class);
    }

    public function legacyPomodoroSessions()
    {
        return $this->hasMany(PomodoroSession::class);
    }

    public function scopeWithProgress($query)
    {
        return $query->withCount([
            'pomodoroSessions as completed_pomodoros' =>
                fn ($q) => $q->where('status', 'completed'),
        ]);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeOrderByPriority($query)
    {
        return $query->orderByRaw("FIELD(priority, 'high', 'medium', 'low')");
    }

    public function scopeOrderByDueDate($query)
    {
        return $query->orderBy('due_date', 'asc');
    }

    public function markAsCompleted()
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function incrementPomodoros()
    {
        $this->increment('completed_pomodoros');

        if ($this->status === 'pending') {
            $this->update(['status' => 'in_progress']);
        }
    }
}