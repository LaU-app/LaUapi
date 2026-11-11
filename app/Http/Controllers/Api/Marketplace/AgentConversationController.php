<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\AssistantDecision;
use App\Services\Agent\LlmException;
use App\Services\Agent\ShoppingAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Chats del estudiante con el agente de compras (historial propio). */
class AgentConversationController extends MarketplaceController
{
    public function __construct(private readonly ShoppingAgent $agent) {}

    public function index(Request $request): JsonResponse
    {
        $conversations = AgentConversation::where('user_id', $request->user()->id)
            ->withCount('messages')
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->limit(50)->get(['id', 'title', 'last_message_at', 'created_at']);

        return $this->respond([
            'available' => $this->agent->isAvailable(),
            'conversations' => $conversations,
        ]);
    }

    /** Crea un chat. Si viene de una búsqueda inteligente, arranca con esos resultados. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => 'required_without:decision_id|nullable|string|min:2|max:1000',
            'decision_id' => 'required_without:message|nullable|integer',
        ], [
            'message.required_without' => 'Escribí tu mensaje.',
        ]);

        if (! $this->agent->isAvailable()) {
            return $this->unavailable();
        }

        $decision = isset($data['decision_id'])
            ? AssistantDecision::where('user_id', $request->user()->id)->find($data['decision_id'])
            : null;

        $conversation = AgentConversation::create([
            'user_id' => $request->user()->id,
            'title' => Str::limit(trim($data['message'] ?? $decision?->query ?? 'Nuevo chat'), 80, '…'),
            'assistant_decision_id' => $decision?->id,
            'last_message_at' => now(),
        ]);

        if ($decision) {
            $this->agent->seedFromDecision($conversation, $decision);
        }

        if (! empty($data['message'])) {
            $failure = $this->runTurn($conversation, $data['message']);
            if ($failure) {
                if ($conversation->messages()->doesntExist()) {
                    $conversation->delete();
                }

                return $failure;
            }
        }

        return $this->respond($this->present($conversation->fresh()), null, 201);
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        return $this->respond($this->present($this->owned($request, $conversation)));
    }

    public function message(Request $request, int $conversation): JsonResponse
    {
        $data = $request->validate(['message' => 'required|string|min:1|max:1000'], [
            'message.required' => 'Escribí tu mensaje.',
        ]);

        $record = $this->owned($request, $conversation);
        if ($failure = $this->runTurn($record, $data['message'])) {
            return $failure;
        }

        return $this->respond($this->present($record->fresh()));
    }

    public function destroy(Request $request, int $conversation): JsonResponse
    {
        $this->owned($request, $conversation)->delete();

        return $this->respond(null, 'Chat eliminado.');
    }

    private function runTurn(AgentConversation $conversation, string $text): ?JsonResponse
    {
        if (! $this->agent->isAvailable()) {
            return $this->unavailable();
        }

        // Un modelo local puede tardar: el turno tiene su propio límite de tiempo.
        @set_time_limit(max(60, (int) config('services.agent.timeout') * 3));

        try {
            $this->agent->reply($conversation, trim($text));
        } catch (LlmException $e) {
            Log::warning('Agente de compras: modelo no disponible', ['reason' => $e->reason, 'status' => $e->httpStatus]);

            return $this->unavailable();
        }

        return null;
    }

    private function owned(Request $request, int $id): AgentConversation
    {
        return AgentConversation::where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function present(AgentConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'messages' => $conversation->messages()->get()->map(fn (AgentMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'cards' => $m->cards ?? [],
                'steps' => $m->steps ?? [],
                'created_at' => $m->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'agent_unavailable',
            'message' => 'El chat no está disponible en este momento. La búsqueda sigue funcionando.',
        ], 503);
    }
}
