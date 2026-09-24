<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use SoftDeletes;

    protected $fillable = ['uuid_cliente', 'title', 'description', 'priority', 'status', 'due_date', 'estimated_pomodoros', 'completed_at'];

    protected $casts = ['due_date' => 'datetime', 'completed_at' => 'datetime', 'estimated_pomodoros' => 'integer'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pomodoroSessions()
    {
        return $this->hasMany(StudySession::class);
    }

    public function scopeWithProgress($query)
    {
        return $query->withCount(['pomodoroSessions as completed_pomodoros' => fn ($q) => $q->where('status', 'completed')]);
    }
}
