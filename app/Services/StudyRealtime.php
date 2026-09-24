<?php

namespace App\Services;

use App\Models\User;
use Chatify\Facades\ChatifyMessenger as Chatify;
use Illuminate\Support\Facades\Log;

class StudyRealtime
{
    public function notify(User|int $user, array $areas): void
    {
        if (app()->environment('testing') || ! config('chatify.pusher.key')) {
            return;
        }

        $userId = $user instanceof User ? $user->id : $user;
        defer(function () use ($userId, $areas) {
            try {
                Chatify::push('private-study.'.$userId, 'study.updated', [
                    'areas' => array_values(array_unique($areas)),
                    'updated_at' => now()->toISOString(),
                ]);
            } catch (\Throwable $error) {
                Log::warning('Study realtime notification failed', [
                    'user_id' => $userId,
                    'error' => $error->getMessage(),
                ]);
            }
        });
    }
}
