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
        Schema::table('universidades', function (Blueprint $table) {
            // nullable() permite que el campo quede vacío (NULL)
            // after('dominio') los coloca justo después de la columna 'dominio' para mantener el orden
            
            $table->string('siglas', 20)->nullable()->after('dominio');
            
            // Le damos una longitud de 7 porque los colores HEX usan 7 caracteres (ej. #FFFFFF)
            $table->string('color_primario', 7)->nullable()->after('siglas'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('universidades', function (Blueprint $table) {
            // Si hacemos un rollback, borramos estas dos columnas
            $table->dropColumn(['siglas', 'color_primario']);
        });
    }
};
