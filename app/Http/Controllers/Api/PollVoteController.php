<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PollVoteController extends Controller
{
    public function store(Request $request, $pollId)
    {
        try {
            $request->validate([
                'option_id' => 'required|integer|exists:poll_options,id',
            ]);

            $user = Auth::user();
            $poll = Poll::findOrFail($pollId);

            if ($poll->isClosed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta encuesta ha cerrado'
                ], 422);
            }

            if ($poll->userHasVoted($user->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya has votado en esta encuesta'
                ], 422);
            }

            $option = PollOption::findOrFail($request->option_id);
            if ($option->poll_id !== $poll->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'La opción no pertenece a esta encuesta'
                ], 422);
            }

            PollVote::create([
                'poll_id' => $poll->id,
                'option_id' => $request->option_id,
                'user_id' => $user->id,
            ]);

            $poll->load(['options.votes']);
            $totalVotes = $poll->votes()->count();

            $options = $poll->options->map(function ($option) use ($totalVotes) {
                $voteCount = $option->votes->count();
                return [
                    'id' => $option->id,
                    'text' => $option->text,
                    'vote_count' => $voteCount,
                    'percentage' => $totalVotes > 0 ? round(($voteCount / $totalVotes) * 100, 1) : 0,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'options' => $options,
                    'total_votes' => $totalVotes,
                    'user_has_voted' => true,
                ],
                'message' => 'Voto registrado exitosamente'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Encuesta u opción no encontrada'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos inválidos',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar el voto',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
