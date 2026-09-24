<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyPreference extends Model
{
    protected $fillable = ['focus_minutes', 'short_break_minutes', 'long_break_minutes', 'cycles_before_long_break', 'ranking_public'];

    protected $casts = ['focus_minutes' => 'integer', 'short_break_minutes' => 'integer', 'long_break_minutes' => 'integer', 'cycles_before_long_break' => 'integer', 'ranking_public' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
