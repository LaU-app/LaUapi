<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separa los tres niveles de un vendedor en LaU Marketplace:
 *  1. Tienda (emprendimiento): nombre y logo, sin papeles. Vende con apartado.
 *  2. Negocio verificado: revisión legal con más documentos (y país, para negocios de fuera).
 *  3. Pasarelas de pago: solicitudes aparte y opcionales (PayPal hoy; otras a futuro).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_verifications', function (Blueprint $table) {
            // ISO 3166-1 alfa-2. En El Salvador se valida NIT/NRC; fuera, el identificador fiscal del país.
            $table->string('country', 2)->default('SV')->after('business_type');
            // Matrícula de comercio (CNR), escritura de constitución o licencia municipal.
            $table->string('registration_document_path')->nullable()->after('document_original_name');
            $table->string('registration_document_name', 160)->nullable()->after('registration_document_path');
            // Documento de identidad del representante legal (DUI o pasaporte).
            $table->string('id_document_path')->nullable()->after('registration_document_name');
            $table->string('id_document_name', 160)->nullable()->after('id_document_path');
        });

        Schema::create('store_payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // paypal | otra (pedido de una pasarela que LaU todavía no integra)
            $table->string('gateway', 30);
            // Solo para "otra": el nombre de la pasarela que el negocio ya usa.
            $table->string('gateway_name', 80)->nullable();
            // requested | approved | rejected
            $table->string('status', 20)->default('requested');
            $table->text('note')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by', 160)->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'requested_at']);
            $table->index(['store_id', 'gateway']);
        });

        // Los comercios que ya habían empezado con PayPal quedan con su solicitud aprobada.
        $now = now();
        DB::table('stores')
            ->where(fn ($q) => $q->whereNotNull('paypal_tracking_id')->orWhere('paypal_status', '!=', 'not_connected'))
            ->pluck('id')
            ->each(fn ($id) => DB::table('store_payment_gateways')->insert([
                'store_id' => $id, 'gateway' => 'paypal', 'status' => 'approved',
                'requested_at' => $now, 'reviewed_at' => $now, 'reviewed_by' => 'Equipo LaU',
                'created_at' => $now, 'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payment_gateways');

        Schema::table('store_verifications', function (Blueprint $table) {
            $table->dropColumn(['country', 'registration_document_path', 'registration_document_name', 'id_document_path', 'id_document_name']);
        });
    }
};
