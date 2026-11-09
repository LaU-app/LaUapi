<?php

namespace App\Services\Marketplace;

use App\Models\AssistantDecision;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\TypeSafe\JevClient;
use App\Services\TypeSafe\JevResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Compra inteligente con Jev".
 *
 * Reparto de responsabilidades:
 * - Laravel: valida la entrada, extrae cifras, consulta el catálogo real (solo comercios
 *   verificados que aceptan PayPal), compara precios con el presupuesto y decide con una
 *   tabla de decisión explícita.
 * - Jev (TypeSafe AI): solo juicios tipados con probabilidades sobre lenguaje: intención,
 *   categoría, qué significa cada cifra, si cada candidato real es lo que se pide, cuánto
 *   cubre la necesidad y cuál es el mejor.
 * - El estudiante: elige y confirma; nada se cobra sin su confirmación en PayPal.
 *
 * Jev nunca inventa productos, precios ni existencias, ni calcula totales.
 */
class SmartShoppingService
{
    public const MAX_CANDIDATES = 6;

    public const MAX_RESULTS = 4;

    private const STOPWORDS = [
        'necesito', 'quiero', 'busco', 'comprar', 'para', 'pero', 'como', 'algo', 'tengo', 'debo', 'mucho', 'poco',
        'unos', 'unas', 'este', 'esta', 'esos', 'esas', 'porque', 'presupuesto', 'maximo', 'dolares', 'dolar',
        'barato', 'barata', 'precio', 'cuesta', 'gastar', 'reservar', 'tiene', 'sirva', 'mejor', 'favor',
    ];

    public function __construct(private readonly JevClient $jev) {}

    /** @return array respuesta lista para la API */
    public function plan(User $user, string $query, ?string $maxBudget = null): array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');
        $categories = ProductCategory::where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'slug', 'description']);
        $mentions = self::moneyMentions($query);

        // 1) Entender la solicitud.
        $understand = $this->jev->ask(
            array_filter([
                'request' => $query,
                'categories' => $categories->mapWithKeys(fn ($c) => [$c->slug => "{$c->name}: {$c->description}"])->all(),
                'money_mentions' => $mentions ?: null,
            ]),
            SmartShoppingQuestions::understand(
                $categories->mapWithKeys(fn ($c) => [$c->slug => "{$c->name}: {$c->description}"])->all(),
                $mentions,
            ),
        );

        $intent = $understand->noul('purchase_intent');
        $itemNamed = $understand->noul('item_named');
        $category = $understand->choice('category');
        $categoryExists = $understand->noul('category_exists');
        $budget = $this->budget($understand, $mentions, $maxBudget);

        $interpretation = [
            'purchase_intent' => round($intent, 3),
            'item_named' => round($itemNamed, 3),
            'category' => null,
        ];

        $categorySlug = null;
        if ($categoryExists >= SmartShoppingQuestions::YES && ($category['probabilities'][$category['choice']] ?? 0) >= SmartShoppingQuestions::CATEGORY_MIN) {
            $categorySlug = $category['choice'];
            $model = $categories->firstWhere('slug', $categorySlug);
            $interpretation['category'] = [
                'slug' => $categorySlug,
                'name' => $model?->name,
                'probability' => round($category['probabilities'][$categorySlug], 3),
            ];
        }

        $answers = ['understand' => $understand->answers];
        $base = compact('query', 'budget', 'interpretation', 'categorySlug');

        // Tabla de decisión, parte 1: ¿se puede buscar?
        if ($intent < SmartShoppingQuestions::NO) {
            return $this->finish($user, $base, 'not_a_purchase', $understand->model, $answers, collect());
        }
        if ($intent < SmartShoppingQuestions::YES || $itemNamed < SmartShoppingQuestions::YES) {
            return $this->finish($user, $base, 'needs_clarification', $understand->model, $answers, collect());
        }

        // 2) Candidatos reales del catálogo (solo comercios verificados que aceptan PayPal).
        $candidates = $this->candidates($query, $categorySlug ? $categories->firstWhere('slug', $categorySlug)?->id : null);
        if ($candidates->isEmpty()) {
            return $this->finish($user, $base, 'no_match', $understand->model, $answers, collect());
        }

        $keyed = $candidates->mapWithKeys(fn (Product $p) => [self::candidateKey($p) => $p]);

        // 3) Jev juzga cada candidato frente a la necesidad. El precio no se envía: el
        //    presupuesto lo compara el código.
        $evaluation = $this->jev->ask(
            [
                'request' => $query,
                'candidates' => $keyed->map(fn (Product $p) => [
                    'name' => $p->name,
                    'description' => Str::limit((string) $p->description, 300),
                    'category' => $p->category?->name,
                    'store' => $p->store?->name,
                ])->all(),
            ],
            SmartShoppingQuestions::evaluate($keyed->map(fn (Product $p) => $p->name)->all()),
        );
        $answers['evaluate'] = $evaluation->answers;

        $scored = $this->score($keyed, $evaluation, $budget['amount']);

        return $this->finish($user, $base, $this->decide($scored), $evaluation->model, $answers, $scored);
    }

    /**
     * Tabla de decisión, parte 2 (en código, no en Jev):
     * - recommendation:  hay un candidato que es lo pedido (≥0.7), lo cubre (≥2/3) y entra en el presupuesto.
     * - over_budget:     hay candidatos que son lo pedido, pero ninguno entra en el presupuesto.
     * - confirm_choice:  solo hay candidatos dudosos (0.3–0.7) dentro del presupuesto: el estudiante confirma.
     * - no_match:        nada parece lo pedido.
     */
    private function decide(Collection $scored): string
    {
        $eligible = $scored->filter(fn ($c) => $c['eligible']);

        return match (true) {
            $eligible->contains(fn ($c) => $c['within_budget'] !== false) => 'recommendation',
            $eligible->isNotEmpty() => 'over_budget',
            $scored->contains(fn ($c) => $c['uncertain'] && $c['within_budget'] !== false) => 'confirm_choice',
            default => 'no_match',
        };
    }

    private function score(Collection $keyed, JevResult $evaluation, ?string $budget): Collection
    {
        $budgetCents = $budget !== null ? PayPalCheckoutService::cents($budget) : null;
        $best = $evaluation->has('best') ? $evaluation->choice('best')['probabilities'] : [];

        $single = $keyed->count() === 1;

        return $keyed->map(function (Product $product, string $key) use ($evaluation, $budgetCents, $best, $single) {
            $match = $evaluation->noul('match_'.$key);
            $fit = $evaluation->score('fit_'.$key);
            $academic = $evaluation->score('academic_'.$key);
            $priceCents = PayPalCheckoutService::cents($product->price);

            return [
                'key' => $key,
                'product' => $product,
                'match' => $match,
                'fit' => $fit['score'],
                'academic' => $academic['score'],
                'best' => $best[$key] ?? ($single ? 1.0 : 0.0),
                'eligible' => $match >= SmartShoppingQuestions::YES && $fit['score'] >= SmartShoppingQuestions::FIT_MIN,
                'uncertain' => $match >= SmartShoppingQuestions::NO && $match < SmartShoppingQuestions::YES,
                // Comparación aritmética en código (Jev no es calculadora).
                'within_budget' => $budgetCents === null ? null : $priceCents <= $budgetCents,
                'price_cents' => $priceCents,
            ];
        })->values()->sort(function ($a, $b) {
            return [$b['eligible'], $b['within_budget'] !== false, $b['fit'], $b['best'], $b['match'], -$b['price_cents']]
                <=> [$a['eligible'], $a['within_budget'] !== false, $a['fit'], $a['best'], $a['match'], -$a['price_cents']];
        })->values();
    }

    /** Productos reales que pueden pagarse con PayPal, priorizando categoría y palabras de la consulta. */
    private function candidates(string $query, ?int $categoryId): Collection
    {
        $base = fn () => Product::available()
            ->whereHas('store', fn ($q) => $q->acceptingPaypal())
            ->with(['images', 'category:id,name,slug', 'store:'.\App\Models\Store::CARD_COLUMNS.',is_active,description']);

        $terms = self::terms($query);
        $rank = function (Collection $products) use ($terms) {
            return $products->map(function (Product $p) use ($terms) {
                $haystack = Str::ascii(Str::lower($p->name.' '.$p->description));
                $p->setAttribute('_hits', collect($terms)->filter(fn ($t) => str_contains($haystack, $t))->count());

                return $p;
            });
        };

        $pool = collect();
        if ($categoryId) {
            $pool = $rank($base()->where('category_id', $categoryId)->latest()->limit(40)->get());
        }

        // Fuera de la categoría solo entran productos que mencionan palabras de la consulta.
        // La coincidencia se hace en PHP, sin tildes, para no depender de la intercalación de la BD.
        if ($pool->count() < self::MAX_CANDIDATES && $terms !== []) {
            $others = $rank($base()->when($categoryId, fn ($q) => $q->where('category_id', '!=', $categoryId))
                ->latest()->limit(200)->get())->filter(fn ($p) => $p->_hits > 0);
            $pool = $pool->concat($others);
        }

        return $pool->unique('id')
            ->sort(fn ($a, $b) => [$b->_hits, PayPalCheckoutService::cents($a->price)] <=> [$a->_hits, PayPalCheckoutService::cents($b->price)])
            ->take(self::MAX_CANDIDATES)
            ->values();
    }

    /**
     * Presupuesto máximo de esta búsqueda (no se guarda como saldo).
     * Prioridad: campo explícito → cifra que Jev identifica con claridad como tope en el texto.
     */
    private function budget(JevResult $understand, array $mentions, ?string $field): array
    {
        $unclear = [];

        if ($field !== null) {
            return ['amount' => PayPalCheckoutService::money(PayPalCheckoutService::cents($field)), 'source' => 'field', 'unclear_amounts' => []];
        }

        $best = null;
        foreach ($mentions as $key => $text) {
            $p = $understand->choice('budget_role_'.$key)['probabilities']['maximum_price'] ?? 0.0;
            if ($p >= SmartShoppingQuestions::YES && ($best === null || $p > $best['p'])) {
                $best = ['p' => $p, 'text' => $text];
            } elseif ($p >= SmartShoppingQuestions::NO && $p < SmartShoppingQuestions::YES) {
                $unclear[] = $text;
            }
        }

        if ($best) {
            return ['amount' => self::parseAmount($best['text']), 'source' => 'message', 'unclear_amounts' => []];
        }

        return ['amount' => null, 'source' => 'none', 'unclear_amounts' => $unclear];
    }

    private function finish(User $user, array $base, string $outcome, string $model, array $answers, Collection $scored): array
    {
        $recommended = $outcome === 'recommendation'
            ? $scored->first(fn ($c) => $c['eligible'] && $c['within_budget'] !== false)
            : null;

        $decision = AssistantDecision::create([
            'user_id' => $user->id,
            'query' => Str::limit($base['query'], 500, ''),
            'max_budget' => $base['budget']['amount'],
            'budget_source' => $base['budget']['source'],
            'category_slug' => $base['categorySlug'],
            'outcome' => $outcome,
            'model' => Str::limit($model, 40, ''),
            'answers' => $answers,
            'candidates' => $scored->map(fn ($c) => [
                'product_id' => $c['product']->id,
                'price' => $c['product']->price,
                'match' => round($c['match'], 4),
                'fit' => round($c['fit'], 3),
                'academic' => round($c['academic'], 3),
                'within_budget' => $c['within_budget'],
            ])->all(),
            'recommended_product_id' => $recommended['product']->id ?? null,
        ]);

        $shown = match ($outcome) {
            'recommendation', 'over_budget' => $scored->filter(fn ($c) => $c['eligible'] || $c['uncertain']),
            'confirm_choice' => $scored->filter(fn ($c) => $c['uncertain'] && $c['within_budget'] !== false),
            default => collect(),
        };

        return [
            'decision_id' => $decision->id,
            'outcome' => $outcome,
            'model' => $model,
            'budget' => $base['budget'],
            'interpretation' => $base['interpretation'],
            'recommendations' => $shown->take(self::MAX_RESULTS)->values()->map(fn ($c) => [
                'product' => [
                    'id' => $c['product']->id,
                    'name' => $c['product']->name,
                    'description' => Str::limit((string) $c['product']->description, 180),
                    'price' => PayPalCheckoutService::money($c['price_cents']),
                    'currency' => PayPalCheckoutService::CURRENCY,
                    'stock' => $c['product']->stock,
                    'image_url' => $c['product']->images->first()?->url,
                    'category' => $c['product']->category?->name,
                ],
                'store' => [
                    'name' => $c['product']->store->name,
                    'slug' => $c['product']->store->slug,
                    'logo_url' => $c['product']->store->logo_url,
                    'is_verified' => $c['product']->store->is_verified,
                    'accepts_paypal' => $c['product']->store->accepts_paypal,
                ],
                'match_probability' => round($c['match'], 3),
                'fit' => ['score' => round($c['fit'], 2), 'label' => SmartShoppingQuestions::FIT_LABELS[(int) round($c['fit'])]],
                'academic' => ['score' => round($c['academic'], 2), 'label' => SmartShoppingQuestions::ACADEMIC_LABELS[(int) round($c['academic'])]],
                'within_budget' => $c['within_budget'],
                'recommended' => $recommended !== null && $recommended['product']->id === $c['product']->id,
            ])->all(),
            'message' => SmartShoppingCopy::for($outcome, $base, $recommended),
        ];
    }

    /** Cifras de dinero tal como aparecen en el texto ($25, 25 dólares, US$ 12.50). */
    public static function moneyMentions(string $query): array
    {
        preg_match_all('/(?:US\$|\$)\s?\d{1,5}(?:[.,]\d{1,2})?|\d{1,5}(?:[.,]\d{1,2})?\s?(?:d[oó]lares|usd)\b/iu', $query, $matches);

        $mentions = [];
        foreach (array_slice(array_values(array_unique($matches[0])), 0, 5) as $i => $text) {
            $mentions['m'.($i + 1)] = trim($text);
        }

        return $mentions;
    }

    public static function parseAmount(string $text): string
    {
        preg_match('/\d{1,5}(?:[.,]\d{1,2})?/', $text, $m);

        return PayPalCheckoutService::money(PayPalCheckoutService::cents(str_replace(',', '.', $m[0] ?? '0')));
    }

    private static function terms(string $query): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::ascii(Str::lower($query))) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn ($w) => strlen($w) >= 4 && ! ctype_digit($w) && ! in_array($w, self::STOPWORDS, true)
        )));
    }

    /** Nombre de opción descriptivo para Jev (los nombres de opción sí se envían al modelo). */
    private static function candidateKey(Product $product): string
    {
        return Str::limit(Str::slug($product->name, '_'), 40, '').'_p'.$product->id;
    }
}
