<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            // Los nombres llevan tilde ("Tecnología"); el slug es la clave estable
            // para query params y deep links.
            $table->string('slug', 80)->unique();
            $table->string('description', 255)->nullable();
            // Nombre de ionicon: así se añaden categorías sin tocar el frontend.
            $table->string('icon', 40)->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        // Tener tienda es lo que convierte a un estudiante en emprendedor:
        // no hace falta una columna de rol.
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('slug', 90)->unique();
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('logo')->nullable();
            $table->string('instagram', 60)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            // nullOnDelete y no cascade: borrar una categoría no debe borrar publicaciones.
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            // Se copia del vendedor al crear para filtrar "solo mi campus" con un índice
            // plano, y para que la publicación no se mude si el vendedor cambia de universidad.
            $table->foreignId('universidad_id')->nullable()->constrained('universidades')->nullOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            // unsignedInteger es la red de seguridad: en MySQL strict, un decremento
            // por debajo de cero lanza excepción en vez de sobrevender en silencio.
            $table->unsignedInteger('stock')->default(0);
            $table->string('status', 20)->default('active');
            $table->string('condition', 20)->nullable();
            $table->string('delivery_point', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'created_at']);
            $table->index(['category_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['store_id', 'status']);
            $table->index(['universidad_id', 'status', 'created_at']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Solo el filename, como el resto de LaU; la URL la construye el accessor.
            $table->string('filename');
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'position']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid_cliente');
            $table->string('code', 16)->unique();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            // Desnormalizado: hace que "mis ventas" sea una query indexada sin joins
            // y sobrevive al borrado del producto.
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('total', 10, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->string('meeting_point', 160)->nullable();
            $table->timestamp('meeting_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            // Un reintento del cliente por mala red no puede duplicar el apartado
            // ni descontar stock dos veces.
            $table->unique(['uuid_cliente', 'seller_id']);
            $table->index(['buyer_id', 'status', 'created_at']);
            $table->index(['seller_id', 'status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            // Snapshots: el apartado debe seguir siendo legible aunque el vendedor
            // edite o borre el producto.
            $table->string('product_name', 120);
            $table->decimal('unit_price', 10, 2);
            $table->unsignedInteger('quantity');
            $table->string('image_filename')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('product_categories');
    }
};
