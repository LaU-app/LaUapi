<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Pusher\Pusher;

class RealtimeAuthController extends Controller
{
    public function __invoke(Request $request)
    {
        $expectedChannel = 'private-study.'.$request->user()->id;
        $data = $request->validate([
            'socket_id' => ['required', 'string', 'max:100'],
            'channel_name' => ['required', 'string', Rule::in([$expectedChannel])],
        ]);
        $pusher = new Pusher(
            config('chatify.pusher.key'),
            config('chatify.pusher.secret'),
            config('chatify.pusher.app_id'),
            config('chatify.pusher.options'),
        );
        $auth = $pusher->socket_auth($data['channel_name'], $data['socket_id']);

        return response()->json(json_decode($auth, true, flags: JSON_THROW_ON_ERROR));
    }
}
