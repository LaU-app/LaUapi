<?php

namespace App\Services\Agent;

use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\AssistantDecision;
use App\Models\Product;
use App\Models\User;
use App\Services\TypeSafe\JevClient;
use App\Services\TypeSafe\JevException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Agente de compras de LaU Marketplace.
 *
 * - Un modelo de lenguaje abierto (Qwen vía Ollama, o el que se configure) conversa, planifica
 *   y decide qué herramienta usar: buscar, ver detalles, reseñas, preparar la compra o revisar pedidos.
 * - Jev (TypeSafe) aporta juicios tipados: dentro de la búsqueda ordena y elige evidencia; antes de
 *   responder dice si el mensaje es sobre compras; después verifica frase por frase que lo que dice
 *   el agente esté respaldado por los datos reales.
 * - El código decide: umbrales, límites del ciclo, qué se muestra y qué se corrige.
 * - Nunca paga: la compra la confirma el estudiante en PayPal.
 */
class ShoppingAgent
{
    public const MAX_STEPS = 5;

    public const MAX_TOOL_CALLS = 8;

    public const HISTORY_MESSAGES = 12;

    public const OFF_TOPIC_REPLY = 'Solo puedo ayudarte con compras en LaU Marketplace: buscar productos de comercios verificados, compararlos, ver reseñas, preparar la compra o revisar tus pedidos. ¿Qué necesitás?';

    /** Duración de cada llamada al modelo en el turno actual (auditoría de rendimiento). */
    private array $timings = [];

    public function __construct(
        private readonly LlmClient $llm,
        private readonly JevClient $jev,
        private readonly AgentTools $tools,
    ) {}

    public function isAvailable(): bool
    {
        return $this->llm->isConfigured();
    }

    /** Mensaje inicial cuando el chat se abre desde una búsqueda inteligente (sin gastar el modelo). */
    public function seedFromDecision(AgentConversation $conversation, AssistantDecision $decision): void
    {
        // Los mismos productos que mostró la búsqueda: los que no descartó Jev (coincidencia > 0.3).
        $ids = collect($decision->candidates ?? [])
            ->filter(fn ($c) => ($c['match'] ?? 0) > AgentQuestions::NO)
            ->sortByDesc('match')
            ->pluck('product_id')->take(3)->values()->all();
        $products = Product::with('store', 'category', 'images')->whereIn('id', $ids)->where('status', 'active')->get()
            ->sortBy(fn ($p) => array_search($p->id, $ids, true))->values();

        $conversation->messages()->create(['role' => 'user', 'content' => $decision->query]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $products->isNotEmpty()
                ? 'Estos son los resultados de tu búsqueda. Preguntame lo que quieras: puedo compararlos, buscar reseñas o preparar la compra.'
                : 'No encontré productos para esa búsqueda. Contame un poco más de lo que necesitás.',
            'cards' => $products->isNotEmpty() ? [[
                'type' => 'products',
                'decision_id' => $decision->id,
                'items' => $products->map(function (Product $p) use ($decision) {
                    $card = AgentTools::card($p);
                    $candidate = collect($decision->candidates)->firstWhere('product_id', $p->id);
                    $card['match_probability'] = $candidate['match'] ?? null;
                    $card['within_budget'] = $candidate['within_budget'] ?? null;
                    $card['recommended'] = $decision->recommended_product_id === $p->id;

                    return $card;
                })->all(),
            ]] : null,
            'meta' => ['seeded_from_decision' => $decision->id],
        ]);
        $conversation->forceFill(['last_message_at' => now()])->save();
    }

    public function reply(AgentConversation $conversation, string $text): AgentMessage
    {
        $user = $conversation->user;
        $started = microtime(true);
        $history = $conversation->messages()->latest('id')->limit(self::HISTORY_MESSAGES)->get()->reverse()->values();

        $userMessage = $conversation->messages()->create(['role' => 'user', 'content' => $text]);
        $meta = ['model' => $this->llm->model()];

        // 1) Jev: ¿es sobre el Marketplace? Si claramente no, respuesta fija sin llamar al modelo.
        $onTopic = $this->onTopic($text, $history, $meta);
        if ($onTopic !== null && $onTopic <= AgentQuestions::NO) {
            return $this->save($conversation, self::OFF_TOPIC_REPLY, [], [], $meta + ['gate' => 'off_topic'], $started);
        }

        // 2) Modelo de lenguaje + herramientas.
        $messages = array_merge([['role' => 'system', 'content' => $this->systemPrompt($user)]], $this->historyMessages($history), [
            ['role' => 'user', 'content' => $text],
        ]);
        $facts = $this->knownFacts($history);
        $cards = [];
        $steps = [];

        $this->timings = [];
        try {
            $reply = $this->loop($user, $messages, $facts, $cards, $steps);
        } catch (LlmException $e) {
            $userMessage->delete();
            throw $e;
        }

        // 3) Jev verifica la respuesta contra los datos reales; el código corrige si hace falta.
        $reply = $this->verified($reply, $facts, $messages, $meta);
        $meta['llm_ms'] = $this->timings;

        if ($this->tools->lastDecisionId) {
            $conversation->forceFill(['assistant_decision_id' => $this->tools->lastDecisionId]);
        }

        return $this->save($conversation, $reply, self::mergeCards($cards), $steps, $meta, $started);
    }

    /** Ciclo del agente: el modelo pide herramientas hasta que responde (con límites en código). */
    private function loop(User $user, array &$messages, array &$facts, array &$cards, array &$steps): string
    {
        $calls = 0;

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $canUseTools = $calls < self::MAX_TOOL_CALLS;
            $answer = $this->chat($messages, $canUseTools ? AgentTools::definitions() : []);

            if ($answer['tool_calls'] === [] || ! $canUseTools) {
                return $answer['content'];
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $answer['content'],
                'tool_calls' => array_map(fn ($c) => [
                    'id' => $c['id'], 'type' => 'function',
                    'function' => ['name' => $c['name'], 'arguments' => $c['raw']],
                ], $answer['tool_calls']),
            ];

            foreach ($answer['tool_calls'] as $call) {
                $calls++;
                $out = $calls <= self::MAX_TOOL_CALLS
                    ? $this->tools->run($user, $call['name'], $call['arguments'])
                    : ['result' => ['error' => 'Límite de acciones alcanzado; respondé con lo que ya sabés.'], 'facts' => [], 'cards' => []];

                $facts = array_merge($facts, $out['facts']);
                array_push($cards, ...$out['cards']);
                $steps[] = [
                    'tool' => $call['name'],
                    'label' => AgentTools::LABELS[$call['name']] ?? 'Revisó datos',
                    'ok' => ! isset($out['result']['error']),
                ];
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => json_encode($out['result'], JSON_UNESCAPED_UNICODE)];
            }
        }

        // Si el modelo sigue pidiendo herramientas, se le pide que responda con lo que tiene.
        $messages[] = ['role' => 'user', 'content' => 'Respondeme ahora con lo que ya encontraste, en 2 a 4 oraciones.'];

        return $this->chat($messages)['content'];
    }

    /**
     * Truth table de la verificación (por frase): afirma un dato (≥ 0.7) y no está respaldado (≤ 0.3)
     * → la frase se marca. Con frases marcadas: 1 corrección del modelo; si siguen marcadas, se quitan.
     * Cualquier otra combinación se deja (las tarjetas siempre muestran los datos reales).
     */
    private function verified(string $reply, array $facts, array $messages, array &$meta): string
    {
        if ($reply === '') {
            return 'No pude armar una respuesta. ¿Me lo podés decir de otra forma?';
        }
        if (! $this->jev->isConfigured()) {
            return $reply;
        }

        $flagged = $this->unsupported($reply, $facts, $meta, 'grounding');
        if ($flagged === null || $flagged === []) {
            return $reply;
        }

        $messages[] = ['role' => 'assistant', 'content' => $reply];
        $messages[] = ['role' => 'user', 'content' => 'Estas frases tuyas mencionan datos que no salieron de las herramientas: «'
            .implode('» «', $flagged).'». Reescribí tu respuesta usando solo datos de las herramientas, en 2 a 4 oraciones.'];

        try {
            $retry = $this->chat($messages)['content'];
        } catch (LlmException) {
            $retry = '';
        }
        $meta['corrected'] = true;

        $candidate = $retry !== '' ? $retry : $reply;
        $stillFlagged = $this->unsupported($candidate, $facts, $meta, 'grounding_retry') ?? [];
        if ($stillFlagged === []) {
            return $candidate;
        }

        $kept = collect(AgentQuestions::sentences($candidate))->reject(fn ($s) => in_array($s, $stillFlagged, true))->implode(' ');
        $meta['removed_sentences'] = count($stillFlagged);

        return $kept !== '' ? $kept.' Revisá los datos exactos en las tarjetas.' : 'Te dejo los datos exactos en las tarjetas de abajo.';
    }

    /** @return list<string>|null frases marcadas, o null si Jev no respondió */
    private function unsupported(string $reply, array $facts, array &$meta, string $key): ?array
    {
        $sentences = AgentQuestions::sentences($reply);
        if ($sentences === []) {
            return [];
        }

        $req = AgentQuestions::grounding($facts, $sentences);
        try {
            $result = $this->jev->ask($req['state'], $req['questions']);
        } catch (JevException $e) {
            Log::warning('Agente: verificación de Jev no disponible', ['reason' => $e->reason]);
            $meta[$key] = 'unavailable';

            return null;
        }

        $flagged = [];
        $audit = [];
        foreach ($sentences as $k => $sentence) {
            $claim = $result->noul("claim_{$k}");
            $supported = $result->noul("supported_{$k}");
            $audit[$k] = ['claim' => round($claim, 3), 'supported' => round($supported, 3)];
            if ($claim >= AgentQuestions::YES && $supported <= AgentQuestions::NO) {
                $flagged[] = $sentence;
            }
        }
        $meta[$key] = $audit;

        return $flagged;
    }

    private function onTopic(string $text, $history, array &$meta): ?float
    {
        if (! $this->jev->isConfigured()) {
            return null;
        }

        $recent = $history->take(-4)->map(fn (AgentMessage $m) => [
            'from' => $m->role === 'user' ? 'student' : 'assistant',
            'text' => Str::limit($m->content, 300),
        ])->values()->all();

        $req = AgentQuestions::gate($text, $recent);
        try {
            $p = $this->jev->ask($req['state'], $req['questions'])->noul('on_topic');
        } catch (JevException $e) {
            // Sin el juicio de Jev no se bloquea: el modelo igual tiene la regla de solo ayudar con compras.
            $meta['gate'] = 'unavailable';

            return null;
        }
        $meta['on_topic'] = round($p, 3);

        return $p;
    }

    private function systemPrompt(User $user): string
    {
        $name = Str::of((string) $user->name)->before(' ')->limit(40, '');
        $campus = $user->universidad_id ? \App\Models\Universidad::find($user->universidad_id) : null;
        $campusLine = $campus ? " Estudia en {$campus->nombre}." : '';

        return <<<PROMPT
        Sos el asistente de compras de LaU Marketplace, la tienda dentro de LaU (la red social de universitarios de El Salvador). Ahí solo venden comercios verificados por LaU y se paga con PayPal. Todo se entrega dentro de un campus universitario (delivery_campus). Estás hablando con {$name}.{$campusLine}

        Reglas:
        1. Todo dato de productos (nombre, precio, stock, comercio, campus de entrega, características, reseñas) sale de tus herramientas. Nunca inventes productos, precios, stock ni reseñas.
        2. Para buscar, usá search_products con lo que necesita el estudiante en sus palabras (producto, materia o uso, y presupuesto si lo dijo). Para comparar o responder detalles, get_product_details. Para reseñas o videos, get_product_reviews. Para encontrar el producto en otras tiendas o sitios web, find_product_on_web. Para sus compras, get_my_orders.
        3. No podés cobrar ni pagar. Cuando el estudiante quiera comprar, usá propose_purchase: la app le muestra el resumen y el botón de PayPal, y él confirma.
        4. Si algo no está en LaU Marketplace, decilo y ofrecé buscar algo parecido.
        5. Respondé en español de El Salvador con voseo ("buscás", "tenés"), en 2 a 4 oraciones. La app ya muestra tarjetas con los productos: no repitas listas largas, explicá por qué conviene uno u otro.
        6. Solo ayudás con compras en LaU Marketplace.
        PROMPT;
    }

    /** Historial para el modelo: texto de cada turno y, en los del agente, qué productos mostró. */
    private function historyMessages($history): array
    {
        return $history->map(function (AgentMessage $m) {
            $content = $m->content;
            if ($m->role === 'assistant' && ($shown = self::shownProducts($m->cards ?? [])) !== []) {
                $content .= "\n[Productos mostrados: ".implode('; ', array_map(
                    fn ($p) => "product_id {$p['id']}: {$p['name']}, \${$p['price']}, stock {$p['stock']}",
                    $shown,
                )).']';
            }

            return ['role' => $m->role === 'user' ? 'user' : 'assistant', 'content' => $content];
        })->all();
    }

    /** Datos ya mostrados en el chat (para que una referencia como "la primera" se pueda verificar). */
    private function knownFacts($history): array
    {
        $facts = [];
        foreach ($history as $m) {
            foreach (self::shownProducts($m->cards ?? []) as $p) {
                $facts['p'.$p['id']] = array_filter([
                    'name' => $p['name'], 'price' => $p['price'].' USD', 'stock' => $p['stock'],
                    'store' => $p['store'] ?? null, 'description' => $p['description'] ?? null,
                ], fn ($v) => $v !== null);
            }
        }

        return $facts;
    }

    private static function shownProducts(array $cards): array
    {
        $out = [];
        foreach ($cards as $card) {
            $items = match ($card['type'] ?? null) {
                'products' => $card['items'] ?? [],
                'purchase' => [$card],
                default => [],
            };
            foreach ($items as $item) {
                if (isset($item['product']['id'])) {
                    $out[$item['product']['id']] = $item['product'] + ['store' => $item['store']['name'] ?? null];
                }
            }
        }

        return array_values($out);
    }

    /** Une las tarjetas del turno: compra primero, una sola lista de productos (sin repetidos), videos. */
    private static function mergeCards(array $cards): array
    {
        $purchase = array_values(array_filter($cards, fn ($c) => $c['type'] === 'purchase'));
        $videos = collect($cards)->where('type', 'videos')->keyBy(fn ($c) => $c['product']['id'])->values()->all();

        $lists = array_values(array_filter($cards, fn ($c) => $c['type'] === 'products'));
        $items = [];
        $decisionId = null;
        foreach (array_reverse($lists) as $list) {
            $decisionId ??= $list['decision_id'];
            foreach ($list['items'] as $item) {
                $items[$item['product']['id']] ??= $item;
            }
        }
        $purchasedIds = array_map(fn ($c) => $c['product']['id'], $purchase);
        $items = array_values(array_filter($items, fn ($i) => ! in_array($i['product']['id'], $purchasedIds, true)));

        return array_merge(
            array_slice($purchase, -1),
            $items ? [['type' => 'products', 'decision_id' => $decisionId, 'items' => array_slice($items, 0, 4)]] : [],
            $videos,
        );
    }

    private function chat(array $messages, array $tools = []): array
    {
        $t = microtime(true);
        try {
            return $this->llm->chat($messages, $tools);
        } finally {
            $this->timings[] = (int) round((microtime(true) - $t) * 1000);
        }
    }

    private function save(AgentConversation $conversation, string $content, array $cards, array $steps, array $meta, float $started): AgentMessage
    {
        $meta['ms'] = (int) round((microtime(true) - $started) * 1000);

        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => Str::limit($content, 4000),
            'cards' => $cards ?: null,
            'steps' => $steps ?: null,
            'meta' => $meta,
        ]);
        $conversation->forceFill(['last_message_at' => now()])->save();

        return $message;
    }
}
