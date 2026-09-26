<?php

namespace App\Jobs;

use App\Services\MentionService;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMentionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $authorId,
        public string $text,
        public string $mentionableType,
        public int|string $mentionableId,
    ) {
    }

    public function handle(MentionService $mentionService, NotificationService $notificationService): void
    {
        $usernames = $mentionService->extractUsernames($this->text);
        $mentionedUserIds = $mentionService->validateMutualFriends($this->authorId, $usernames);

        $mentionService->saveMentions($this->authorId, [
            'type' => $this->mentionableType,
            'id' => $this->mentionableId,
        ], $mentionedUserIds);

        foreach ($mentionedUserIds as $mentionedUserId) {
            $notificationService->createMentionNotification(
                $this->authorId,
                $mentionedUserId,
                $this->mentionableType,
                $this->mentionableId
            );
        }
    }
}
