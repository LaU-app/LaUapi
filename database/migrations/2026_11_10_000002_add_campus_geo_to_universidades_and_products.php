<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas dentro del campus: ubicación y área de entrega de cada universidad y punto de entrega
 * de cada producto. (Ferias y anuncios por campus: 2026_11_10_000003.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('universidades', function (Blueprint $table) {
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->unsignedSmallInteger('radio_entrega_m')->default(300);
            $table->string('direccion', 255)->nullable();
            $table->string('google_place_id', 255)->nullable();
            $table->timestamp('ubicado_en')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('delivery_lat', 10, 7)->nullable();
            $table->decimal('delivery_lng', 10, 7)->nullable();
            $table->index(['universidad_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['universidad_id', 'status']);
            $table->dropColumn(['delivery_lat', 'delivery_lng']);
        });
        Schema::table('universidades', function (Blueprint $table) {
            $table->dropColumn(['latitud', 'longitud', 'radio_entrega_m', 'direccion', 'google_place_id', 'ubicado_en']);
        });
    }
};
