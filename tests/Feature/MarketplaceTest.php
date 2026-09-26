<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Aislamos las migraciones del módulo de las legacy que son solo-MySQL.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        DB::purge('sqlite');
        \Tests\Support\MarketplaceSchema::migrate();

        ProductCategory::create(['name' => 'Libros', 'slug' => 'libros', 'icon' => 'book-outline', 'sort_order' => 1]);
        ProductCategory::create(['name' => 'Ropa', 'slug' => 'ropa', 'icon' => 'shirt-outline', 'sort_order' => 2]);
    }

    private function user(string $name): User
    {
        return User::create(['name' => $name, 'username' => $name, 'email' => $name.'@example.test', 'password' => 'testing-password']);
    }

    private function product(User $seller, array $overrides = []): Product
    {
        return $seller->products()->create([
            'category_id' => 1, 'name' => 'Libro de Cálculo', 'price' => 20.00, 'stock' => 5, 'status' => 'active',
            ...$overrides,
        ]);
    }

    private function checkout(User $buyer, array $items, ?string $uuid = null)
    {
        Sanctum::actingAs($buyer);

        return $this->postJson('/api/marketplace/orders', [
            'uuid_cliente' => $uuid ?? (string) Str::uuid(),
            'items' => $items,
        ]);
    }

    public function test_solo_el_dueno_puede_editar_o_borrar_su_producto(): void
    {
        $ana = $this->user('ana');
        $beto = $this->user('beto');
        $product = $this->product($ana);

        Sanctum::actingAs($beto);
        $this->putJson("/api/marketplace/products/{$product->id}", ['name' => 'Robado'])->assertNotFound();
        $this->deleteJson("/api/marketplace/products/{$product->id}")->assertNotFound();

        Sanctum::actingAs($ana);
        $this->putJson("/api/marketplace/products/{$product->id}", ['name' => 'Mío'])->assertOk();
        $this->deleteJson("/api/marketplace/products/{$product->id}")->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_el_user_id_del_payload_se_ignora(): void
    {
        $ana = $this->user('ana');
        $beto = $this->user('beto');

        Sanctum::actingAs($ana);
        $response = $this->postJson('/api/marketplace/products', [
            'name' => 'Mochila', 'price' => 10, 'stock' => 1, 'category_id' => 2, 'user_id' => $beto->id,
        ])->assertCreated();

        $this->assertSame($ana->id, $response->json('data.user_id'));
    }

    public function test_un_carrito_multivendedor_genera_un_pedido_por_vendedor(): void
    {
        $ana = $this->user('ana');
        $beto = $this->user('beto');
        $cami = $this->user('cami');

        $deAna = $this->product($ana, ['name' => 'Libro', 'price' => 20.00, 'stock' => 5]);
        $otroDeAna = $this->product($ana, ['name' => 'Cuaderno', 'price' => 5.00, 'stock' => 5]);
        $deBeto = $this->product($beto, ['name' => 'Sudadera', 'price' => 25.00, 'stock' => 2, 'category_id' => 2]);

        $response = $this->checkout($cami, [
            ['product_id' => $deAna->id, 'quantity' => 2],
            ['product_id' => $otroDeAna->id, 'quantity' => 1],
            ['product_id' => $deBeto->id, 'quantity' => 1],
        ])->assertCreated();

        $orders = $response->json('data');
        $this->assertCount(2, $orders);

        $porVendedor = collect($orders)->keyBy('seller_id');
        $this->assertSame('45.00', $porVendedor[$ana->id]['total']);   // 20*2 + 5
        $this->assertSame('25.00', $porVendedor[$beto->id]['total']);
        $this->assertCount(2, $porVendedor[$ana->id]['items']);
        $this->assertCount(1, $porVendedor[$beto->id]['items']);

        $this->assertSame(3, $deAna->fresh()->stock);
        $this->assertSame(1, $deBeto->fresh()->stock);
    }

    public function test_el_mismo_uuid_cliente_no_duplica_el_apartado_ni_el_descuento(): void
    {
        $ana = $this->user('ana');
        $beto = $this->user('beto');
        $product = $this->product($ana, ['stock' => 5]);
        $uuid = (string) Str::uuid();

        $primera = $this->checkout($beto, [['product_id' => $product->id, 'quantity' => 2]], $uuid)->assertCreated();
        $segunda = $this->checkout($beto, [['product_id' => $product->id, 'quantity' => 2]], $uuid)->assertCreated();

        $this->assertSame($primera->json('data.0.id'), $segunda->json('data.0.id'));
        $this->assertSame(1, Order::count());
        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_no_se_puede_sobrevender(): void
    {
        $ana = $this->user('ana');
        $beto = $this->user('beto');
        $product = $this->product($ana, ['stock' => 2]);

        $this->checkout($beto, [['product_id' => $product->id, 'quantity' => 3]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, Order::count());
    }

    public function test_no_se_puede_apartar_el_producto_propio(): void
    {
        $ana = $this->user('ana');
        $product = $this->product($ana);

        $this->checkout($ana, [['product_id' => $product->id, 'quantity' => 1]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_solo_el_vendedor_marca_la_entrega(): void
    {
        $ana = $this->user('ana');
        $beto = $this->user('beto');
        $product = $this->product($ana);

        $orderId = $this->checkout($beto, [['product_id' => $product->id, 'quantity' => 1]])->json('data.0.id');

        Sanctum::actingAs($beto);
        $this->postJson("/api/marketplace/orders/{$orderId}/deliver")->assertForbidden();

        Sanctum::actingAs($ana);
        $this->postJson("/api/marketplace/orders/{$orderId}/deliver")
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        // La entrega no devuelve stock: ya se descontó al apartar.
        $this->assertSame(4, $product->fresh()->stock);

        $this->postJson("/api/marketplace/orders/{$orderId}/deliver")->assertStatus(422);
    }

    public function test_cancelar_devuelve_el_stock_una_sola_vez(): void
    {
        $ana = $this->user('ana');
        $beto = $this->user('beto');
        $product = $this->product($ana, ['stock' => 5]);

        $orderId = $this->checkout($beto, [['product_id' => $product->id, 'quantity' => 2]])->json('data.0.id');
        $this->assertSame(3, $product->fresh()->stock);

        Sanctum::actingAs($beto);
        $this->postJson("/api/marketplace/orders/{$orderId}/cancel", ['reason' => 'Ya no lo necesito'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_by', $beto->id);

        $this->assertSame(5, $product->fresh()->stock);

        $this->postJson("/api/marketplace/orders/{$orderId}/cancel")->assertStatus(422);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_un_tercero_no_ve_el_pedido(): void
    {
        $ana = $this->user('ana');
        $beto = $this->user('beto');
        $cami = $this->user('cami');
        $product = $this->product($ana);

        $orderId = $this->checkout($beto, [['product_id' => $product->id, 'quantity' => 1]])->json('data.0.id');

        foreach ([$ana, $beto] as $parte) {
            Sanctum::actingAs($parte);
            $this->getJson("/api/marketplace/orders/{$orderId}")->assertOk();
        }

        Sanctum::actingAs($cami);
        $this->getJson("/api/marketplace/orders/{$orderId}")->assertNotFound();
    }

    public function test_los_nombres_de_imagen_no_permiten_salirse_de_la_carpeta(): void
    {
        $ana = $this->user('ana');
        Sanctum::actingAs($ana);

        // Las rutas con ../ las corta la regex (error por ítem: images.0);
        // un nombre con formato válido pero inexistente lo corta el file_exists.
        $casos = [
            '../../.env' => 'images.0',
            '../../../config/app.php' => 'images.0',
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.webp' => 'images',
        ];

        foreach ($casos as $filename => $errorKey) {
            $this->postJson('/api/marketplace/products', [
                'name' => 'Hack', 'price' => 1, 'stock' => 1, 'category_id' => 1, 'images' => [$filename],
            ])->assertStatus(422)->assertJsonValidationErrors($errorKey);
        }

        $this->assertSame(0, Product::count());
    }

    public function test_el_catalogo_publico_solo_muestra_activos_con_stock(): void
    {
        $ana = $this->user('ana');
        $this->product($ana, ['name' => 'Visible']);
        $this->product($ana, ['name' => 'Pausado', 'status' => 'paused']);
        $this->product($ana, ['name' => 'Agotado', 'stock' => 0]);

        $names = collect($this->getJson('/api/marketplace/products')->assertOk()->json('data.data'))->pluck('name');

        $this->assertEqualsCanonicalizing(['Visible'], $names->all());

        // El vendedor sí ve los suyos, incluidos pausados y sin stock.
        Sanctum::actingAs($ana);
        $this->assertSame(3, $this->getJson('/api/marketplace/my/products')->assertOk()->json('data.total'));
    }

    public function test_solo_se_puede_tener_una_tienda_y_adopta_los_productos_sueltos(): void
    {
        $ana = $this->user('ana');
        $product = $this->product($ana);
        $this->assertNull($product->store_id);

        Sanctum::actingAs($ana);
        $this->postJson('/api/marketplace/store', ['name' => 'Tech Hub', 'category_id' => 1])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'tech-hub');

        $this->assertNotNull($product->fresh()->store_id);

        $this->postJson('/api/marketplace/store', ['name' => 'Otra', 'category_id' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_el_panel_calcula_metricas_reales(): void
    {
        $ana = $this->user('ana');
        $beto = $this->user('beto');
        $entregado = $this->product($ana, ['price' => 30.00, 'stock' => 5]);
        $pendiente = $this->product($ana, ['name' => 'Otro', 'price' => 10.00, 'stock' => 3]);

        $entregadoId = $this->checkout($beto, [['product_id' => $entregado->id, 'quantity' => 1]])->json('data.0.id');
        Sanctum::actingAs($ana);
        $this->postJson("/api/marketplace/orders/{$entregadoId}/deliver")->assertOk();

        $this->checkout($beto, [['product_id' => $pendiente->id, 'quantity' => 1]])->assertCreated();

        Sanctum::actingAs($ana);
        $metrics = $this->getJson('/api/marketplace/my/dashboard')->assertOk()->json('data.metrics');

        $this->assertEquals(30.0, $metrics['delivered_this_month']);
        $this->assertSame(1, $metrics['pending_orders']);
        $this->assertSame(2, $metrics['published_products']);
        $this->assertSame(1, $metrics['delivered_total']);
    }
}
