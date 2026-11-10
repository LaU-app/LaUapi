<?php

namespace Tests\Feature;

use App\Models\AgentConversation;
use App\Models\AssistantDecision;
use App\Models\Product;
use App\Models\User;
use App\Services\Marketplace\SmartShoppingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

/** Agente de compras: modelo de lenguaje (simulado) + herramientas + juicios de Jev (simulados). */
class ShoppingAgentTest extends TestCase
{
    use MarketplaceFixtures;
    use RefreshDatabase;

    private const LLM = 'http://llm.test/v1/chat/completions';

    private const JEV = 'https://api.typesafe.ai/v1/systemone';

    private User $student;

    private Product $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFakeServices();
        config(['services.agent' => [
            'base_url' => 'http://llm.test/v1', 'api_key' => null, 'model' => 'qwen3.5:9b', 'timeout' => 30, 'reasoning' => 'low',
        ]]);

        $this->student = User::factory()->create(['name' => 'Ana López']);
        $this->calculator = $this->productFor($this->verifiedStore());
        Sanctum::actingAs($this->student);
    }

    /** La búsqueda (que internamente usa Jev) se simula: aquí se prueba el agente. */
    private function fakeSearch(): void
    {
        $this->mock(SmartShoppingService::class, function ($mock) {
            $mock->shouldReceive('plan')->andReturnUsing(function (User $user, string $query) {
                $decision = AssistantDecision::create([
                    'user_id' => $user->id, 'query' => $query, 'outcome' => 'recommendation', 'model' => 'jev-1.14.0',
                    'budget_source' => 'none', 'answers' => [], 'recommended_product_id' => $this->calculator->id,
                    'candidates' => [['product_id' => $this->calculator->id, 'price' => '18.50', 'match' => 0.96, 'within_budget' => null]],
                ]);

                return [
                    'decision_id' => $decision->id, 'outcome' => 'recommendation', 'model' => 'jev-1.14.0',
                    'budget' => ['amount' => null, 'source' => 'none', 'unclear_amounts' => []],
                    'interpretation' => [],
                    'recommendations' => [[
                        'product' => [
                            'id' => $this->calculator->id, 'name' => $this->calculator->name, 'description' => $this->calculator->description,
                            'price' => '18.50', 'currency' => 'USD', 'stock' => 5, 'image_url' => null, 'category' => 'Electrónica',
                            'condition' => null, 'delivery_point' => 'Biblioteca central',
                        ],
                        'highlight' => 'Ideal para Cálculo I y Física.',
                        'reasons' => ['Es el producto de comercios verificados que coincide con tu búsqueda.'],
                        'store' => ['name' => 'Papelería Campus', 'slug' => 'papeleria-campus', 'logo_url' => null, 'is_verified' => true, 'accepts_paypal' => true],
                        'match_probability' => 0.96, 'within_budget' => null, 'recommended' => true,
                    ]],
                    'message' => ['title' => 'Encontré lo que buscás', 'body' => [], 'clarification' => null],
                ];
            });
        });
    }

    /**
     * Modelo de lenguaje simulado: primero pide la herramienta indicada; con el resultado responde
     * $final; si recibe un pedido de corrección responde $corrected.
     */
    private function fakeLlm(string $tool = 'search_products', array $args = ['query' => 'calculadora para Cálculo'], string $final = 'Te recomiendo la Calculadora científica 417 funciones a $18.50, ideal para Cálculo I.', ?string $corrected = null): void
    {
        $this->fakes[self::LLM] = function (Request $request) use ($tool, $args, $final, $corrected) {
            $messages = $request['messages'];
            $last = end($messages);
            $hasTool = collect($messages)->contains(fn ($m) => $m['role'] === 'tool');

            if ($corrected !== null && $last['role'] === 'user' && str_contains($last['content'], 'Reescribí tu respuesta')) {
                return Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => $corrected]]]]);
            }
            if (! $hasTool && isset($request['tools'])) {
                return Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
                    'id' => 'call_1', 'type' => 'function', 'function' => ['name' => $tool, 'arguments' => json_encode($args)],
                ]]]]]]);
            }

            return Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => '<think>razonamiento interno</think>'.$final]]]]);
        };
    }

    /** Jev simulado: tema del mensaje y verificación frase por frase (marca precios inventados). */
    private function fakeJev(float $onTopic = 0.93, string $unsupportedMarker = '$99'): void
    {
        $this->fakes[self::JEV] = function (Request $request) use ($onTopic, $unsupportedMarker) {
            $answers = [];
            foreach ($request['questions'] as $key => $q) {
                $sentenceKey = Str::after($key, '_');
                $sentence = $request['state']['reply_sentences'][$sentenceKey] ?? '';
                $answers[$key] = ['type' => 'noul', 'noul' => match (true) {
                    $key === 'on_topic' => $onTopic,
                    str_starts_with($key, 'claim_') => 0.9,
                    str_starts_with($key, 'supported_') => str_contains($sentence, $unsupportedMarker) ? 0.05 : 0.95,
                    default => 0.5,
                }];
            }

            return Http::response(['model' => 'jev-1.14.0', 'answers' => $answers, 'usage' => ['input_tokens' => 200, 'output_tokens' => 10]]);
        };
    }

    private array $fakes = [];

    private function applyFakes(): void
    {
        Http::fake($this->fakes);
    }

    public function test_agent_searches_with_tools_and_replies_with_verified_text_and_cards(): void
    {
        $this->fakeSearch();
        $this->fakeLlm();
        $this->fakeJev();
        $this->applyFakes();

        $response = $this->postJson('/api/marketplace/agent/conversations', ['message' => 'Necesito una calculadora para Cálculo'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Necesito una calculadora para Cálculo')
            ->assertJsonPath('data.messages.0.role', 'user')
            ->assertJsonPath('data.messages.1.role', 'assistant')
            ->assertJsonPath('data.messages.1.content', 'Te recomiendo la Calculadora científica 417 funciones a $18.50, ideal para Cálculo I.')
            ->assertJsonPath('data.messages.1.cards.0.type', 'products')
            ->assertJsonPath('data.messages.1.cards.0.items.0.product.id', $this->calculator->id)
            ->assertJsonPath('data.messages.1.steps.0.label', 'Buscó en comercios verificados')
            ->assertJsonMissingPath('data.messages.1.meta');

        $this->assertStringNotContainsString('razonamiento interno', $response->getContent());

        // El modelo recibió las herramientas y la regla de no inventar datos.
        Http::assertSent(fn (Request $r) => $r->url() === self::LLM && isset($r['tools'])
            && collect($r['tools'])->pluck('function.name')->contains('propose_purchase')
            && str_contains($r['messages'][0]['content'], 'Nunca inventes productos'));
        // Jev juzgó el tema y verificó la respuesta contra los datos reales.
        Http::assertSent(fn (Request $r) => $r->url() === self::JEV && isset($r['questions']['on_topic']));
        Http::assertSent(fn (Request $r) => $r->url() === self::JEV && isset($r['questions']['supported_s1'])
            && isset($r['state']['facts']['p'.$this->calculator->id]));

        $conversation = AgentConversation::sole();
        $this->assertNotNull($conversation->assistant_decision_id);
        $this->assertSame(0.93, $conversation->messages()->where('role', 'assistant')->sole()->meta['on_topic']);
    }

    public function test_off_topic_message_gets_a_fixed_reply_without_calling_the_model(): void
    {
        $this->fakeLlm();
        $this->fakeJev(onTopic: 0.08);
        $this->applyFakes();

        $this->postJson('/api/marketplace/agent/conversations', ['message' => 'Resolveme la integral de x al cuadrado'])
            ->assertCreated()
            ->assertJsonPath('data.messages.1.content', \App\Services\Agent\ShoppingAgent::OFF_TOPIC_REPLY);

        Http::assertNotSent(fn (Request $r) => $r->url() === self::LLM);
    }

    public function test_unsupported_claims_are_corrected_by_the_model(): void
    {
        $this->fakeSearch();
        $this->fakeLlm(
            final: 'La Calculadora científica 417 funciones cuesta $99 y es la mejor.',
            corrected: 'La Calculadora científica 417 funciones cuesta $18.50 y sirve para Cálculo I.',
        );
        $this->fakeJev();
        $this->applyFakes();

        $this->postJson('/api/marketplace/agent/conversations', ['message' => 'Necesito una calculadora'])
            ->assertCreated()
            ->assertJsonPath('data.messages.1.content', 'La Calculadora científica 417 funciones cuesta $18.50 y sirve para Cálculo I.');

        $this->assertTrue(AgentConversation::sole()->messages()->where('role', 'assistant')->sole()->meta['corrected']);
    }

    public function test_claims_still_unsupported_after_correction_are_removed(): void
    {
        $this->fakeSearch();
        $this->fakeLlm(final: 'Encontré una opción. Además tiene garantía de $99 incluida.', corrected: 'Encontré una opción. Además tiene garantía de $99 incluida.');
        $this->fakeJev();
        $this->applyFakes();

        $content = $this->postJson('/api/marketplace/agent/conversations', ['message' => 'Necesito una calculadora'])
            ->assertCreated()->json('data.messages.1.content');

        $this->assertStringNotContainsString('$99', $content);
        $this->assertStringContainsString('Encontré una opción.', $content);
    }

    public function test_purchase_is_only_proposed_and_total_is_computed_in_code(): void
    {
        $this->fakeLlm(tool: 'propose_purchase', args: ['product_id' => $this->calculator->id, 'quantity' => 2], final: 'Listo, te preparé la compra de 2 calculadoras por $37.00. Confirmala con PayPal.');
        $this->fakeJev();
        $this->applyFakes();

        $this->postJson('/api/marketplace/agent/conversations', ['message' => 'Quiero comprar 2'])
            ->assertCreated()
            ->assertJsonPath('data.messages.1.cards.0.type', 'purchase')
            ->assertJsonPath('data.messages.1.cards.0.quantity', 2)
            ->assertJsonPath('data.messages.1.cards.0.total', '37.00')
            ->assertJsonPath('data.messages.1.steps.0.tool', 'propose_purchase');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'paypal.com'));
    }

    public function test_chat_opened_from_a_smart_search_starts_with_those_results_without_the_model(): void
    {
        Http::fake();
        $decision = AssistantDecision::create([
            'user_id' => $this->student->id, 'query' => 'calculadora para Cálculo II', 'outcome' => 'recommendation', 'model' => 'jev',
            'budget_source' => 'none', 'answers' => [], 'recommended_product_id' => $this->calculator->id,
            'candidates' => [
                ['product_id' => $this->calculator->id, 'price' => '18.50', 'match' => 0.97, 'within_budget' => null],
                // Descartado por Jev: la búsqueda no lo mostró, el chat tampoco.
                ['product_id' => $this->productFor($this->calculator->store, ['name' => 'Cuaderno'])->id, 'price' => '3.25', 'match' => 0.02, 'within_budget' => null],
            ],
        ]);

        $this->postJson('/api/marketplace/agent/conversations', ['decision_id' => $decision->id])
            ->assertJsonCount(1, 'data.messages.1.cards.0.items')
            ->assertCreated()
            ->assertJsonPath('data.title', 'calculadora para Cálculo II')
            ->assertJsonPath('data.messages.0.content', 'calculadora para Cálculo II')
            ->assertJsonPath('data.messages.1.cards.0.items.0.product.id', $this->calculator->id)
            ->assertJsonPath('data.messages.1.cards.0.items.0.match_probability', 0.97);

        Http::assertNothingSent();
    }

    public function test_history_is_private_to_each_student(): void
    {
        $mine = AgentConversation::create(['user_id' => $this->student->id, 'title' => 'Mío', 'last_message_at' => now()]);
        $other = AgentConversation::create(['user_id' => User::factory()->create()->id, 'title' => 'Ajeno', 'last_message_at' => now()]);

        $this->getJson('/api/marketplace/agent/conversations')->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonCount(1, 'data.conversations')
            ->assertJsonPath('data.conversations.0.id', $mine->id);

        $this->getJson('/api/marketplace/agent/conversations/'.$other->id)->assertNotFound();
        $this->deleteJson('/api/marketplace/agent/conversations/'.$other->id)->assertNotFound();
        $this->deleteJson('/api/marketplace/agent/conversations/'.$mine->id)->assertOk();
        $this->assertDatabaseMissing('agent_conversations', ['id' => $mine->id]);
    }

    public function test_unreachable_model_returns_503_and_keeps_no_half_turn(): void
    {
        $this->fakeJev();
        $this->fakes[self::LLM] = Http::response(['error' => 'down'], 500);
        $this->applyFakes();

        $conversation = AgentConversation::create(['user_id' => $this->student->id, 'title' => 'Chat', 'last_message_at' => now()]);
        $this->postJson("/api/marketplace/agent/conversations/{$conversation->id}/messages", ['message' => 'Necesito un cuaderno'])
            ->assertStatus(503)->assertJsonPath('code', 'agent_unavailable');

        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_agent_disabled_without_configuration(): void
    {
        config(['services.agent.base_url' => '']);
        Http::fake();

        $this->getJson('/api/marketplace/agent/conversations')->assertOk()->assertJsonPath('data.available', false);
        $this->postJson('/api/marketplace/agent/conversations', ['message' => 'hola'])->assertStatus(503);
        Http::assertNothingSent();
    }
}
