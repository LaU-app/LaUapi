<?php

namespace App\Services\Agent;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Marketplace\PayPalCheckoutService;
use App\Services\Marketplace\ProductVideosService;
use App\Services\Marketplace\SmartShoppingService;
use App\Services\TypeSafe\JevException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Herramientas del agente de compras. Cada una lee datos reales (catálogo de comercios
 * verificados, reseñas filtradas por Jev, pedidos del estudiante) y devuelve:
 * - result: lo que ve el modelo de lenguaje;
 * - facts: datos para verificar su respuesta (Jev);
 * - cards: lo que ve el estudiante.
 * Ninguna herramienta cobra: propose_purchase solo prepara un botón que el estudiante confirma.
 */
final class AgentTools
{
    public const LABELS = [
        'search_products' => 'Buscó en comercios verificados',
        'get_product_details' => 'Revisó el detalle del producto',
        'get_product_reviews' => 'Buscó reseñas y videos',
        'propose_purchase' => 'Preparó la compra',
        'get_my_orders' => 'Revisó tus pedidos',
    ];

    /** Decisión de la última búsqueda (para atribuir la compra a la búsqueda inteligente). */
    public ?int $lastDecisionId = null;

    public function __construct(
        private readonly SmartShoppingService $search,
        private readonly ProductVideosService $videos,
    ) {}

    /** Definiciones en formato OpenAI (type=function). */
    public static function definitions(): array
    {
        $productId = ['type' => 'integer', 'description' => 'product_id de un producto que devolvió search_products'];

        return [
            self::fn('search_products', 'Busca productos en LaU Marketplace (solo comercios verificados que cobran con PayPal) y los ordena según la necesidad del estudiante.', [
                'query' => ['type' => 'string', 'description' => 'Qué necesita el estudiante, con sus palabras: producto, materia o uso. Ejemplo: "calculadora científica para Cálculo II"'],
                'max_budget' => ['type' => 'number', 'description' => 'Presupuesto máximo en USD, solo si el estudiante lo dijo'],
            ], ['query']),
            self::fn('get_product_details', 'Devuelve el detalle completo de un producto: descripción, precio, stock, estado, punto de entrega y comercio.', [
                'product_id' => $productId,
            ], ['product_id']),
            self::fn('get_product_reviews', 'Busca reseñas y videos de YouTube sobre un producto. Dice claramente si no hay.', [
                'product_id' => $productId,
            ], ['product_id']),
            self::fn('propose_purchase', 'Prepara la compra de un producto: la app le muestra al estudiante un resumen y el botón de PayPal para que él confirme y pague. Usala cuando el estudiante diga que quiere comprar.', [
                'product_id' => $productId,
                'quantity' => ['type' => 'integer', 'description' => 'Unidades (1 si no lo dijo)'],
            ], ['product_id']),
            self::fn('get_my_orders', 'Devuelve los últimos pedidos del estudiante con su estado de pago y de entrega.', [], []),
        ];
    }

    /** @return array{result: array, facts: array<string, array>, cards: list<array>} */
    public function run(User $user, string $name, array $args): array
    {
        try {
            return match ($name) {
                'search_products' => $this->searchProducts($user, $args),
                'get_product_details' => $this->productDetails($args),
                'get_product_reviews' => $this->productReviews($args),
                'propose_purchase' => $this->proposePurchase($user, $args),
                'get_my_orders' => $this->myOrders($user),
                default => self::error('Esa herramienta no existe.'),
            };
        } catch (JevException) {
            return self::error('La búsqueda no está disponible en este momento.');
        } catch (Throwable $e) {
            report($e);

            return self::error('No se pudo completar esta acción.');
        }
    }

    private function searchProducts(User $user, array $args): array
    {
        $query = Str::limit(trim((string) ($args['query'] ?? '')), 500, '');
        if (mb_strlen($query) < 3) {
            return self::error('Indicá qué producto o para qué materia lo necesita el estudiante.');
        }
        $budget = isset($args['max_budget']) && is_numeric($args['max_budget']) && $args['max_budget'] > 0
            ? (string) round((float) $args['max_budget'], 2) : null;

        $plan = $this->search->plan($user, $query, $budget);
        $this->lastDecisionId = $plan['decision_id'];

        // Datos de la búsqueda que el código ya conoce (el presupuesto también es un dato verificable).
        $facts = ['search' => array_filter([
            'student_request' => $query,
            'max_budget' => $plan['budget']['amount'] ? $plan['budget']['amount'].' USD' : null,
            'products_found' => count($plan['recommendations']),
        ], fn ($v) => $v !== null)];
        $products = [];
        foreach ($plan['recommendations'] as $rec) {
            $p = $rec['product'];
            $facts['p'.$p['id']] = self::fact($p, $rec['store']['name'], $rec['highlight'] ?? null);
            $products[] = [
                'product_id' => $p['id'],
                'name' => $p['name'],
                'price' => $p['price'].' '.$p['currency'],
                'stock' => $p['stock'],
                'store' => $rec['store']['name'],
                'match' => (int) round($rec['match_probability'] * 100).'%',
                'best_match' => $rec['recommended'],
                'within_budget' => $rec['within_budget'],
                'evidence_from_description' => $rec['highlight'] ?? null,
                'reasons' => $rec['reasons'],
            ];
        }

        return [
            'result' => [
                'outcome' => $plan['outcome'],
                'summary' => $plan['message']['title'],
                'budget' => $plan['budget']['amount'],
                'products' => $products,
            ],
            'facts' => $facts,
            'cards' => $plan['recommendations'] ? [[
                'type' => 'products',
                'decision_id' => $plan['decision_id'],
                'items' => $plan['recommendations'],
            ]] : [],
        ];
    }

    private function productDetails(array $args): array
    {
        $product = $this->product($args);
        if (! $product) {
            return self::error('No existe un producto disponible con ese product_id.');
        }

        $card = self::card($product);

        return [
            'result' => [
                'product_id' => $product->id,
                'name' => $product->name,
                'description' => Str::limit((string) $product->description, 600),
                'price' => $card['product']['price'].' USD',
                'stock' => $product->stock,
                'condition' => $product->condition,
                'category' => $product->category?->name,
                'delivery_point' => $product->delivery_point,
                'store' => $product->store?->name,
                'store_verified' => (bool) $product->store?->is_verified,
                'accepts_paypal' => (bool) $product->store?->accepts_paypal,
            ],
            'facts' => ['p'.$product->id => self::fact($card['product'], $product->store?->name)],
            'cards' => [['type' => 'products', 'decision_id' => null, 'items' => [$card]]],
        ];
    }

    private function productReviews(array $args): array
    {
        $product = $this->product($args);
        if (! $product) {
            return self::error('No existe un producto disponible con ese product_id.');
        }

        $videos = $this->videos->for($product);
        $list = collect($videos['videos'] ?? [])->map(fn ($v) => ['title' => $v['title'] ?? '', 'channel' => $v['channel'] ?? ''])->all();

        return [
            'result' => [
                'product_id' => $product->id,
                'status' => $videos['status'],
                'videos' => $list,
                'message' => $videos['message'] ?? null,
            ],
            'facts' => ['reviews_p'.$product->id => [
                'product' => $product->name,
                'status' => $videos['status'],
                'videos' => $list,
                'message' => $videos['message'] ?? null,
            ]],
            'cards' => [['type' => 'videos', 'product' => ['id' => $product->id, 'name' => $product->name]] + $videos],
        ];
    }

    private function proposePurchase(User $user, array $args): array
    {
        $product = $this->product($args);
        if (! $product) {
            return self::error('No existe un producto disponible con ese product_id.');
        }
        $quantity = max(1, (int) ($args['quantity'] ?? 1));

        $problem = match (true) {
            ! $product->store?->accepts_paypal => 'Este comercio todavía no cobra con PayPal.',
            $product->store->user_id === $user->id => 'Es un producto del propio estudiante; no lo puede comprar.',
            $quantity > PayPalCheckoutService::MAX_QUANTITY => 'Se pueden comprar hasta '.PayPalCheckoutService::MAX_QUANTITY.' unidades por pedido.',
            $product->stock < $quantity => "Solo quedan {$product->stock} unidades.",
            default => null,
        };
        if ($problem) {
            return self::error($problem);
        }

        $card = self::card($product);
        $total = PayPalCheckoutService::money(PayPalCheckoutService::cents($product->price) * $quantity);

        return [
            'result' => [
                'ready' => true,
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => $quantity,
                'total' => $total.' USD',
                'next_step' => 'La app ya le muestra al estudiante el botón para confirmar y pagar con PayPal. Vos no podés pagar.',
            ],
            'facts' => ['p'.$product->id => self::fact($card['product'], $product->store->name) + ['quantity' => $quantity, 'total' => $total.' USD']],
            'cards' => [['type' => 'purchase', 'decision_id' => $this->lastDecisionId, 'quantity' => $quantity, 'total' => $total] + $card],
        ];
    }

    private function myOrders(User $user): array
    {
        $orders = Order::where('buyer_id', $user->id)->with('items:id,order_id,product_name,quantity', 'store:id,name')
            ->latest()->limit(5)->get();

        $list = $orders->map(fn (Order $o) => [
            'code' => $o->code,
            'store' => $o->store?->name,
            'items' => $o->items->map(fn ($i) => $i->quantity.' x '.$i->product_name)->implode(', '),
            'total' => PayPalCheckoutService::money(PayPalCheckoutService::cents($o->total)).' USD',
            'payment' => $o->payment_status,
            'status' => $o->status,
            'date' => $o->created_at?->toDateString(),
        ])->all();

        return [
            'result' => ['orders' => $list, 'note' => $list ? null : 'El estudiante no tiene pedidos.'],
            'facts' => collect($list)->keyBy(fn ($o) => 'order_'.$o['code'])->all(),
            'cards' => [],
        ];
    }

    private function product(array $args): ?Product
    {
        $id = (int) ($args['product_id'] ?? 0);

        return $id > 0
            ? Product::with('store', 'category', 'images')->whereKey($id)->where('status', 'active')->first()
            : null;
    }

    /** Tarjeta de producto con la misma forma que una recomendación de la búsqueda inteligente. */
    public static function card(Product $product): array
    {
        return [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => Str::limit((string) $product->description, 400),
                'price' => PayPalCheckoutService::money(PayPalCheckoutService::cents($product->price)),
                'currency' => PayPalCheckoutService::CURRENCY,
                'stock' => $product->stock,
                'image_url' => $product->images->first()?->url,
                'category' => $product->category?->name,
                'condition' => $product->condition,
                'delivery_point' => $product->delivery_point,
            ],
            'highlight' => null,
            'reasons' => [],
            'store' => [
                'name' => $product->store?->name,
                'slug' => $product->store?->slug,
                'logo_url' => $product->store?->logo_url,
                'is_verified' => (bool) $product->store?->is_verified,
                'accepts_paypal' => (bool) $product->store?->accepts_paypal,
            ],
            'match_probability' => null,
            'within_budget' => null,
            'recommended' => false,
        ];
    }

    private static function fact(array $product, ?string $store, ?string $evidence = null): array
    {
        return array_filter([
            'name' => $product['name'],
            'price' => $product['price'].' '.($product['currency'] ?? 'USD'),
            'stock' => $product['stock'],
            'store' => $store,
            'condition' => $product['condition'] ?? null,
            'category' => $product['category'] ?? null,
            'delivery_point' => $product['delivery_point'] ?? null,
            'description' => $product['description'] ?? null,
            'evidence' => $evidence,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private static function error(string $message): array
    {
        return ['result' => ['error' => $message], 'facts' => [], 'cards' => []];
    }

    private static function fn(string $name, string $description, array $properties, array $required): array
    {
        return ['type' => 'function', 'function' => [
            'name' => $name,
            'description' => $description,
            'parameters' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required],
        ]];
    }
}
