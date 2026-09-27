<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Poll;
use App\Models\PollOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PollController extends Controller
{
    public function index()
    {
        try {
            $polls = Poll::with(['user', 'options.votes'])
                ->withCount('votes')
                ->where('expires_at', '>', now())
                ->orWhere('expires_at', '<=', now())
                ->latest()
                ->paginate(20);

            $polls->getCollection()->transform(function ($poll) {
                if ($poll->user && $poll->user->imagen) {
                    $poll->user->imagen_url = url('perfiles/' . $poll->user->imagen);
                }

                $totalVotes = $poll->votes_count;
                $poll->options->transform(function ($option) use ($totalVotes) {
                    $voteCount = $option->votes->count();
                    $option->vote_count = $voteCount;
                    $option->percentage = $totalVotes > 0 ? round(($voteCount / $totalVotes) * 100, 1) : 0;
                    unset($option->votes);
                    return $option;
                });

                $poll->is_closed = $poll->isClosed();
                $poll->time_remaining = $poll->time_remaining;
                $poll->user_has_voted = Auth::check() ? $poll->userHasVoted(Auth::id()) : false;

                return $poll;
            });

            return response()->json([
                'success' => true,
                'data' => $polls
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener encuestas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'question' => 'required|string|max:255',
                'options' => 'required|array|min:2|max:5',
                'options.*.text' => 'required|string|max:100',
                'expires_in_hours' => 'required|integer|min:1|max:72',
            ]);

            $user = Auth::user();
            $expiresAt = now()->addHours($request->expires_in_hours);

            $poll = Poll::create([
                'user_id' => $user->id,
                'question' => $request->question,
                'visibility' => $request->visibility ?? 'public',
                'expires_at' => $expiresAt,
            ]);

            foreach ($request->options as $optionData) {
                PollOption::create([
                    'poll_id' => $poll->id,
                    'text' => $optionData['text'],
                ]);
            }

            $poll->load(['user', 'options']);
            if ($poll->user && $poll->user->imagen) {
                $poll->user->imagen_url = url('perfiles/' . $poll->user->imagen);
            }

            $poll->options->each(function ($option) {
                $option->vote_count = 0;
                $option->percentage = 0;
            });

            $poll->is_closed = false;
            $poll->time_remaining = $poll->time_remaining;
            $poll->user_has_voted = false;
            $poll->votes_count = 0;

            return response()->json([
                'success' => true,
                'data' => $poll,
                'message' => 'Encuesta creada exitosamente'
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos inválidos',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la encuesta',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $poll = Poll::with(['user', 'options.votes'])
                ->withCount('votes')
                ->findOrFail($id);

            if ($poll->user && $poll->user->imagen) {
                $poll->user->imagen_url = url('perfiles/' . $poll->user->imagen);
            }

            $totalVotes = $poll->votes_count;
            $poll->options->transform(function ($option) use ($totalVotes) {
                $voteCount = $option->votes->count();
                $option->vote_count = $voteCount;
                $option->percentage = $totalVotes > 0 ? round(($voteCount / $totalVotes) * 100, 1) : 0;
                unset($option->votes);
                return $option;
            });

            $poll->is_closed = $poll->isClosed();
            $poll->time_remaining = $poll->time_remaining;
            $poll->user_has_voted = Auth::check() ? $poll->userHasVoted(Auth::id()) : false;

            return response()->json([
                'success' => true,
                'data' => $poll
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Encuesta no encontrada'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la encuesta',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $poll = Poll::findOrFail($id);
            $user = Auth::user();

            if ($poll->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para eliminar esta encuesta'
                ], 403);
            }

            $poll->delete();

            return response()->json([
                'success' => true,
                'message' => 'Encuesta eliminada exitosamente'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Encuesta no encontrada'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la encuesta',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
