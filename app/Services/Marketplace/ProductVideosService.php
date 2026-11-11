<?php

namespace App\Services\Marketplace;

use App\Models\Product;
use App\Services\TypeSafe\JevClient;
use App\Services\TypeSafe\JevException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reseñas y videos de YouTube sobre un producto del Marketplace.
 *
 * - YouTube Data API v3 (search) trae candidatos; requiere YOUTUBE_API_KEY (opcional).
 * - Jev decide qué videos tratan realmente de ese tipo de producto (selección, no generación).
 * - Si no queda ninguno, se dice explícitamente. Los resultados se guardan 12 h por producto.
 * - La API key nunca se registra ni se devuelve.
 */
class ProductVideosService
{
    private const ENDPOINT = 'https://www.googleapis.com/youtube/v3/search';

    private const MAX_VIDEOS = 3;

    public function __construct(private readonly JevClient $jev) {}

    /**
     * @return array{status: string, videos: list<array>, search_url: string, message: string}
     */
    public function for(Product $product): array
    {
        $query = Str::limit($product->name, 80, '').' reseña';
        $searchUrl = 'https://www.youtube.com/results?search_query='.rawurlencode($query);
        $key = (string) config('services.youtube.key');

        if ($key === '') {
            return $this->result('unavailable', [], $searchUrl, 'Podés buscar reseñas de este producto en YouTube.');
        }

        $cacheKey = 'product-videos:'.$product->id.':'.md5($product->name.'|'.$product->description);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $result = $this->lookup($product, $query, $key, $searchUrl);
        } catch (JevException|ConnectionException) {
            return $this->result('error', [], $searchUrl, 'No pudimos revisar videos en este momento.');
        }

        // Solo se guardan respuestas completas; un error vuelve a intentarse en la próxima consulta.
        if ($result['status'] !== 'error') {
            Cache::put($cacheKey, $result, now()->addHours(12));
        }

        return $result;
    }

    private function lookup(Product $product, string $query, string $key, string $searchUrl): array
    {
        $response = Http::acceptJson()->timeout(10)->get(self::ENDPOINT, [
            'part' => 'snippet',
            'type' => 'video',
            'maxResults' => 6,
            'q' => $query,
            'relevanceLanguage' => 'es',
            'safeSearch' => 'strict',
            'key' => $key,
        ]);

        if ($response->failed()) {
            Log::warning('YouTube Data API: búsqueda fallida', ['status' => $response->status(), 'product_id' => $product->id]);

            return $this->result('error', [], $searchUrl, 'No pudimos revisar videos en este momento.');
        }

        $candidates = collect((array) $response->json('items', []))
            ->filter(fn ($item) => is_string($item['id']['videoId'] ?? null) && is_string($item['snippet']['title'] ?? null))
            ->values()
            ->mapWithKeys(fn ($item, $i) => ['v'.($i + 1) => [
                'id' => $item['id']['videoId'],
                'title' => html_entity_decode($item['snippet']['title'], ENT_QUOTES | ENT_HTML5),
                'channel' => html_entity_decode((string) ($item['snippet']['channelTitle'] ?? ''), ENT_QUOTES | ENT_HTML5),
                'thumbnail' => $item['snippet']['thumbnails']['medium']['url'] ?? $item['snippet']['thumbnails']['default']['url'] ?? null,
            ]]);

        if ($candidates->isEmpty()) {
            return $this->result('none', [], $searchUrl, 'No encontramos videos ni reseñas de este producto.');
        }

        $answers = $this->jev->ask(
            [
                'product' => array_filter([
                    'name' => $product->name,
                    'category' => $product->category?->name,
                    'description' => Str::limit((string) $product->description, 300),
                ]),
                'videos' => $candidates->map(fn ($v) => ['title' => $v['title'], 'channel' => $v['channel']])->all(),
            ],
            $candidates->keys()->mapWithKeys(fn ($k) => ['about_'.$k => [
                'type' => 'noul',
                'instructions' => "Is `videos.{$k}` a review, unboxing, comparison or tutorial of the kind of product described in `product`?",
                'criteria' => [
                    'true' => 'For example, a review of a scientific calculator when `product` is a scientific calculator.',
                    'false' => 'For example, a music video, a class recording, or a video about a different kind of product.',
                ],
            ]])->all(),
        );

        $videos = $candidates
            ->filter(fn ($v, $k) => $answers->noul('about_'.$k) >= SmartShoppingQuestions::YES)
            ->sortByDesc(fn ($v, $k) => $answers->noul('about_'.$k))
            ->take(self::MAX_VIDEOS)
            ->map(fn ($v, $k) => $v + [
                'url' => 'https://www.youtube.com/watch?v='.rawurlencode($v['id']),
                'relevance' => round($answers->noul('about_'.$k), 3),
            ])
            ->values()
            ->all();

        return $videos === []
            ? $this->result('none', [], $searchUrl, 'No encontramos videos ni reseñas de este producto.')
            : $this->result('found', $videos, $searchUrl, 'Videos sobre este tipo de producto.');
    }

    private function result(string $status, array $videos, string $searchUrl, string $message): array
    {
        return ['status' => $status, 'videos' => $videos, 'search_url' => $searchUrl, 'message' => $message];
    }
}
