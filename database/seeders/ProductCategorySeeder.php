<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    /**
     * Las seis categorías que el frontend del Marketplace ya espera.
     * Idempotente a propósito: los seeders de este proyecto se relanzan a mano.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Libros',      'slug' => 'libros',      'icon' => 'book-outline',          'description' => 'Libros de texto, apuntes y material de lectura.'],
            ['name' => 'Tecnología',  'slug' => 'tecnologia',  'icon' => 'laptop-outline',        'description' => 'Laptops, tablets, accesorios y gadgets.'],
            ['name' => 'Materiales',  'slug' => 'materiales',  'icon' => 'construct-outline',     'description' => 'Papelería, instrumentos y material de laboratorio.'],
            ['name' => 'Ropa',        'slug' => 'ropa',        'icon' => 'shirt-outline',         'description' => 'Ropa, calzado y accesorios.'],
            ['name' => 'Servicios',   'slug' => 'servicios',   'icon' => 'briefcase-outline',     'description' => 'Tutorías, asesorías y servicios académicos.'],
            ['name' => 'Electrónica', 'slug' => 'electronica', 'icon' => 'hardware-chip-outline', 'description' => 'Componentes, calculadoras y equipo electrónico.'],
        ];

        foreach ($categories as $index => $category) {
            ProductCategory::updateOrCreate(
                ['slug' => $category['slug']],
                $category + ['sort_order' => $index + 1, 'is_active' => true]
            );
        }

        $this->command->info('🛍️  Categorías del Marketplace listas ('.count($categories).').');
    }
}
