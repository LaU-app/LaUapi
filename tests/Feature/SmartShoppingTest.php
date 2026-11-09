<?php

namespace Tests\Feature;

use App\Models\AssistantDecision;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

/** Compra inteligente con Jev: Jev simulado con Http::fake; catálogo y decisiones reales. */
class SmartShoppingTest extends TestCase
{
    use MarketplaceFixtures;
    use RefreshDatabase;

    private const JEV = 'https://api.typesafe.ai/v1/systemone';

    private User $student;

    private Product $calculator;

    private Product $notebook;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFakeServices();

        $this->category('electronica', 'Electrónica');
        $materiales = $this->category('materiales', 'Materiales');
        $store = $this->verifiedStore();
        $this->calculator = $this->productFor($store, ['price' => '18.50']);
        $this->notebook = $this->productFor($store, [
            'name' => 'Cuaderno universitario cuadriculado', 'description' => '100 hojas, ideal para apuntes de Cálculo.',
            'price' => '3.25', 'category_id' => $materiales->id,
        ]);

        $this->student = User::factory()->create();
        Sanctum::actingAs($this->student);
    }

    public function test_recommends_the_matching_product_within_the_budget_field(): void
    {
        $this->fakeJev();

        $response = $this->postJson('/api/marketplace/smart-shopping', [
            'query' => 'Necesito una calculadora para Cálculo', 'max_budget' => 25,
        ])->assertOk();

        $response->assertJsonPath('data.outcome', 'recommendation')
            ->assertJsonPath('data.model', 'jev-1.14.0')
            ->assertJsonPath('data.budget.amount', '25.00')
            ->assertJsonPath('data.budget.source', 'field')
            ->assertJsonPath('data.recommendations.0.product.id', $this->calculator->id)
            ->assertJsonPath('data.recommendations.0.product.price', '18.50')
            ->assertJsonPath('data.recommendations.0.recommended', true)
            ->assertJsonPath('data.recommendations.0.within_budget', true)
            ->assertJsonPath('data.recommendations.0.store.is_verified', true)
            ->assertJsonPath('data.recommendations.0.match_probability', 0.95);
        $this->assertStringContainsString('Te recomendamos Calculadora científica', $response->json('data.message.title'));
        $this->assertNoSecrets($response->getContent());

        $decision = AssistantDecision::findOrFail($response->json('data.decision_id'));
        $this->assertSame('recommendation', $decision->outcome);
        $this->assertSame($this->calculator->id, $decision->recommended_product_id);
        $this->assertSame('jev-1.14.0', $decision->model);
        $this->assertArrayHasKey('evaluate', $decision->answers);

        // Jev recibe solo candidatos reales, nunca precios, con la clave en el encabezado.
        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::JEV || ! isset($request['state']['candidates'])) {
                return false;
            }
            $candidates = $request['state']['candidates'];

            return $request->hasHeader('Authorization', 'Bearer fake-typesafe-key-KKK')
                && $request['model'] === 'jev-latest'
                && count($candidates) === 2
                && collect($candidates)->every(fn ($c) => ! array_key_exists('price', $c))
                && isset($request['questions']['best']['criteria']);
        });
    }

    public function test_reads_the_maximum_budget_from_the_message_and_reports_over_budget(): void
    {
        $this->fakeJev(budgetRole: ['maximum_price' => 0.92, 'money_to_keep' => 0.05, 'other_amount' => 0.03]);

        $this->postJson('/api/marketplace/smart-shopping', ['query' => 'Necesito una calculadora para Cálculo, mi presupuesto máximo es $15'])
            ->assertOk()
            ->assertJsonPath('data.budget.amount', '15.00')
            ->assertJsonPath('data.budget.source', 'message')
            ->assertJsonPath('data.outcome', 'over_budget')
            ->assertJsonPath('data.recommendations.0.within_budget', false)
            ->assertJsonPath('data.recommendations.0.recommended', false);
    }

    public function test_does_not_apply_an_amount_whose_meaning_is_unclear(): void
    {
        $this->fakeJev(budgetRole: ['maximum_price' => 0.5, 'money_to_keep' => 0.4, 'other_amount' => 0.1]);

        $response = $this->postJson('/api/marketplace/smart-shopping', ['query' => 'Necesito una calculadora para Cálculo y tengo $15'])
            ->assertOk()
            ->assertJsonPath('data.budget.amount', null)
            ->assertJsonPath('data.budget.unclear_amounts.0', '$15')
            ->assertJsonPath('data.outcome', 'recommendation');

        $this->assertStringContainsString('no quedó claro', implode(' ', $response->json('data.message.body')));
    }

    public function test_money_to_keep_is_not_treated_as_a_budget(): void
    {
        $this->fakeJev(budgetRole: ['maximum_price' => 0.04, 'money_to_keep' => 0.94, 'other_amount' => 0.02]);

        $this->postJson('/api/marketplace/smart-shopping', ['query' => 'Necesito una calculadora para Cálculo, pero debo reservar $20 para comida'])
            ->assertOk()
            ->assertJsonPath('data.budget.amount', null)
            ->assertJsonPath('data.budget.source', 'none');
    }

    public function test_asks_the_student_to_confirm_when_the_match_is_uncertain(): void
    {
        $this->fakeJev(match: [$this->calculator->id => 0.55, $this->notebook->id => 0.1]);

        $this->postJson('/api/marketplace/smart-shopping', ['query' => 'Necesito algo para hacer cuentas en Cálculo'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'confirm_choice')
            ->assertJsonPath('data.recommendations.0.product.id', $this->calculator->id)
            ->assertJsonPath('data.recommendations.0.recommended', false)
            ->assertJsonCount(1, 'data.recommendations');
    }

    public function test_asks_for_clarification_without_searching_when_no_item_is_named(): void
    {
        $this->fakeJev(itemNamed: 0.2);

        $this->postJson('/api/marketplace/smart-shopping', ['query' => 'quiero comprar algo barato'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'needs_clarification')
            ->assertJsonCount(0, 'data.recommendations');

        $this->assertSame(1, Http::recorded()->filter(fn ($pair) => $pair[0]->url() === self::JEV)->count());
    }

    public function test_does_nothing_when_the_message_is_not_a_purchase(): void
    {
        $this->fakeJev(intent: 0.05);

        $this->postJson('/api/marketplace/smart-shopping', ['query' => 'hola, cómo funciona LaU?'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'not_a_purchase')
            ->assertJsonCount(0, 'data.recommendations');
    }

    public function test_reports_no_match_when_no_real_candidate_fits(): void
    {
        $this->fakeJev(match: [$this->calculator->id => 0.08, $this->notebook->id => 0.05]);

        $this->postJson('/api/marketplace/smart-shopping', ['query' => 'Necesito una calculadora graficadora'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'no_match')
            ->assertJsonCount(0, 'data.recommendations');
    }

    public function test_only_products_from_verified_stores_that_accept_paypal_are_candidates(): void
    {
        $unverified = $this->verifiedStore(['verification_status' => 'submitted', 'paypal_tracking_id' => 'lau-store-x', 'paypal_status' => 'not_connected']);
        $hidden = $this->productFor($unverified, ['name' => 'Calculadora sin verificar']);
        $this->fakeJev();

        $this->postJson('/api/marketplace/smart-shopping', ['query' => 'Necesito una calculadora para Cálculo'])->assertOk();

        Http::assertSent(fn (Request $r) => isset($r['state']['candidates'])
            && ! collect(array_keys($r['state']['candidates']))->contains(fn ($k) => str_ends_with($k, '_p'.$hidden->id)));
    }

    public function test_jev_failure_is_an_error_not_a_recommendation(): void
    {
        Http::fake([self::JEV => Http::response(['detail' => 'boom'], 500)]);

        $this->postJson('/api/marketplace/smart-shopping', ['query' => 'Necesito una calculadora para Cálculo'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'assistant_unavailable')
            ->assertJsonMissingPath('data');

        $this->assertDatabaseCount('assistant_decisions', 0);
    }

    public function test_malformed_jev_answers_are_rejected(): void
    {
        Http::fake([self::JEV => Http::response(['model' => 'jev-1.14.0', 'answers' => ['purchase_intent' => ['type' => 'noul', 'noul' => 1.7]], 'usage' => []])]);

        $this->postJson('/api/marketplace/smart-shopping', ['query' => 'Necesito una calculadora para Cálculo'])
            ->assertStatus(503)->assertJsonPath('code', 'assistant_unavailable');
    }

    public function test_missing_api_key_disables_the_feature_without_calling_typesafe(): void
    {
        config(['services.typesafe.api_key' => null]);
        Http::fake();

        $this->postJson('/api/marketplace/smart-shopping', ['query' => 'Necesito una calculadora para Cálculo'])
            ->assertStatus(503)->assertJsonPath('code', 'assistant_unavailable');
        Http::assertNothingSent();
    }

    public function test_validates_input(): void
    {
        Http::fake();

        $this->postJson('/api/marketplace/smart-shopping', ['query' => 'a'])->assertUnprocessable();
        $this->postJson('/api/marketplace/smart-shopping', ['query' => 'calculadora', 'max_budget' => -5])->assertUnprocessable();
        Http::assertNothingSent();
    }

    /**
     * Jev simulado: responde cada pregunta recibida según su nombre.
     *
     * @param  array<int, float>  $match  probabilidad "es lo que pide" por id de producto
     */
    private function fakeJev(float $intent = 0.95, float $itemNamed = 0.93, array $match = [], array $budgetRole = []): void
    {
        $match += [$this->calculator->id => 0.95, $this->notebook->id => 0.06];

        Http::fake([self::JEV => function (Request $request) use ($intent, $itemNamed, $match, $budgetRole) {
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                $productId = preg_match('/_p(\d+)$/', $key, $m) ? (int) $m[1] : null;

                $answers[$key] = match (true) {
                    $key === 'purchase_intent' => ['type' => 'noul', 'noul' => $intent],
                    $key === 'item_named' => ['type' => 'noul', 'noul' => $itemNamed],
                    $key === 'category_exists' => ['type' => 'noul', 'noul' => 0.9],
                    $key === 'category' => $this->choice(array_keys($question['criteria']), 'electronica', 0.88),
                    Str::startsWith($key, 'budget_role_') => $this->choice(array_keys($question['criteria']), null, 0, $budgetRole ?: ['maximum_price' => 0.9, 'money_to_keep' => 0.05, 'other_amount' => 0.05]),
                    Str::startsWith($key, 'match_') => ['type' => 'noul', 'noul' => $match[$productId] ?? 0.1],
                    Str::startsWith($key, 'fit_') => $this->score(count($question['criteria']), ($match[$productId] ?? 0) >= 0.7 ? 2.8 : 0.4),
                    Str::startsWith($key, 'academic_') => $this->score(count($question['criteria']), 1.8),
                    $key === 'best' => $this->choice(array_keys($question['criteria']), collect(array_keys($question['criteria']))->first(fn ($k) => str_ends_with($k, '_p'.$this->calculator->id)), 0.8),
                    default => ['type' => 'noul', 'noul' => 0.5],
                };
            }

            return Http::response(['model' => 'jev-1.14.0', 'answers' => $answers, 'usage' => ['input_tokens' => 300, 'output_tokens' => 20]]);
        }]);
    }

    private function choice(array $options, ?string $top, float $p, array $explicit = []): array
    {
        $probabilities = $explicit ?: collect($options)->mapWithKeys(fn ($o) => [$o => $o === $top ? $p : round((1 - $p) / max(1, count($options) - 1), 4)])->all();
        arsort($probabilities);

        return ['type' => 'choice', 'choice' => array_key_first($probabilities), 'confidence' => 0.7, 'probabilities' => $probabilities];
    }

    private function score(int $levels, float $value): array
    {
        $probabilities = array_fill(0, $levels, 0.0);
        $probabilities[(int) round($value)] = 1.0;

        return ['type' => 'score', 'score' => $value, 'confidence' => 0.6, 'legend' => [], 'probabilities' => $probabilities];
    }
}
