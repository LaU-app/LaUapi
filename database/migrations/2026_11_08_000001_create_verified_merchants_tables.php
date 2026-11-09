<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comercios verificados por LaU que cobran con PayPal, pedidos pagados en línea
 * y bitácora de decisiones de Jev (compra inteligente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // none | submitted | approved | rejected | suspended
            $table->string('verification_status', 20)->default('none')->after('is_active');
            $table->timestamp('verified_at')->nullable()->after('verification_status');
            // Vinculación con PayPal. El estado real se consulta siempre a PayPal; aquí se guarda
            // la última lectura para filtrar el catálogo sin llamar a PayPal en cada listado.
            $table->string('paypal_tracking_id', 127)->nullable()->unique()->after('verified_at');
            $table->string('paypal_merchant_id', 64)->nullable()->after('paypal_tracking_id');
            // not_connected | pending | enabled
            $table->string('paypal_status', 20)->default('not_connected')->after('paypal_merchant_id');
            $table->timestamp('paypal_checked_at')->nullable()->after('paypal_status');
            $table->index(['verification_status', 'paypal_status']);
        });

        Schema::create('store_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('legal_name', 160);
            // persona_natural | sociedad
            $table->string('business_type', 20);
            $table->string('nit', 20);
            $table->string('nrc', 20)->nullable();
            $table->string('legal_representative', 120);
            $table->string('contact_email', 160);
            $table->string('contact_phone', 30);
            $table->string('address', 255);
            $table->string('website', 255)->nullable();
            $table->text('offer_description');
            // Documento legal privado (fuera de public/): solo lo descarga el panel admin.
            $table->string('document_path')->nullable();
            $table->string('document_original_name', 160)->nullable();
            $table->string('status', 20)->default('submitted');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by', 160)->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'submitted_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // in_person (apartado, se paga al entregar) | paypal
            $table->string('payment_method', 20)->default('in_person')->after('status');
            // unpaid | paid
            $table->string('payment_status', 20)->default('unpaid')->after('payment_method');
            $table->string('paypal_order_id', 64)->nullable()->unique()->after('payment_status');
            $table->string('paypal_capture_id', 64)->nullable()->after('paypal_order_id');
            $table->timestamp('paid_at')->nullable()->after('paypal_capture_id');
        });

        Schema::create('assistant_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('query', 500);
            $table->decimal('max_budget', 10, 2)->nullable();
            // field | message | none
            $table->string('budget_source', 10)->default('none');
            $table->string('category_slug', 80)->nullable();
            // recommendation | confirm_choice | over_budget | no_match | needs_clarification | not_a_purchase
            $table->string('outcome', 30);
            $table->string('model', 40);
            // Solo decisiones y probabilidades de Jev (sin secretos ni encabezados).
            $table->json('answers');
            $table->json('candidates');
            $table->foreignId('recommended_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('selected_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('paypal_order_id', 64)->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_decisions');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['paypal_order_id']);
            $table->dropColumn(['payment_method', 'payment_status', 'paypal_order_id', 'paypal_capture_id', 'paid_at']);
        });

        Schema::dropIfExists('store_verifications');

        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex(['verification_status', 'paypal_status']);
            $table->dropUnique(['paypal_tracking_id']);
            $table->dropColumn(['verification_status', 'verified_at', 'paypal_tracking_id', 'paypal_merchant_id', 'paypal_status', 'paypal_checked_at']);
        });
    }
};
