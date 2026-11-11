<?php

namespace Tests\Feature;

use App\Services\TypeSafe\JevClient;
use App\Services\TypeSafe\JevException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JevClientTest extends TestCase
{
    private const JEV = 'https://api.typesafe.ai/v1/systemone';

    private const KEY = 'fake-typesafe-key-KKK';

    private function client(array $overrides = []): JevClient
    {
        return new JevClient(array_merge([
            'api_key' => self::KEY, 'model' => 'jev-latest', 'endpoint' => self::JEV, 'timeout' => 5,
        ], $overrides));
    }

    private function questions(): array
    {
        return [
            'is_purchase' => ['type' => 'noul', 'instructions' => 'Does `request` ask to buy something?'],
            'kind' => ['type' => 'choice', 'instructions' => 'Which kind?', 'criteria' => ['calculator' => 'A calculator', 'notebook' => 'A notebook']],
            'fit' => ['type' => 'score', 'instructions' => 'How well?', 'criteria' => ['Not at all', 'Partly', 'Fully']],
        ];
    }

    private function answers(): array
    {
        return [
            'model' => 'jev-1.14.0',
            'answers' => [
                'is_purchase' => ['type' => 'noul', 'noul' => 0.97],
                'kind' => ['type' => 'choice', 'choice' => 'calculator', 'confidence' => 0.8, 'probabilities' => ['calculator' => 0.9, 'notebook' => 0.1]],
                'fit' => ['type' => 'score', 'score' => 1.8, 'confidence' => 0.6, 'legend' => ['0' => 'Not at all', '1' => 'Partly', '2' => 'Fully'], 'probabilities' => ['0' => 0.05, '1' => 0.1, '2' => 0.85]],
            ],
            'usage' => ['input_tokens' => 50, 'output_tokens' => 5],
        ];
    }

    public function test_sends_typed_questions_and_parses_answers(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::JEV => Http::response($this->answers())]);

        $result = $this->client()->ask(['request' => 'necesito una calculadora'], $this->questions());

        $this->assertSame('jev-1.14.0', $result->model);
        $this->assertSame(0.97, $result->noul('is_purchase'));
        $this->assertSame('calculator', $result->choice('kind')['choice']);
        $this->assertSame([0.05, 0.1, 0.85], $result->score('fit')['probabilities']);

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::KEY)
            && $r['model'] === 'jev-latest'
            && $r['state'] === ['request' => 'necesito una calculadora']
            && $r['questions']['kind']['criteria'] === ['calculator' => 'A calculator', 'notebook' => 'A notebook']);
    }

    public function test_retries_rate_limits_and_overload_a_limited_number_of_times(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::JEV => Http::sequence()
            ->push(['detail' => 'slow down'], 429, ['Retry-After' => '0'])
            ->push(['detail' => 'busy'], 529)
            ->push($this->answers())]);

        $this->assertSame(0.97, $this->client()->ask(['request' => 'x'], $this->questions())->noul('is_purchase'));
        Http::assertSentCount(3);
    }

    public function test_gives_up_after_the_retry_budget(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::JEV => Http::response(['detail' => 'busy'], 529, ['Retry-After' => '0'])]);

        try {
            $this->client()->ask(['request' => 'x'], $this->questions());
            $this->fail('Debió lanzar JevException');
        } catch (JevException $e) {
            $this->assertSame(JevException::UNAVAILABLE, $e->reason);
        }
        Http::assertSentCount(3);
    }

    public function test_rejected_key_is_reported_without_leaking_it(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::JEV => Http::response(['detail' => 'Invalid key '.self::KEY], 401)]);

        try {
            $this->client()->ask(['request' => 'x'], $this->questions());
            $this->fail('Debió lanzar JevException');
        } catch (JevException $e) {
            $this->assertSame(JevException::REJECTED, $e->reason);
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_rejects_answers_that_do_not_match_the_questions(): void
    {
        Http::preventStrayRequests();
        $bad = $this->answers();
        $bad['answers']['kind']['choice'] = 'laptop';
        Http::fake([self::JEV => Http::response($bad)]);

        $this->expectException(JevException::class);
        $this->client()->ask(['request' => 'x'], $this->questions());
    }

    public function test_rejects_out_of_range_scores(): void
    {
        Http::preventStrayRequests();
        $bad = $this->answers();
        $bad['answers']['fit']['score'] = 3.5;
        Http::fake([self::JEV => Http::response($bad)]);

        $this->expectException(JevException::class);
        $this->client()->ask(['request' => 'x'], $this->questions());
    }

    public function test_requires_an_api_key(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        try {
            $this->client(['api_key' => ''])->ask(['request' => 'x'], $this->questions());
            $this->fail('Debió lanzar JevException');
        } catch (JevException $e) {
            $this->assertSame(JevException::NOT_CONFIGURED, $e->reason);
        }
        Http::assertNothingSent();
    }

    /**
     * Prueba real opcional contra TypeSafe AI. Solo corre con RUN_TYPESAFE_LIVE=1 y una
     * TYPESAFE_API_KEY en el entorno. Envía un texto genérico sin datos personales.
     */
    public function test_live_typesafe_api_answers_typed_questions(): void
    {
        $key = getenv('TYPESAFE_API_KEY') ?: (string) env('TYPESAFE_API_KEY');
        if (getenv('RUN_TYPESAFE_LIVE') !== '1' || $key === '') {
            $this->markTestSkipped('Prueba real desactivada: define RUN_TYPESAFE_LIVE=1 y TYPESAFE_API_KEY.');
        }

        $result = $this->client(['api_key' => $key, 'timeout' => 30])
            ->ask(['request' => 'Necesito una calculadora científica para Cálculo I'], $this->questions());

        $this->assertGreaterThan(0.7, $result->noul('is_purchase'));
        $this->assertSame('calculator', $result->choice('kind')['choice']);
    }
}
