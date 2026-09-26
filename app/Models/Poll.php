<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Poll extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'question',
        'visibility',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class)->select(['id', 'name', 'username', 'imagen', 'insignia']);
    }

    public function options()
    {
        return $this->hasMany(PollOption::class);
    }

    public function votes()
    {
        return $this->hasMany(PollVote::class);
    }

    public function isClosed()
    {
        return now() > $this->expires_at;
    }

    public function userHasVoted($userId)
    {
        return $this->votes()
            ->where('user_id', $userId)
            ->exists();
    }

    public function getTimeRemainingAttribute()
    {
        if ($this->isClosed()) {
            return 'Cerrada';
        }

        $diff = $this->expires_at->diffInSeconds(now());

        if ($diff < 60) {
            return 'Expira en segundos';
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes == 1 ? 'Expira en 1 min' : "Expira en {$minutes} mins";
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours == 1 ? 'Expira en 1 hora' : "Expira en {$hours} horas";
        } else {
            $days = floor($diff / 86400);
            return $days == 1 ? 'Expira en 1 día' : "Expira en {$days} días";
        }
    }
}
