<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Feria;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\su_ad;
use App\Models\Universidad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MarketplaceFixtures;
use Tests\TestCase;

/** Entregas solo dentro de un campus: ubicación de campus, punto de entrega, mapa, ferias y enlaces por foto. */
class CampusDeliveryTest extends TestCase
{
    use MarketplaceFixtures;
    use RefreshDatabase;

    private const JEV = 'https://api.typesafe.ai/v1/systemone';

    private Universidad $ues;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFakeServices();
        config(['services.google' => ['server_key' => 'fake-google-server-key', 'vision_key' => 'fake-google-server-key']]);

        // Ciudad Universitaria (centro aproximado) con 800 m de área de entrega.
        $this->ues = Universidad::create(['nombre' => 'Universidad de El Salvador', 'siglas' => 'UES', 'dominio' => 'ues.edu.sv']);
        $this->ues->forceFill(['latitud' => 13.7186, 'longitud' => -89.2030, 'radio_entrega_m' => 800])->save();

        $this->student = User::factory()->create();
        $this->student->forceFill(['universidad_id' => $this->ues->id])->save();
        Sanctum::actingAs($this->student);
    }

    private function productPayload(array $overrides = []): array
    {
        return array_merge(['name' => 'Bata de laboratorio', 'price' => 14, 'stock' => 2, 'category_id' => $this->category()->id], $overrides);
    }

    public function test_product_is_published_for_the_sellers_campus_with_a_point_inside(): void
    {
        $this->postJson('/api/marketplace/products', $this->productPayload([
            'delivery_point' => 'Biblioteca central', 'delivery_lat' => 13.7190, 'delivery_lng' => -89.2025,
        ]))->assertCreated()
            ->assertJsonPath('data.universidad.siglas', 'UES')
            ->assertJsonPath('data.delivery_lat', 13.719);
    }

    public function test_delivery_point_outside_the_campus_is_rejected(): void
    {
        // Centro de San Salvador, a unos 3 km de Ciudad Universitaria.
        $this->postJson('/api/marketplace/products', $this->productPayload(['delivery_lat' => 13.6989, 'delivery_lng' => -89.1914]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.delivery_lat.0', 'El punto de entrega tiene que estar dentro de UES.');
    }

    public function test_a_campus_is_required(): void
    {
        $this->student->forceFill(['universidad_id' => null])->save();

        $this->postJson('/api/marketplace/products', $this->productPayload())
            ->assertUnprocessable()->assertJsonValidationErrors('universidad_id');

        $this->postJson('/api/marketplace/products', $this->productPayload(['universidad_id' => $this->ues->id]))->assertCreated();
    }

    public function test_changing_campus_clears_the_old_delivery_point(): void
    {
        $uca = Universidad::create(['nombre' => 'UCA', 'siglas' => 'UCA']);
        $id = $this->postJson('/api/marketplace/products', $this->productPayload(['delivery_lat' => 13.7190, 'delivery_lng' => -89.2025]))->json('data.id');

        $this->putJson("/api/marketplace/products/{$id}", ['universidad_id' => $uca->id])->assertOk()
            ->assertJsonPath('data.universidad.siglas', 'UCA')
            ->assertJsonPath('data.delivery_lat', null);
    }

    public function test_campus_map_lists_campuses_with_counts_and_upcoming_fairs(): void
    {
        $store = $this->verifiedStore();
        $this->productFor($store)->forceFill(['universidad_id' => $this->ues->id])->save();
        Feria::create(['universidad_id' => $this->ues->id, 'titulo' => 'Feria de emprendedores', 'inicia_en' => now()->addDay(), 'termina_en' => now()->addDays(2)]);
        Feria::create(['universidad_id' => $this->ues->id, 'titulo' => 'Feria pasada', 'inicia_en' => now()->subDays(5), 'termina_en' => now()->subDays(4)]);

        $this->getJson('/api/marketplace/campus-map')->assertOk()
            ->assertJsonPath('data.my_campus_id', $this->ues->id)
            ->assertJsonPath('data.campuses.0.siglas', 'UES')
            ->assertJsonPath('data.campuses.0.products_count', 1)
            ->assertJsonPath('data.campuses.0.stores_count', 1)
            ->assertJsonCount(1, 'data.campuses.0.ferias')
            ->assertJsonPath('data.campuses.0.ferias.0.titulo', 'Feria de emprendedores')
            ->assertJsonMissingPath('data.campuses.0.google_place_id');
    }

    public function test_team_sets_campus_location_and_creates_a_fair_with_a_campus_ad(): void
    {
        su_ad::create(['name' => 'Equipo', 'email' => 'equipo@lau.test', 'password' => Hash::make('clave-larga-2026'), 'password_verific_modify' => Hash::make('x'), 'is_admin' => true, 'imagen' => 'img/usuario.svg', 'last_login' => now()]);
        $token = $this->postJson('/api/lau-admin/login', ['email' => 'equipo@lau.test', 'password' => 'clave-larga-2026'])->json('data.token');
        $this->app['auth']->forgetGuards();
        $admin = fn () => $this->withHeader('Authorization', 'Bearer '.$token);

        $admin()->putJson("/api/lau-admin/universidades/{$this->ues->id}/ubicacion", ['latitud' => 40.4, 'longitud' => -3.7, 'radio_entrega_m' => 700])
            ->assertUnprocessable()->assertJsonPath('errors.latitud.0', 'La ubicación tiene que estar en El Salvador.');
        $admin()->putJson("/api/lau-admin/universidades/{$this->ues->id}/ubicacion", ['latitud' => 13.7187, 'longitud' => -89.2032, 'radio_entrega_m' => 900])
            ->assertOk()->assertJsonPath('data.radio_entrega_m', 900);

        // El formulario manda hora de El Salvador sin zona; la base guarda en UTC.
        $local = now('America/El_Salvador')->addDays(3)->setSeconds(0);
        $admin()->postJson('/api/lau-admin/ferias', [
            'universidad_id' => $this->ues->id, 'titulo' => 'Feria de tecnología', 'lugar' => 'Plaza central',
            'inicia_en' => $local->format('Y-m-d\TH:i'), 'termina_en' => $local->copy()->addHours(6)->format('Y-m-d\TH:i'),
            'crear_anuncio' => true,
        ])->assertCreated()->assertJsonPath('data.universidad.siglas', 'UES');

        $feria = Feria::sole();
        $this->assertSame($local->format('Y-m-d H:i'), $feria->inicia_en->copy()->setTimezone('America/El_Salvador')->format('Y-m-d H:i'));
        $this->assertSame($local->copy()->setTimezone('UTC')->format('H:i'), $feria->inicia_en->format('H:i'));

        $banner = Banner::sole();
        $this->assertSame($this->ues->id, $banner->universidad_id);
        $this->assertSame('Feria de tecnología', $banner->title);
        $this->assertSame(Feria::sole()->banner_id, $banner->id);

        // Las fechas tienen que tener sentido.
        $admin()->postJson('/api/lau-admin/ferias', [
            'universidad_id' => $this->ues->id, 'titulo' => 'Mal', 'inicia_en' => '2026-12-02T10:00', 'termina_en' => '2026-12-01T10:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('termina_en');
    }

    public function test_locate_command_saves_the_place_jev_picks_and_skips_unclear_ones(): void
    {
        $uca = Universidad::create(['nombre' => 'Universidad Centroamericana José Simeón Cañas', 'siglas' => 'UCA']);
        Http::fake([
            'https://places.googleapis.com/*' => Http::response(['places' => [
                ['id' => 'p-libreria', 'displayName' => ['text' => 'Librería UCA'], 'formattedAddress' => 'Antiguo Cuscatlán', 'location' => ['latitude' => 13.6790, 'longitude' => -89.2350], 'types' => ['book_store']],
                ['id' => 'p-uca', 'displayName' => ['text' => 'Universidad Centroamericana José Simeón Cañas'], 'formattedAddress' => 'Bulevar Los Próceres, Antiguo Cuscatlán', 'location' => ['latitude' => 13.6797, 'longitude' => -89.2360], 'types' => ['university']],
            ]]),
            self::JEV => function (Request $request) {
                $options = array_keys($request['questions']['main_campus']['criteria']);
                $uni = collect($options)->first(fn ($o) => str_starts_with($o, 'universidad_centroamericana'));

                return Http::response(['model' => 'jev-1.14.0', 'answers' => [
                    'campus_listed' => ['type' => 'noul', 'noul' => 0.96],
                    'main_campus' => ['type' => 'choice', 'choice' => $uni, 'confidence' => 0.8, 'probabilities' => collect($options)->mapWithKeys(fn ($o) => [$o => $o === $uni ? 0.9 : 0.1])->all()],
                ]]);
            },
        ]);

        $this->artisan('lau:ubicar-universidades', ['--id' => $uca->id])->assertSuccessful();

        $uca->refresh();
        $this->assertEqualsWithDelta(13.6797, $uca->latitud, 0.0001);
        $this->assertSame('p-uca', $uca->google_place_id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'places:searchText') && $r->header('X-Goog-Api-Key')[0] === 'fake-google-server-key');
    }

    public function test_locate_command_does_not_guess_when_jev_is_unsure(): void
    {
        $uca = Universidad::create(['nombre' => 'UCA']);
        Http::fake([
            'https://places.googleapis.com/*' => Http::response(['places' => [
                ['id' => 'x', 'displayName' => ['text' => 'Colegio'], 'formattedAddress' => 'San Salvador', 'location' => ['latitude' => 13.70, 'longitude' => -89.20], 'types' => ['school']],
            ]]),
            self::JEV => Http::response(['model' => 'jev', 'answers' => [
                'campus_listed' => ['type' => 'noul', 'noul' => 0.12],
                'main_campus' => ['type' => 'choice', 'choice' => 'colegio_1', 'confidence' => 1, 'probabilities' => ['colegio_1' => 1.0]],
            ]]),
        ]);

        $this->artisan('lau:ubicar-universidades', ['--id' => $uca->id])->assertSuccessful();
        $this->assertNull($uca->fresh()->latitud);
    }

    public function test_web_links_from_the_product_photo_are_filtered_by_jev(): void
    {
        $product = $this->productFor($this->verifiedStore());
        $filename = 'prueba-web-'.uniqid().'.png';
        $dir = public_path('marketplace');
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/'.$filename, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII='));
        ProductImage::forceCreate(['product_id' => $product->id, 'filename' => $filename, 'position' => 0]);

        Http::fake([
            'https://vision.googleapis.com/*' => Http::response(['responses' => [['webDetection' => [
                'bestGuessLabels' => [['label' => 'casio fx-991la']],
                'pagesWithMatchingImages' => [
                    ['url' => 'https://tienda.example.com/casio-fx-991', 'pageTitle' => 'Calculadora científica Casio fx-991LA'],
                    ['url' => 'https://noticias.example.com/foto', 'pageTitle' => 'Fotos del concierto del fin de semana'],
                ],
            ]]]]),
            self::JEV => function (Request $request) {
                $answers = [];
                foreach ($request['questions'] as $key => $q) {
                    $page = $request['state']['pages'][substr($key, 5)]['title'] ?? '';
                    $answers[$key] = ['type' => 'noul', 'noul' => str_contains($page, 'Calculadora') ? 0.93 : 0.04];
                }

                return Http::response(['model' => 'jev', 'answers' => $answers]);
            },
        ]);

        try {
            $this->getJson("/api/marketplace/products/{$product->id}/web-links")->assertOk()
                ->assertJsonPath('data.status', 'found')
                ->assertJsonCount(1, 'data.links')
                ->assertJsonPath('data.links.0.domain', 'tienda.example.com')
                ->assertJsonPath('data.best_guess', 'casio fx-991la');
        } finally {
            @unlink($dir.'/'.$filename);
        }

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'vision.googleapis.com') && $r['requests'][0]['features'][0]['type'] === 'WEB_DETECTION');
    }

    public function test_web_links_without_photo_or_key_degrade_gracefully(): void
    {
        $product = $this->productFor($this->verifiedStore());
        Http::fake();

        $this->getJson("/api/marketplace/products/{$product->id}/web-links")->assertOk()->assertJsonPath('data.status', 'no_image');
        Http::assertNothingSent();
    }
}
