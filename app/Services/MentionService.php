<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MentionService
{
    public function extractUsernames(string $text): array
    {
        preg_match_all('/@([a-zA-Z0-9_]+)/', $text, $matches);

        return array_values(array_unique(array_map(
            static fn (string $username): string => strtolower($username),
            $matches[1] ?? []
        )));
    }

    public function validateMutualFriends(int $authorId, array $usernames): array
    {
        if ($usernames === []) {
            return [];
        }

        return DB::table('users')
            ->select('users.id')
            ->whereIn('users.username', $usernames)
            ->where('users.id', '!=', $authorId)
            ->whereExists(function ($query) use ($authorId) {
                $query->selectRaw('1')
                    ->from('followers')
                    ->whereColumn('followers.user_id', 'users.id')
                    ->where('followers.follower_id', $authorId);
            })
            ->whereExists(function ($query) use ($authorId) {
                $query->selectRaw('1')
                    ->from('followers')
                    ->whereColumn('followers.follower_id', 'users.id')
                    ->where('followers.user_id', $authorId);
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function saveMentions(int $authorId, array $targetEntity, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $timestamp = now();
        $mentions = array_map(static function (int $userId) use ($authorId, $targetEntity, $timestamp): array {
            return [
                'mentioned_user_id' => $userId,
                'author_id' => $authorId,
                'mentionable_type' => $targetEntity['type'],
                'mentionable_id' => $targetEntity['id'],
                'created_at' => $timestamp,
            ];
        }, array_values(array_unique(array_map('intval', $userIds))));

        DB::table('mentions')->insertOrIgnore($mentions);
    }
}
