<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\Post;
use App\Models\Comentario;
use App\Http\Controllers\Api\NotificationController; // Importación necesaria
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Crear notificación de follow
     */
    public function createFollowNotification(User $follower, User $followed)
    {
        // No crear notificación si te sigues a ti mismo
        if ($follower->id === $followed->id) {
            return null;
        }

        try {
            // === NUEVA LÓGICA ANTI-SPAM ===
            // Buscar si ya existe una notificación de Follow previa
            $existingNotification = Notification::where('user_id', $followed->id)
                ->where('from_user_id', $follower->id)
                ->where('type', Notification::TYPE_FOLLOW)
                ->first();

            if ($existingNotification) {
                $existingNotification->delete();
            }
            // ==============================

            // Crear notificación en la base de datos
            $notification = Notification::create([
                'user_id' => $followed->id,
                'from_user_id' => $follower->id,
                'type' => Notification::TYPE_FOLLOW,
                'data' => [
                    'follower_username' => $follower->username,
                    'follower_name' => $follower->name,
                    'follower_image' => $follower->imagen
                ]
            ]);


            // Enviar notificación push
            NotificationController::sendPushNotification(
                $followed->id,
                'Nuevo seguidor',
                "{$follower->name} empezó a seguirte",
                [
                    'type' => 'follow',
                    'user_id' => (string) $follower->id,
                    'notification_id' => (string) $notification->id
                ]
            );

            return $notification;
        } catch (\Exception $e) {
            Log::error('Error creating follow notification: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Crear notificación de like
     */
    public function createLikeNotification(User $liker, Post $post)
    {
        // No crear notificación si el like es en tu propio post
        if ($liker->id === $post->user_id) {
            return null;
        }

        try {
            // === NUEVA LÓGICA ANTI-SPAM ===
            // Buscar si ya existe una notificación de Like de este usuario para este post
            $existingNotification = Notification::where('user_id', $post->user_id)
                ->where('from_user_id', $liker->id)
                ->where('type', Notification::TYPE_LIKE)
                ->where('post_id', $post->id)
                ->first();

            // Si ya existe, la eliminamos para no tener duplicados
            // (La nueva notificación aparecerá hasta arriba en el feed del usuario)
            if ($existingNotification) {
                $existingNotification->delete();
            }
            // ==============================

            // Crear notificación en la base de datos
            $notification = Notification::create([
                // ... (tu código actual se mantiene igual)
                'user_id' => $post->user_id,
                'from_user_id' => $liker->id,
                'type' => Notification::TYPE_LIKE,
                'post_id' => $post->id,
                'data' => [
                    'liker_username' => $liker->username,
                    'liker_name' => $liker->name,
                    'liker_image' => $liker->imagen,
                    'post_title' => $post->titulo ?? '',
                    'post_image' => $post->imagen ?? null
                ]
            ]);

            // Enviar notificación push
            // OPCIONAL: Podrías envolver esto en un "if (!$existingNotification)" 
            // si NO quieres que el celular suene otra vez por un re-like.
            NotificationController::sendPushNotification(
                $post->user_id,
                'Nuevo like',
                "{$liker->name} le dio like a tu publicación",
                [
                    'type' => 'like',
                    'post_id' => (string) $post->id,
                    'user_id' => (string) $liker->id,
                    'notification_id' => (string) $notification->id
                ]
            );

            return $notification;
        } catch (\Exception $e) {
            Log::error('Error creating like notification: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Crear notificación de comentario (Nivel superior)
     */
    public function createCommentNotification(User $commenter, Post $post, $commentText = null)
    {
        // No crear notificación si comentas tu propio post
        if ($commenter->id === $post->user_id) {
            return null;
        }

        try {
            $commentPreview = null;
            if ($commentText) {
                $commentPreview = strlen($commentText) > 100
                    ? substr($commentText, 0, 100) . '...'
                    : $commentText;
            }

            // Crear notificación en la base de datos
            $notification = Notification::create([
                'user_id' => $post->user_id,
                'from_user_id' => $commenter->id,
                'type' => Notification::TYPE_COMMENT,
                'post_id' => $post->id,
                'data' => [
                    'commenter_username' => $commenter->username,
                    'commenter_name' => $commenter->name,
                    'commenter_image' => $commenter->imagen,
                    'post_title' => $post->titulo ?? '',
                    'post_image' => $post->imagen ?? null,
                    'comment_preview' => $commentPreview
                ]
            ]);

            // Enviar notificación push
            $pushBody = $commentPreview
                ? "{$commenter->name}: {$commentPreview}"
                : "{$commenter->name} comentó tu publicación";

            NotificationController::sendPushNotification(
                $post->user_id,
                'Nuevo comentario',
                $pushBody,
                [
                    'type' => 'comment',
                    'post_id' => (string) $post->id,
                    'user_id' => (string) $commenter->id,
                    'notification_id' => (string) $notification->id
                ]
            );

            return $notification;
        } catch (\Exception $e) {
            Log::error('Error creating comment notification: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Crea una notificación en la base de datos y envía un Push Notification 
     * al autor del comentario padre cuando se recibe una respuesta (Reply).
     * @param User $sender Usuario que responde
     * @param Comentario $parentComment Comentario al que se está respondiendo
     * @param Comentario $newReply El nuevo comentario (la respuesta)
     * @param Post $post La publicación original
     */
    public function createCommentReplyNotification(User $sender, Comentario $parentComment, Comentario $newReply, Post $post): ?Notification
    {
        try {
            // 1. Determinar el destinatario (autor del comentario padre)
            $recipient = $parentComment->user;

            if (!$recipient || $recipient->id === $sender->id) {
                return null;
            }

            $commentPreview = strlen($newReply->comentario) > 30
                ? substr($newReply->comentario, 0, 30) . '...'
                : $newReply->comentario;
                
            // 2. Crear notificación en la base de datos
            $notification = Notification::create([
                'user_id' => $recipient->id,
                'from_user_id' => $sender->id,
                'type' => 'reply_comment', // Nuevo tipo para diferenciar
                'post_id' => $post->id,
                'data' => [
                    'sender_username' => $sender->username,
                    'sender_name' => $sender->name,
                    'sender_image' => $sender->imagen,
                    'post_id' => (string) $post->id,
                    'parent_comment_id' => (string) $parentComment->id, 
                    'reply_id' => (string) $newReply->id,
                    'comment_preview' => $commentPreview
                ]
            ]);

            // 3. Enviar notificación push
            $pushBody = "{$sender->name} respondió tu comentario: \"{$commentPreview}\"";
            
            NotificationController::sendPushNotification(
                $recipient->id, // ID del destinatario
                'Nueva Respuesta',
                $pushBody,
                [
                    'type' => 'reply_comment', // Tipo para la app
                    'post_id' => (string) $post->id,
                    'user_id' => (string) $sender->id,
                    'notification_id' => (string) $notification->id
                ]
            );

            return $notification;
        } catch (\Exception $e) {
            Log::error('Error creating comment reply notification: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Marcar todas las notificaciones como leídas para un usuario
     */
    public function markAllAsRead(User $user)
    {
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }

    /**
     * Eliminar notificaciones antiguas (más de X días)
     */
    public function cleanOldNotifications($days = 30)
    {
        return Notification::where('created_at', '<', now()->subDays($days))->delete();
    }

    /**
     * Obtener conteo de notificaciones no leídas
     */
    public function getUnreadCount(User $user)
    {
        return $user->unreadNotifications()->count();
    }
}