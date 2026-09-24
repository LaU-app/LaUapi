<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('insignias', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');          // Ej: Comunidad
            $table->text('descripcion')->nullable(); // Ej: Usuario activo...
            $table->string('icono');           // Ej: fas fa-comment
            $table->string('bgicon');          // Ej: bg-yellow-100 text-yellow-600
            
            // Clave única para identificarlos fácil en el código (opcional pero recomendado)
            // Ej: 'community', 'verified'
            $table->string('slug')->unique()->nullable(); 
            
            $table->timestamps();
        });

        Schema::create('insignia_user', function (Blueprint $table) {
            $table->id();
            
            // Llaves foráneas
            $table->foreignId('insignia_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insignia_user'); // Nombre actualizado
        Schema::dropIfExists('insignias');
    }
};
