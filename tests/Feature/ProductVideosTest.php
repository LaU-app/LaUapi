<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

/** Videos de reseñas: YouTube Data API y Jev simulados con Http::fake. */
class ProductVideosTest extends TestCase
{
    use MarketplaceFixtures;
    use RefreshDatabase;

    private const YT = 'https://www.googleapis.com/youtube/v3/search*';

    private const JEV = 'https://api.typesafe.ai/v1/systemone';

    private const YT_KEY = 'fake-youtube-key-YYY';

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFakeServices();
        config(['services.youtube.key' => self::YT_KEY]);
        $this->product = $this->productFor($this->verifiedStore());
        Sanctum::actingAs(User::factory()->create());
    }

    private function youtube(array $titles): array
    {
        return ['items' => collect($titles)->map(fn ($t, $i) => [
            'id' => ['videoId' => 'vid'.$i],
            'snippet' => ['title' => $t, 'channelTitle' => 'Canal '.$i, 'thumbnails' => ['medium' => ['url' => "https://i.ytimg.com/vi/vid{$i}/mqdefault.jpg"]]],
        ])->all()];
    }

    /** @param array<string, float> $relevance v1 => probabilidad */
    private function jev(array $relevance): \Closure
    {
        return fn (Request $request) => Http::response([
            'model' => 'jev-1.14.0',
            'answers' => collect($request['questions'])->mapWithKeys(fn ($q, $k) => [$k => ['type' => 'noul', 'noul' => $relevance[Str::after($k, 'about_')] ?? 0.1]])->all(),
            'usage' => [],
        ]);
    }

    public function test_keeps_only_videos_that_jev_says_are_about_the_product(): void
    {
        Http::fake([
            self::YT => Http::response($this->youtube(['Reseña calculadora científica 417 funciones', 'Mix de música para estudiar', 'Cómo usar una calculadora científica'])),
            self::JEV => $this->jev(['v1' => 0.93, 'v2' => 0.05, 'v3' => 0.81]),
        ]);

        $response = $this->getJson('/api/marketplace/products/'.$this->product->id.'/videos')->assertOk()
            ->assertJsonPath('data.status', 'found')
            ->assertJsonCount(2, 'data.videos')
            ->assertJsonPath('data.videos.0.title', 'Reseña calculadora científica 417 funciones')
            ->assertJsonPath('data.videos.0.url', 'https://www.youtube.com/watch?v=vid0')
            ->assertJsonPath('data.videos.1.title', 'Cómo usar una calculadora científica');

        $this->assertStringNotContainsString(self::YT_KEY, $response->getContent());
        $this->assertNoSecrets($response->getContent());
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://www.googleapis.com/youtube/v3/search')
            && $r['type'] === 'video' && $r['safeSearch'] === 'strict' && str_contains($r['q'], 'reseña'));
    }

    public function test_says_clearly_when_there_are_no_videos_or_reviews(): void
    {
        Http::fake([
            self::YT => Http::response($this->youtube(['Mix de música para estudiar'])),
            self::JEV => $this->jev(['v1' => 0.04]),
        ]);

        $this->getJson('/api/marketplace/products/'.$this->product->id.'/videos')->assertOk()
            ->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.message', 'No encontramos videos ni reseñas de este producto.')
            ->assertJsonCount(0, 'data.videos');
    }

    public function test_no_youtube_results_means_no_videos(): void
    {
        Http::fake([self::YT => Http::response(['items' => []])]);

        $this->getJson('/api/marketplace/products/'.$this->product->id.'/videos')->assertOk()
            ->assertJsonPath('data.status', 'none');
        Http::assertNotSent(fn (Request $r) => $r->url() === self::JEV);
    }

    public function test_results_are_cached_per_product(): void
    {
        Http::fake([
            self::YT => Http::response($this->youtube(['Reseña calculadora científica'])),
            self::JEV => $this->jev(['v1' => 0.9]),
        ]);

        $this->getJson('/api/marketplace/products/'.$this->product->id.'/videos')->assertOk();
        $this->getJson('/api/marketplace/products/'.$this->product->id.'/videos')->assertOk()->assertJsonPath('data.status', 'found');

        $this->assertSame(1, Http::recorded()->filter(fn ($p) => str_starts_with($p[0]->url(), 'https://www.googleapis.com'))->count());
    }

    public function test_jev_failure_is_reported_and_not_cached(): void
    {
        Http::fake([
            self::YT => Http::response($this->youtube(['Reseña calculadora científica'])),
            self::JEV => Http::response(['detail' => 'boom'], 500),
        ]);

        $this->getJson('/api/marketplace/products/'.$this->product->id.'/videos')->assertOk()
            ->assertJsonPath('data.status', 'error')
            ->assertJsonCount(0, 'data.videos');
        $this->getJson('/api/marketplace/products/'.$this->product->id.'/videos')->assertOk();

        $this->assertSame(2, Http::recorded()->filter(fn ($p) => str_starts_with($p[0]->url(), 'https://www.googleapis.com'))->count());
    }

    public function test_without_api_key_offers_a_youtube_search_link(): void
    {
        config(['services.youtube.key' => null]);
        Http::fake();

        $this->getJson('/api/marketplace/products/'.$this->product->id.'/videos')->assertOk()
            ->assertJsonPath('data.status', 'unavailable')
            ->assertJsonPath('data.search_url', 'https://www.youtube.com/results?search_query='.rawurlencode('Calculadora científica 417 funciones reseña'));
        Http::assertNothingSent();
    }
}
