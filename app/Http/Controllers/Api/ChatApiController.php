<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\ChMessage;
use App\Models\ChFavorite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Chatify\Facades\ChatifyMessenger as Chatify;
use Pusher\Pusher;
use App\Http\Controllers\Api\NotificationController;

class ChatApiController extends Controller
{
    // 1. LISTA DE CONTACTOS
    public function getContactsJSON(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json([], 401);

        $users = User::where('id', '!=', $user->id)
            ->get()
            ->map(function ($contact) use ($user) {
                // Último mensaje
                $lastMessage = ChMessage::where(function ($q) use ($user, $contact) {
                    $q->where('from_id', $user->id)->where('to_id', $contact->id);
                })->orWhere(function ($q) use ($user, $contact) {
                    $q->where('from_id', $contact->id)->where('to_id', $user->id);
                })->orderBy('created_at', 'desc')->first();

                $unreadCount = ChMessage::where('from_id', $contact->id)
                    ->where('to_id', $user->id)
                    ->where('seen', 0)->count();

                // Construir URL completa para el avatar usando la estructura real de la app
                $avatarUrl = $contact->imagen
                    ? url('perfiles/' . $contact->imagen)
                    : null;

                return [
                    'id' => $contact->id,
                    'name' => $contact->name ?? $contact->username,
                    'username' => $contact->username, // Para redirección
                    'imagen_url' => $avatarUrl,
                    'last_message' => $lastMessage ? ($lastMessage->attachment ? '📎 Archivo' : $lastMessage->body) : null,
                    'last_message_time' => $lastMessage ? $lastMessage->created_at->diffForHumans() : null,
                    'last_message_date' => $lastMessage ? $lastMessage->created_at : null,
                    'unread_count' => $unreadCount,
                    'is_online' => $contact->active_status
                ];
            });

        $contacts = $users->filter(fn($u) => $u['last_message'] !== null)->values();
        return response()->json($contacts->sortByDesc('last_message_date')->values());
    }

    // 2. OBTENER MENSAJES (Rutas Relativas)
    public function fetchMessagesJSON(Request $request)
    {
        $auth_id = Auth::id();
        $user_id = $request->id;

        $messages = ChMessage::where(function ($q) use ($auth_id, $user_id) {
            $q->where('from_id', $auth_id)->where('to_id', $user_id);
        })->orWhere(function ($q) use ($auth_id, $user_id) {
            $q->where('from_id', $user_id)->where('to_id', $auth_id);
        })->orderBy('created_at', 'asc')->get()->map(function ($msg) {
            // Procesar adjuntos
            if ($msg->attachment) {
                $att = json_decode($msg->attachment);
                if (is_object($att)) {
                    // DEVOLVER RUTA RELATIVA
                    $msg->attachment_url = '/storage/attachments/' . ($att->new_name ?? '');
                    $msg->attachment_type = $att->type ?? 'file';
                }
            }
            return $msg;
        });

        return response()->json($messages);
    }

    // 3. ENVIAR MENSAJE
    public function sendMessageJSON(Request $request)
    {
        $user = Auth::user();

        $attachment = null;
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $file->storeAs('attachments', $fileName, 'public');

            $attachment = json_encode([
                'new_name' => $fileName,
                'old_name' => $file->getClientOriginalName(),
                'type' => $file->getClientOriginalExtension(),
                'size' => $file->getSize(),
            ]);
        }

        $message = new ChMessage();
        $message->id = Str::uuid();
        $message->from_id = $user->id;
        $message->to_id = $request->id;
        $message->body = $request->message;
        $message->attachment = $attachment;
        $message->seen = 0;
        $message->created_at = now();
        $message->updated_at = now();
        $message->save();

        // Datos Pusher
        $pusherData = [
            'from_id' => $user->id,
            'to_id' => $request->id,
            'body' => $message->body,
            'attachment' => $attachment,
            'id' => $message->id,
            'created_at' => $message->created_at->toISOString(),
            'seen' => 0
        ];

        if ($attachment) {
            $att = json_decode($attachment);
            $attachmentUrl = '/storage/attachments/' . $att->new_name;
            $message->attachment_url = $attachmentUrl;
            $pusherData['attachment_url'] = $attachmentUrl;
        }

        Chatify::push('private-chatify.' . $request->id, 'messaging', $pusherData);

        // Enviar notificación push al destinatario
        $messageBody = $message->body ?? '📎 Te envió una imagen';
        NotificationController::sendPushNotification(
            $request->id,
            $user->name ?? $user->username,
            $messageBody,
            [
                'type' => 'message',
                'chat_user_id' => (string) $user->id,
                'message_id' => (string) $message->id,
                'sender_name' => $user->name ?? $user->username,
                'sender_avatar' => $user->imagen ? url('perfiles/' . $user->imagen) : null,
            ]
        );

        return response()->json($message);
    }

    // 4. FAVORITOS
    public function toggleFavorite(Request $request)
    {
        $user = Auth::user();
        $targetId = $request->id;
        $favorite = ChFavorite::where('user_id', $user->id)->where('favorite_id', $targetId)->first();

        if ($favorite) {
            $favorite->delete();
            return response()->json(['status' => 'removed']);
        } else {
            $newFav = new ChFavorite();
            $newFav->id = Str::uuid();
            $newFav->user_id = $user->id;
            $newFav->favorite_id = $targetId;
            $newFav->save();
            return response()->json(['status' => 'added']);
        }
    }

    public function checkFavorite(Request $request)
    {
        $exists = ChFavorite::where('user_id', Auth::id())->where('favorite_id', $request->id)->exists();
        return response()->json(['is_favorite' => $exists]);
    }

    public function getFavoritesJSON(Request $request)
    {
        $user = Auth::user();
        $favoriteIds = ChFavorite::where('user_id', $user->id)->pluck('favorite_id');
        $favorites = User::whereIn('id', $favoriteIds)->get()->map(function ($fav) {
            $avatarUrl = $fav->imagen ? url('perfiles/' . $fav->imagen) : null;
            return [
                'id' => $fav->id,
                'name' => $fav->name ?? $fav->username,
                'imagen_url' => $avatarUrl
            ];
        });
        return response()->json($favorites);
    }

    // 5. SHARED PHOTOS
    public function getSharedPhotos(Request $request)
    {
        $user = Auth::user();
        $userId = $request->id;
        $images = ChMessage::where(function ($q) use ($user, $userId) {
            $q->where('from_id', $user->id)->where('to_id', $userId);
        })->orWhere(function ($q) use ($user, $userId) {
            $q->where('from_id', $userId)->where('to_id', $user->id);
        })->whereNotNull('attachment')->orderBy('created_at', 'desc')->get()->map(function ($msg) {
            $att = json_decode($msg->attachment);
            if (!is_object($att)) return null;
            $ext = strtolower($att->type ?? '');
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) {
                return [
                    'id' => $msg->id,
                    'url' => '/storage/attachments/' . ($att->new_name ?? ''), // Ruta relativa
                    'name' => $att->old_name ?? 'Imagen'
                ];
            }
        })->filter()->values();
        return response()->json($images);
    }

    // 6. PUSHER AUTH
    public function pusherAuth(Request $request)
    {
        $user = Auth::user();
        if (!$request->channel_name || !$request->socket_id) return response()->json(['message' => 'Datos faltantes'], 400);

        $channelId = str_replace('private-chatify.', '', $request->channel_name);
        if ((string)$channelId !== (string)$user->id) return response()->json(['message' => 'Unauthorized'], 403);

        try {
            $pusher = new Pusher(
                config('chatify.pusher.key'),
                config('chatify.pusher.secret'),
                config('chatify.pusher.app_id'),
                config('chatify.pusher.options')
            );
            $auth = $pusher->socket_auth($request->channel_name, $request->socket_id);
            return response($auth, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function typing(Request $request)
    {
        Chatify::push('private-chatify.' . $request->id, 'client-typing', ['from_id' => Auth::id(), 'typing' => true]);
        return response()->json(['status' => 'ok']);
    }

    public function makeSeen(Request $request)
    {
        $user = Auth::user();
        ChMessage::where('from_id', $request->id)->where('to_id', $user->id)->where('seen', 0)->update(['seen' => 1]);
        Chatify::push('private-chatify.' . $request->id, 'client-seen', ['from_id' => $user->id, 'seen' => true]);
        return response()->json(['status' => 'seen']);
    }

    // 7. CONTADOR DE MENSAJES NO LEÍDOS
    /**
     * Obtener el total de mensajes no leídos de todos los chats
     * 
     * GET /api/chat/unread-count
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function unreadCount()
    {
        try {
            $userId = Auth::id();

            // Contar mensajes no leídos donde el usuario es el destinatario
            $count = ChMessage::where('to_id', $userId)
                ->where('seen', 0)
                ->count();

            return response()->json([
                'success' => true,
                'count' => $count
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al contar mensajes',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
