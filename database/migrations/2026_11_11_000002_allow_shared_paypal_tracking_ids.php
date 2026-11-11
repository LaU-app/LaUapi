<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El tracking_id de PayPal deja de ser único: varias tiendas de un mismo negocio (o las tiendas
 * de vitrina en Sandbox) pueden cobrar con la misma cuenta PayPal conectada. Nunca se busca una
 * tienda por tracking_id; solo se usa para consultar a PayPal el estado de la cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique(['paypal_tracking_id']);
            $table->index('paypal_tracking_id');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex(['paypal_tracking_id']);
            $table->unique('paypal_tracking_id');
        });
    }
};
