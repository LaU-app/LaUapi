<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ferias de comercios en un campus y anuncios dirigidos a los estudiantes de un campus. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            // Vacío = anuncio para todos; con valor = solo para estudiantes de ese campus.
            $table->foreignId('universidad_id')->nullable()->constrained('universidades')->nullOnDelete();
        });

        Schema::create('ferias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('universidad_id')->constrained('universidades')->cascadeOnDelete();
            $table->string('titulo', 120);
            $table->text('descripcion')->nullable();
            $table->string('lugar', 160)->nullable();
            $table->dateTime('inicia_en');
            $table->dateTime('termina_en');
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->foreignId('banner_id')->nullable()->constrained('banners')->nullOnDelete();
            $table->timestamps();
            $table->index(['universidad_id', 'termina_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ferias');
        Schema::table('banners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('universidad_id');
        });
    }
};
