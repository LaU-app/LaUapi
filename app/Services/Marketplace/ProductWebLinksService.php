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
 * El producto en la web, a partir de su foto: Google Cloud Vision (Web Detection) encuentra páginas
 * con esa imagen o una muy parecida, y Jev decide, página por página, si trata del mismo producto.
 * Solo se muestran enlaces que Jev confirma (≥ 0.7). La clave nunca se registra.
 */
class ProductWebLinksService
{
    private const ENDPOINT = 'https://vision.googleapis.com/v1/images:annotate';

    private const MAX_LINKS = 4;

    private const YES = 0.7;

    /** Vision acepta imágenes de hasta 10 MB en base64; las de LaU ya vienen optimizadas. */
    private const MAX_BYTES = 8_000_000;

    public function __construct(private readonly JevClient $jev) {}

    /**
     * @return array{status: string, links: list<array{title: string, url: string, domain: string}>, best_guess: ?string, search_url: string, message: string}
     */
    public function for(Product $product): array
    {
        $searchUrl = 'https://www.google.com/search?q='.rawurlencode(Str::limit($product->name, 80, ''));
        $image = $product->images()->first();
        $path = $image ? public_path(\App\Http\Controllers\Api\Marketplace\ProductImageController::DIRECTORY.'/'.$image->filename) : null;

        if (! $path || ! is_file($path) || filesize($path) > self::MAX_BYTES) {
            return $this->result('no_image', [], null, $searchUrl, 'Este producto no tiene foto para buscarlo en la web.');
        }

        $key = (string) config('services.google.vision_key');
        if ($key === '') {
            return $this->result('unavailable', [], null, $searchUrl, 'Podés buscar este producto en Google.');
        }

        $cacheKey = 'product-web:'.$product->id.':'.md5($image->filename.'|'.$product->name);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $result = $this->lookup($product, $path, $key, $searchUrl);
        } catch (JevException|ConnectionException) {
            return $this->result('error', [], null, $searchUrl, 'No pudimos buscar este producto en la web en este momento.');
        }

        if ($result['status'] !== 'error') {
            Cache::put($cacheKey, $result, now()->addHours(24));
        }

        return $result;
    }

    private function lookup(Product $product, string $path, string $key, string $searchUrl): array
    {
        $response = Http::acceptJson()->timeout(20)->post(self::ENDPOINT.'?key='.urlencode($key), [
            'requests' => [[
                'image' => ['content' => base64_encode((string) file_get_contents($path))],
                'features' => [['type' => 'WEB_DETECTION', 'maxResults' => 10]],
            ]],
        ]);

        if ($response->failed() || $response->json('responses.0.error')) {
            Log::warning('Cloud Vision: búsqueda web fallida', ['status' => $response->status(), 'product_id' => $product->id]);

            return $this->result('error', [], null, $searchUrl, 'No pudimos buscar este producto en la web en este momento.');
        }

        $web = (array) $response->json('responses.0.webDetection', []);
        $bestGuess = $web['bestGuessLabels'][0]['label'] ?? null;

        $pages = collect((array) ($web['pagesWithMatchingImages'] ?? []))
            ->filter(fn ($p) => isset($p['url']) && str_starts_with($p['url'], 'https://') && trim(strip_tags($p['pageTitle'] ?? '')) !== '')
            ->unique(fn ($p) => parse_url($p['url'], PHP_URL_HOST).'|'.Str::lower(strip_tags($p['pageTitle'])))
            ->take(8)
            ->values();

        if ($pages->isEmpty()) {
            return $this->result('none', [], $bestGuess, $searchUrl, 'No encontramos este producto en otros sitios.');
        }

        $keyed = $pages->mapWithKeys(fn ($p, $i) => ['page_'.($i + 1) => [
            'title' => Str::limit(html_entity_decode(strip_tags($p['pageTitle']), ENT_QUOTES), 160, ''),
            'site' => (string) parse_url($p['url'], PHP_URL_HOST),
            'url' => $p['url'],
        ]]);

        // Una pregunta por página (cada una con su propio título), como recomienda la guía de TypeSafe.
        $questions = [];
        foreach ($keyed as $k => $page) {
            $questions["same_{$k}"] = [
                'type' => 'noul',
                'instructions' => "Is the web page titled `pages.{$k}.title` about the same kind of product as `product`?",
                'criteria' => [
                    'true' => 'The page sells, reviews or describes this product or the same model, for example a store listing of the same calculator.',
                    'false' => 'The page is about something else, for example a different kind of product, a news article or a page that only reuses the photo.',
                ],
            ];
        }

        $answers = $this->jev->ask([
            'product' => array_filter([
                'name' => $product->name,
                'description' => Str::limit((string) $product->description, 300),
                'image_label' => $bestGuess,
            ]),
            'pages' => $keyed->map(fn ($p) => ['title' => $p['title'], 'site' => $p['site']])->all(),
        ], $questions);

        $links = $keyed
            ->filter(fn ($p, $k) => $answers->noul("same_{$k}") >= self::YES)
            ->take(self::MAX_LINKS)
            ->map(fn ($p) => ['title' => $p['title'], 'url' => $p['url'], 'domain' => preg_replace('/^www\./', '', $p['site'])])
            ->values()->all();

        return $links
            ? $this->result('found', $links, $bestGuess, $searchUrl, '')
            : $this->result('none', [], $bestGuess, $searchUrl, 'No encontramos este producto en otros sitios.');
    }

    private function result(string $status, array $links, ?string $bestGuess, string $searchUrl, string $message): array
    {
        return ['status' => $status, 'links' => $links, 'best_guess' => $bestGuess, 'search_url' => $searchUrl, 'message' => $message];
    }
}
