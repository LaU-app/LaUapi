<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\StoreVerification;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Vitrina de LaU Marketplace con comercios verificados (datos ficticios).
 * No forma parte de DatabaseSeeder: se ejecuta a mano en local o en un entorno de demo.
 *
 *   php artisan db:seed --class=MarketplaceShowcaseSeeder
 *   php artisan paypal:link-store papeleria-campus-central <tracking_id>
 */
class MarketplaceShowcaseSeeder extends Seeder
{
    /** Cuenta LaU del comercio de vitrina (la usan los jurados para ver sus ventas). */
    public const MERCHANT_EMAIL = 'comercio.demo@lauapp.es';

    /** Cuenta LaU de estudiante para probar compras (la crea marketplace:demo-accounts). */
    public const STUDENT_EMAIL = 'estudiante.demo@lauapp.es';

    /** Campus de la vitrina: todo se entrega dentro de un campus (por defecto, la UES). */
    public static function demoCampusId(): ?int
    {
        return \App\Models\Universidad::where('dominio', 'ues.edu.sv')->value('id')
            ?? \App\Models\Universidad::orderBy('id')->value('id');
    }

    public function run(): void
    {
        $campusId = self::demoCampusId();
        $this->call(ProductCategorySeeder::class);
        $category = fn (string $slug) => ProductCategory::where('slug', $slug)->value('id');

        // Comercio verificado que cobra con PayPal (la conexión se confirma con paypal:link-store).
        $papeleria = $this->store(
            ['name' => 'Papelería Campus Central', 'username' => 'papeleria_campus', 'email' => MarketplaceShowcaseSeeder::MERCHANT_EMAIL],
            [
                'name' => 'Papelería Campus Central',
                'description' => 'Útiles, calculadoras y material técnico para estudiantes universitarios. Entregas en campus de lunes a sábado.',
                'category_id' => $category('materiales'),
                'instagram' => 'papeleriacampus.sv',
            ],
            [
                'legal_name' => 'Papelería Campus Central, S.A. de C.V.', 'business_type' => 'sociedad',
                'nit' => '0614-010190-101-3', 'nrc' => '123456-7', 'legal_representative' => 'Ana María López',
                'contact_email' => 'ventas@papeleriacampus.lau.test', 'contact_phone' => '+503 2222-3333',
                'address' => 'Av. Universitaria 1500, San Salvador', 'website' => 'https://instagram.com/papeleriacampus.sv',
                'offer_description' => 'Calculadoras científicas y graficadoras, cuadernos, kits de dibujo técnico, batas de laboratorio y guías de estudio para las principales universidades de San Salvador.',
            ],
            'approved',
        );

        $products = [
            ['electronica', 'Calculadora científica 417 funciones', 'Pantalla de dos líneas y 417 funciones. Ideal para Cálculo I y II, Física y Estadística; permitida en la mayoría de exámenes.', '18.50', 8, 'nuevo'],
            ['electronica', 'Calculadora graficadora con pantalla a color', 'Grafica funciones, resuelve sistemas y trae modo examen. Para Ingeniería y Cálculo avanzado.', '64.00', 3, 'nuevo'],
            ['materiales', 'Cuaderno universitario cuadriculado (100 hojas)', 'Pasta dura, hojas cuadriculadas de 7 mm. Perfecto para apuntes de Matemática y Cálculo.', '3.25', 40, 'nuevo'],
            ['materiales', 'Kit de dibujo técnico', 'Escuadras de 30°/60° y 45°, compás de precisión, transportador y portaminas 0.5 mm.', '12.90', 10, 'nuevo'],
            ['materiales', 'Bata de laboratorio manga larga', 'Algodón y poliéster, unisex. Tallas S a XL. Requerida en Química y Biología.', '14.00', 12, 'nuevo'],
            ['tecnologia', 'Memoria USB 64 GB', 'USB 3.0 de alta velocidad para tareas, presentaciones y respaldos.', '7.50', 25, 'nuevo'],
            ['libros', 'Guía de ejercicios resueltos de Cálculo I', 'Límites, derivadas e integrales con 300 ejercicios resueltos paso a paso.', '6.00', 15, 'nuevo'],
        ];

        foreach ($products as [$slug, $name, $description, $price, $stock, $condition]) {
            $product = Product::withTrashed()->firstOrNew(['store_id' => $papeleria->id, 'name' => $name]);
            $product->fill([
                'category_id' => $category($slug), 'description' => $description, 'price' => $price,
                'stock' => $stock, 'status' => 'active', 'condition' => $condition, 'delivery_point' => 'Entrada principal del campus',
                'universidad_id' => $campusId,
            ]);
            $product->user_id = $papeleria->user_id;
            $product->deleted_at = null;
            $product->save();
        }

        // Comercio en revisión: aparece en el panel de LaU para aprobar o pedir cambios.
        $this->store(
            ['name' => 'TecnoCampus SV', 'username' => 'tecnocampus_sv', 'email' => 'hola@tecnocampus.lau.test'],
            [
                'name' => 'TecnoCampus SV',
                'description' => 'Accesorios de tecnología para estudiantes: cargadores, audífonos y mouse.',
                'category_id' => $category('tecnologia'),
            ],
            [
                'legal_name' => 'Roberto Carlos Hernández', 'business_type' => 'persona_natural',
                'nit' => '0614-150395-102-8', 'nrc' => null, 'legal_representative' => 'Roberto Carlos Hernández',
                'contact_email' => 'hola@tecnocampus.lau.test', 'contact_phone' => '+503 7777-8888',
                'address' => 'Colonia Escalón, San Salvador', 'website' => null,
                'offer_description' => 'Venta de accesorios tecnológicos originales con garantía: cargadores, audífonos, mouse y hubs USB-C para estudiantes.',
            ],
            'submitted',
        );
    }

    private function store(array $owner, array $store, array $verification, string $status): Store
    {
        // Si la tienda de vitrina ya existe, se reutiliza con su dueño (idempotente).
        $existing = Store::where('slug', Str::slug($store['name']))->with('user')->first();

        // Sin factories: en producción no se instalan las dependencias de desarrollo (Faker).
        // La contraseña es aleatoria; para las cuentas de demostración se fija con marketplace:demo-accounts.
        $user = $existing?->user ?? User::firstOrNew(['email' => $owner['email']]);
        if (! $user->exists) {
            $user->forceFill([
                'name' => $owner['name'], 'username' => $owner['username'],
                'password' => Hash::make(Str::random(40)),
            ])->save();
        }

        $model = $existing ?? Store::firstOrNew(['user_id' => $user->id]);
        $model->fill($store + ['slug' => $model->slug ?? Store::uniqueSlug($store['name']), 'is_active' => true]);
        $model->user()->associate($user);
        $model->forceFill([
            'verification_status' => $status,
            'verified_at' => $status === 'approved' ? now() : null,
        ])->save();

        $record = StoreVerification::firstOrNew(['store_id' => $model->id]);
        $record->fill($verification + [
            'status' => $status,
            'submitted_at' => now()->subDays($status === 'approved' ? 6 : 1),
            'reviewed_at' => $status === 'approved' ? now()->subDays(4) : null,
            'reviewed_by' => $status === 'approved' ? 'Equipo LaU' : null,
            'review_notes' => $status === 'approved' ? 'NIT, NRC y escritura verificados.' : null,
        ]);
        $record->store()->associate($model);
        $record->save();

        return $model;
    }
}
