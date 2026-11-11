<?php

namespace Database\Seeders;

use App\Models\Carrera;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\StorePaymentGateway;
use App\Models\StoreVerification;
use App\Models\Universidad;
use App\Models\User;
use App\Services\Marketplace\CampusGeo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Catálogo grande de demostración para LaU Marketplace (datos ficticios, productos reales):
 *  - 8 negocios verificados de El Salvador (4 cobran con PayPal, 4 venden con apartado),
 *  - 8 emprendimientos estudiantiles con tienda pero sin verificar,
 *  - 16 estudiantes que venden por su cuenta, con publicaciones de calidad muy variada.
 *
 * Los datos viven en database/seeders/catalog/catalog.json (la misma fuente que usa
 * fetch_images.py para bajar las fotos). No forma parte de DatabaseSeeder: se corre a mano.
 *
 *   php artisan db:seed --class=MarketplaceCatalogSeeder
 *
 * Es idempotente: relanzarlo actualiza los mismos usuarios, tiendas y productos.
 */
class MarketplaceCatalogSeeder extends Seeder
{
    private const DIR = __DIR__.'/catalog';

    /** Tienda de la que se copia la conexión de PayPal Sandbox (solo hay un comercio de prueba). */
    private const PAYPAL_SOURCE_SLUG = 'papeleria-campus-central';

    /** @var array<string, int> */
    private array $categories = [];

    /** @var \Illuminate\Support\Collection<string, Universidad> */
    private $campuses;

    private array $deliveryPoints = [];

    private array $summary = ['paypal' => 0, 'verified_no_paypal' => 0, 'ventures' => 0, 'students' => 0, 'products' => 0, 'images' => 0];

    private array $missingImages = [];

    public function run(): void
    {
        $this->call(ProductCategorySeeder::class);

        $catalog = json_decode(File::get(self::DIR.'/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->categories = ProductCategory::pluck('id', 'slug')->all();
        $this->campuses = Universidad::all()->keyBy('dominio');
        $this->deliveryPoints = $catalog['delivery_points'];

        File::ensureDirectoryExists(public_path('marketplace'));
        File::ensureDirectoryExists(public_path('tiendas'));

        $paypal = $this->paypalConnection();

        DB::transaction(function () use ($catalog, $paypal) {
            foreach ($catalog['businesses'] as $index => $business) {
                $this->seedBusiness($business, $index, $paypal);
            }
            foreach ($catalog['ventures'] as $venture) {
                $this->seedVenture($venture);
            }
            foreach ($catalog['students'] as $student) {
                $this->seedStudent($student);
            }
        });

        if ($this->missingImages) {
            $this->command?->warn('Fotos que faltan en catalog/productos (correr fetch_images.py): '.implode(', ', array_unique($this->missingImages)));
        }

        $s = $this->summary;
        $this->command?->info(sprintf(
            '🛍️  Catálogo listo: %d negocios con PayPal, %d verificados sin PayPal, %d emprendimientos, %d estudiantes, %d productos, %d fotos.',
            $s['paypal'], $s['verified_no_paypal'], $s['ventures'], $s['students'], $s['products'], $s['images']
        ));
    }

    // ------------------------------------------------------------------ Vendedores

    /** Merchant ID y tracking ID del comercio de Sandbox ya conectado, o null si no hay. */
    private function paypalConnection(): ?array
    {
        $source = Store::where('slug', self::PAYPAL_SOURCE_SLUG)->first();

        if ($source && $source->paypal_status === 'enabled' && filled($source->paypal_merchant_id) && filled($source->paypal_tracking_id)) {
            return ['merchant_id' => $source->paypal_merchant_id, 'tracking_id' => $source->paypal_tracking_id];
        }

        $this->command?->warn('La tienda "'.self::PAYPAL_SOURCE_SLUG.'" no tiene PayPal conectado (merchant y tracking ID): '
            .'los negocios con PayPal del catálogo quedan sin conectar. Conectala y volvé a correr este seeder.');

        return null;
    }

    private function seedBusiness(array $data, int $index, ?array $paypal): void
    {
        $owner = $this->user($data['owner'] + ['role' => 'egresado']);
        $store = $this->store($owner, $data);
        $connected = $data['paypal'] && $paypal;

        $store->forceFill([
            'verification_status' => 'approved',
            'verified_at' => $store->verified_at ?? now()->subDays(14 + $index),
            'paypal_status' => $connected ? 'enabled' : 'not_connected',
            'paypal_merchant_id' => $connected ? $paypal['merchant_id'] : null,
            'paypal_tracking_id' => $connected ? $paypal['tracking_id'] : null,
            'paypal_checked_at' => $connected ? now() : null,
        ])->save();

        $this->verification($store, $data['verification'], $index);
        $this->gateways($store, $data, $index);

        $campuses = $this->campusList($data['campuses']);
        foreach ($data['products'] as $i => $product) {
            $this->product($owner, $store, $campuses[$i % count($campuses)], $data['key'], $product);
        }
        $this->pruneProducts($owner, $data['products']);

        $this->summary[$connected ? 'paypal' : 'verified_no_paypal']++;
    }

    private function seedVenture(array $data): void
    {
        $owner = $this->user($data['owner'] + ['role' => 'estudiante']);
        $store = $this->store($owner, $data);

        // Emprendimiento: tienda con nombre y logo, sin papeles ni pagos en línea (vende con apartado).
        $store->forceFill([
            'verification_status' => 'none', 'verified_at' => null,
            'paypal_status' => 'not_connected', 'paypal_merchant_id' => null,
            'paypal_tracking_id' => null, 'paypal_checked_at' => null,
        ])->save();

        $campus = $this->campusList([$data['owner']['campus']])[0];
        foreach ($data['products'] as $product) {
            $this->product($owner, $store, $campus, $data['key'], $product);
        }
        $this->pruneProducts($owner, $data['products']);

        $this->summary['ventures']++;
    }

    private function seedStudent(array $data): void
    {
        $owner = $this->user($data['owner'] + ['role' => 'estudiante']);
        $campus = $this->campusList([$data['owner']['campus']])[0];

        foreach ($data['products'] as $product) {
            $this->product($owner, null, $campus, $owner->username, $product);
        }
        $this->pruneProducts($owner, $data['products']);

        $this->summary['students']++;
    }

    /** Si un producto se renombra o se quita del catálogo, el viejo se retira (soft delete). */
    private function pruneProducts(User $owner, array $products): void
    {
        Product::where('user_id', $owner->id)->whereNotIn('name', array_column($products, 'name'))->delete();
    }

    private function user(array $data): User
    {
        $user = User::firstOrNew(['email' => $data['email']]);

        // Sin factories (en producción no hay Faker). Contraseña aleatoria: nadie entra con estas cuentas.
        if (! $user->exists) {
            $user->password = Hash::make(Str::random(40));
        }

        $campus = isset($data['campus']) ? $this->campuses->get($data['campus']) : null;
        $user->forceFill([
            'name' => $data['name'],
            'username' => $this->uniqueUsername($data['username'], $user->id),
            'role' => $data['role'],
            'universidad_id' => $campus?->id,
            'carrera_id' => isset($data['carrera']) ? Carrera::where('nombre', $data['carrera'])->value('id') : null,
        ])->save();

        return $user;
    }

    private function uniqueUsername(string $base, ?int $ignoreId): string
    {
        $username = $base;
        $suffix = 2;
        while (User::where('username', $username)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $username = Str::limit($base, 13, '').$suffix++;
        }

        return $username;
    }

    private function store(User $owner, array $data): Store
    {
        $store = Store::where('user_id', $owner->id)->first() ?? new Store;
        $store->fill([
            'name' => $data['name'],
            'slug' => $store->slug ?? Store::uniqueSlug($data['name']),
            'description' => $data['description'],
            'category_id' => $this->categories[$data['category']] ?? null,
            'logo' => $this->copyLogo($data['key']) ?? $store->logo,
            'is_active' => true,
        ]);
        $store->user()->associate($owner);
        $store->save();

        return $store;
    }

    private function verification(Store $store, array $data, int $index): void
    {
        $record = StoreVerification::where('store_id', $store->id)->first() ?? new StoreVerification;
        $slug = $store->slug;
        $registration = $data['business_type'] === 'sociedad' ? 'Escritura de constitucion.pdf' : 'Matricula de comercio CNR 2026.pdf';

        // Los documentos son de mentira: basta con que el panel los muestre como enviados.
        $record->forceFill($data + [
            'store_id' => $store->id,
            'country' => 'SV',
            'document_path' => "verificaciones-comercios/demo-{$slug}-registro-fiscal.pdf",
            'document_original_name' => 'NIT y tarjeta de IVA - '.Str::limit($data['legal_name'], 60, '').'.pdf',
            'registration_document_path' => "verificaciones-comercios/demo-{$slug}-registro.pdf",
            'registration_document_name' => $registration,
            'id_document_path' => "verificaciones-comercios/demo-{$slug}-identidad.pdf",
            'id_document_name' => 'DUI representante legal.pdf',
            'status' => 'approved',
            'submitted_at' => now()->subDays(20 + $index),
            'reviewed_at' => now()->subDays(17 + $index),
            'reviewed_by' => 'Equipo LaU',
            'review_notes' => 'NIT, NRC, registro del negocio e identidad del representante verificados.',
        ])->save();
    }

    /** Solicitudes de pasarela: aprobada para los que cobran con PayPal, pendientes para otros. */
    private function gateways(Store $store, array $data, int $index): void
    {
        $requests = [];
        if ($data['paypal']) {
            $requests[] = [
                'gateway' => StorePaymentGateway::PAYPAL, 'gateway_name' => null, 'status' => 'approved',
                'note' => 'Queremos que los estudiantes paguen en línea antes de la entrega.',
                'requested_at' => now()->subDays(15 + $index), 'reviewed_at' => now()->subDays(13 + $index),
                'reviewed_by' => 'Equipo LaU', 'review_notes' => 'Cuenta PayPal Business conectada y habilitada para recibir pagos.',
            ];
        }
        if ($data['gateway']) {
            $requests[] = $data['gateway'] + [
                'gateway_name' => null, 'requested_at' => now()->subDays(2 + $index % 3),
                'reviewed_at' => null, 'reviewed_by' => null, 'review_notes' => null,
            ];
        }

        foreach ($requests as $request) {
            $row = StorePaymentGateway::where('store_id', $store->id)->where('gateway', $request['gateway'])->first()
                ?? new StorePaymentGateway;
            $row->forceFill($request + ['store_id' => $store->id])->save();
        }
    }

    // ------------------------------------------------------------------ Productos

    private function product(User $owner, ?Store $store, ?Universidad $campus, string $ownerKey, array $data): void
    {
        $product = Product::withTrashed()->where('user_id', $owner->id)->where('name', $data['name'])->first() ?? new Product;
        [$lat, $lng] = $this->deliveryCoordinates($campus, $ownerKey.'|'.$data['name']);

        $product->fill([
            'store_id' => $store?->id,
            'category_id' => $this->categories[$data['category']] ?? null,
            'universidad_id' => $campus?->id,
            'name' => $data['name'],
            'description' => $data['description'],
            'price' => $data['price'],
            'stock' => $data['stock'],
            'status' => 'active',
            'condition' => $data['condition'],
            'delivery_point' => $data['delivery_point'] ?? $this->deliveryPoints[crc32($data['name']) % count($this->deliveryPoints)],
            'delivery_lat' => $lat,
            'delivery_lng' => $lng,
        ]);
        $product->user_id = $owner->id;
        $product->deleted_at = null;
        $product->save();

        $product->images()->delete();
        $base = 'catalogo-'.Str::limit(Str::slug($ownerKey.' '.$data['name']), 90, '');
        foreach ($data['images'] as $position => $key) {
            if ($filename = $this->copyProductImage($key, $base.'-'.($position + 1))) {
                $product->images()->create(['filename' => $filename, 'position' => $position]);
                $this->summary['images']++;
            }
        }

        $this->summary['products']++;
    }

    /**
     * Punto de entrega estable dentro del ~60 % del radio del campus (el mismo en cada corrida:
     * el desplazamiento sale del crc32 del producto). Sin campus ubicado, sin coordenadas.
     */
    private function deliveryCoordinates(?Universidad $campus, string $seed): array
    {
        if (! CampusGeo::located($campus)) {
            return [null, null];
        }

        $hash = crc32($seed);
        $angle = deg2rad($hash % 360);
        $distance = (($hash >> 9) % 1000) / 1000 * 0.6 * ($campus->radio_entrega_m ?: CampusGeo::DEFAULT_RADIUS_M);
        $lat = $campus->latitud + ($distance * cos($angle)) / 111320;
        $lng = $campus->longitud + ($distance * sin($angle)) / (111320 * cos(deg2rad($campus->latitud)));

        return [round($lat, 7), round($lng, 7)];
    }

    /** @return list<?Universidad> Campus del catálogo que existen en esta BD (al menos uno). */
    private function campusList(array $domains): array
    {
        $found = collect($domains)->map(fn ($d) => $this->campuses->get($d))->filter()->values()->all();

        return $found ?: [$this->campuses->sortBy('id')->first()];
    }

    // ------------------------------------------------------------------ Archivos

    private function copyProductImage(string $key, string $name): ?string
    {
        foreach (['jpg', 'png', 'webp'] as $ext) {
            $source = self::DIR."/productos/{$key}.{$ext}";
            if (is_file($source)) {
                return $this->copyFile($source, public_path('marketplace'), "{$name}.{$ext}");
            }
        }
        $this->missingImages[] = $key;

        return null;
    }

    private function copyLogo(string $key): ?string
    {
        $source = self::DIR."/logos/{$key}.svg";

        return is_file($source) ? $this->copyFile($source, public_path('tiendas'), "catalogo-{$key}.svg") : null;
    }

    private function copyFile(string $source, string $directory, string $filename): string
    {
        $target = $directory.DIRECTORY_SEPARATOR.$filename;
        if (! is_file($target) || filesize($target) !== filesize($source) || md5_file($target) !== md5_file($source)) {
            File::copy($source, $target);
        }

        return $filename;
    }
}
