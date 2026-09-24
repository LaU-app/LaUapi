<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudySession extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'target_seconds' => 'integer', 'duration_seconds' => 'integer'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function intervals()
    {
        return $this->hasMany(StudyInterval::class);
    }
}
