<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public function setImagenAttribute($value)
    {
        $this->attributes['imagen'] = $value ?: 'img.jpg';
    }

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'imagen',
        'gender',
        'profession',
        'insignia',
        'last_activity',
        'is_online',
        'last_seen',
        'universidad_id',
        'carrera_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'imagen_url',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_activity' => 'datetime',
            'last_seen' => 'datetime',
            'is_online' => 'boolean',
        ];
    }

    public function getImagenUrlAttribute()
    {
        return $this->imagen
            ? url('perfiles/'.$this->imagen)
            : null;
    }

    public function getRouteKeyName()
    {
        return 'username';
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function likes()
    {
        return $this->hasMany(Like::class);
    }

    public function followers()
    {
        return $this->belongsToMany(User::class, 'followers', 'user_id', 'follower_id');
    }

    public function following()
    {
        return $this->belongsToMany(User::class, 'followers', 'follower_id', 'user_id');
    }

    public function isFollowing(User $user)
    {
        return $this->following->contains($user->id);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class)->recent();
    }

    public function unreadNotifications()
    {
        return $this->hasMany(Notification::class)->unread()->recent();
    }

    public function getUnreadNotificationsCountAttribute()
    {
        return $this->unreadNotifications()->count();
    }

    public function socialLinks()
    {
        return $this->hasMany(SocialLink::class)->ordered();
    }

    public function updateActivity()
    {
        $this->forceFill([
            'last_activity' => now(),
            'is_online' => true,
        ])->save();

        return $this;
    }

    public function setOffline()
    {
        $this->forceFill([
            'is_online' => false,
            'last_seen' => now(),
        ])->save();

        return $this;
    }

    public function isOnline()
    {
        return $this->is_online
            && $this->last_activity
            && $this->last_activity->greaterThan(now()->subMinutes(5));
    }

    public function getLastSeenAttribute($value)
    {
        if (!$value) {
            return null;
        }

        return \Carbon\Carbon::parse($value)->diffForHumans();
    }

    public function scopeOnline($query)
    {
        return $query->where('is_online', true)
            ->where('last_activity', '>', now()->subMinutes(5));
    }

    public function universidad()
    {
        return $this->belongsTo(Universidad::class);
    }

    public function carrera()
    {
        return $this->belongsTo(Carrera::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function studySessions()
    {
        return $this->hasMany(StudySession::class);
    }

    public function studyPreference()
    {
        return $this->hasOne(StudyPreference::class);
    }

    public function pomodoroSessions()
    {
        return $this->hasMany(PomodoroSession::class);
    }

    public function insignias()
    {
        return $this->belongsToMany(Insignia::class)->withTimestamps();
    }

    public function reportesRecibidos()
    {
        return $this->hasMany(Reporte::class, 'reported_user_id');
    }
}